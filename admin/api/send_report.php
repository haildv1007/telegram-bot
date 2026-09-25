<?php
require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../../lib/Telegram.php';
require_once __DIR__ . '/../../lib/ReportBuilder.php';

header('Content-Type: application/json; charset=utf-8');

$channelId = (int)($_POST['channel_id'] ?? 0);
$reportType = $_POST['report_type'] ?? 'summary';
$reportDate = $_POST['report_date'] ?? date('Y-m-d');

if ($channelId <= 0) { echo json_encode(['ok'=>false, 'error'=>'Missing channel_id']); exit; }

$db = get_db();
$stmt = $db->prepare('SELECT * FROM ad_channels WHERE id = ?');
$stmt->execute([$channelId]);
$channel = $stmt->fetch();
if (!$channel) { echo json_encode(['ok'=>false, 'error'=>'Channel không tồn tại']); exit; }

try {
    $msg = ReportBuilder::build($db, $channel, $reportType, $reportDate);

    $botId = (int)($channel['report_bot_id'] ?? 0);
    if ($botId <= 0) {
        $b = $db->query('SELECT id FROM bots WHERE is_default_reporter = 1 AND active = 1 LIMIT 1')->fetchColumn();
        $botId = (int)$b;
    }
    if ($botId <= 0) throw new RuntimeException('Không có bot gửi');

    $token = $db->prepare('SELECT token FROM bots WHERE id = ?');
    $token->execute([$botId]);
    $token = $token->fetchColumn();
    if (!$token) throw new RuntimeException("Bot #$botId không có token");

    $chatId = $channel['report_chat_id'];
    if (!$chatId) throw new RuntimeException('Channel chưa set report_chat_id');

    $tg = new Telegram($token);
    $resp = $tg->sendMessage($chatId, $msg);
    $success = !empty($resp['ok']);
    $error = $success ? null : ($resp['description'] ?? json_encode($resp));

    $ins = $db->prepare('INSERT INTO report_history (channel_id, schedule_id, report_date, report_type, message, success, error) VALUES (?, NULL, ?, "manual", ?, ?, ?)');
    $ins->execute([$channelId, $reportDate, $msg, $success ? 1 : 0, $error]);

    echo json_encode(['ok'=>$success, 'error'=>$error, 'preview'=>$msg], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
