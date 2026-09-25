<?php
require_once __DIR__ . '/../auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$db = get_db();
$botId = (int)($_POST['bot_id'] ?? 0);
$action = $_POST['wh_action'] ?? '';

$stmt = $db->prepare('SELECT * FROM bots WHERE id = ?');
$stmt->execute([$botId]);
$bot = $stmt->fetch();
if (!$bot) { echo json_encode(['ok' => false, 'msg' => 'Bot không tồn tại']); exit; }

$apiBase = 'https://api.telegram.org/bot' . $bot['token'];

if ($action === 'info') {
    $ch = curl_init("$apiBase/getWebhookInfo");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $resp = json_decode(curl_exec($ch), true);
    curl_close($ch);
    echo json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'set') {
    $baseUrl = trim($_POST['base_url'] ?? '');
    if (!$baseUrl) { echo json_encode(['ok' => false, 'msg' => 'Thiếu base_url']); exit; }
    $baseUrl = rtrim($baseUrl, '/');
    $webhookUrl = $baseUrl . '/webhook.php?bot=' . $bot['id'];

    $ch = curl_init("$apiBase/setWebhook");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'url' => $webhookUrl,
            'secret_token' => $bot['webhook_secret'],
            'drop_pending_updates' => true,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp = json_decode(curl_exec($ch), true);
    curl_close($ch);
    $resp['webhook_url'] = $webhookUrl;
    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'delete') {
    $ch = curl_init("$apiBase/deleteWebhook?drop_pending_updates=true");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $resp = json_decode(curl_exec($ch), true);
    curl_close($ch);
    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Action không hợp lệ']);
