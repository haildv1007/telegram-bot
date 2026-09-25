<?php
/**
 * CLI: php tools/deletewebhook.php [bot_id]
 * Xóa webhook cho 1 bot hoặc tất cả bot active.
 */
require_once __DIR__ . '/../config.php';

$onlyBotId = isset($argv[1]) ? (int)$argv[1] : null;
$db = get_db();
$q = 'SELECT * FROM bots WHERE active = 1';
$params = [];
if ($onlyBotId) { $q .= ' AND id = ?'; $params[] = $onlyBotId; }
$stmt = $db->prepare($q);
$stmt->execute($params);
foreach ($stmt as $b) {
    $ch = curl_init('https://api.telegram.org/bot' . $b['token'] . '/deleteWebhook');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $resp = curl_exec($ch);
    curl_close($ch);
    echo "Bot #{$b['id']} {$b['name']} -> $resp\n";
}
