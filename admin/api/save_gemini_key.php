<?php
require_once __DIR__ . '/../auth.php';
require_login();
header('Content-Type: application/json');

$db = get_db();
$botId = (int)($_POST['bot_id'] ?? 0);
$key = trim($_POST['gemini_api_key'] ?? '');

if (!$botId) {
    echo json_encode(['ok' => false, 'error' => 'Missing bot_id']);
    exit;
}

$stmt = $db->prepare('UPDATE bots SET gemini_api_key = ? WHERE id = ?');
$stmt->execute([$key ?: null, $botId]);

echo json_encode(['ok' => true]);
