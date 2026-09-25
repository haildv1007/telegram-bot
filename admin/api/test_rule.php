<?php
require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../../lib/LeadParser.php';

header('Content-Type: application/json; charset=utf-8');

$raw = $_POST['text'] ?? '';
if (trim($raw) === '') {
    echo json_encode(['ok'=>false, 'error'=>'Nhập text để test']);
    exit;
}

$db = get_db();
$rules = LeadParser::loadRulesFor($db, null);
$result = LeadParser::parse($raw, $rules);

$channelName = null;
if ($result['channel_id']) {
    $s = $db->prepare('SELECT name FROM ad_channels WHERE id=?');
    $s->execute([$result['channel_id']]);
    $channelName = $s->fetchColumn();
}

echo json_encode([
    'ok' => true,
    'matched' => $result['channel_id'] !== null,
    'channel_id' => $result['channel_id'],
    'channel_name' => $channelName,
    'rule_id' => $result['rule_id'],
    'fields' => $result['fields'],
], JSON_UNESCAPED_UNICODE);
