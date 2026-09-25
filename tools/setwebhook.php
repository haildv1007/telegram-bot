<?php
/**
 * CLI: php tools/setwebhook.php <base_url> [bot_id]
 *   base_url: URL public tới thư mục project (không có trailing slash)
 *             VD:  https://xxx.ngrok-free.app  hoặc  https://domain.com/telegram-bot
 *   bot_id:   optional. Nếu không truyền, sẽ set cho TẤT CẢ bot active.
 *
 * Ví dụ:
 *   php tools/setwebhook.php https://abc.ngrok-free.app
 *   php tools/setwebhook.php https://domain.com/telegram-bot 2
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Telegram.php';

if ($argc < 2) {
    echo "Cú pháp: php tools/setwebhook.php <base_url> [bot_id]\n";
    exit(1);
}
$base = rtrim($argv[1], '/');
$onlyBotId = isset($argv[2]) ? (int)$argv[2] : null;

$db = get_db();
$q = 'SELECT * FROM bots WHERE active = 1';
$params = [];
if ($onlyBotId) { $q .= ' AND id = ?'; $params[] = $onlyBotId; }
$stmt = $db->prepare($q);
$stmt->execute($params);
$bots = $stmt->fetchAll();

if (empty($bots)) { echo "Không có bot nào active.\n"; exit(1); }

foreach ($bots as $b) {
    $url = $base . '/webhook.php?bot=' . $b['id'];
    $tg = new Telegram($b['token']);
    $r = $tg->call ?? null; // fallthrough: use generic call via reflection? use raw curl.

    $ch = curl_init('https://api.telegram.org/bot' . $b['token'] . '/setWebhook');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'url' => $url,
            'secret_token' => $b['webhook_secret'],
            'drop_pending_updates' => true,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    $ok = !empty($data['ok']);
    printf("Bot #%d %s -> %s | %s\n",
        $b['id'], $b['name'],
        $ok ? 'OK' : 'FAIL',
        $ok ? $url : ($data['description'] ?? $resp)
    );
}
