<?php
require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../../lib/Telegram.php';
require_once __DIR__ . '/../../lib/DigestBuilder.php';

header('Content-Type: application/json; charset=utf-8');

$groupId = (int)($_POST['group_id'] ?? 0);
$reportDate = $_POST['report_date'] ?? date('Y-m-d', strtotime('-1 day'));

if ($groupId <= 0) { echo json_encode(['ok'=>false, 'error'=>'Missing group_id']); exit; }

$db = get_db();
$stmt = $db->prepare('SELECT * FROM channel_groups WHERE id = ?');
$stmt->execute([$groupId]);
$group = $stmt->fetch();
if (!$group) { echo json_encode(['ok'=>false, 'error'=>'Nhóm không tồn tại']); exit; }

try {
    $msg = DigestBuilder::buildForGroup($db, $groupId, $reportDate);
    if ($msg === null) throw new RuntimeException('Nhóm chưa có channel thành viên nào');

    $botId = (int)($group['report_bot_id'] ?? 0);
    if ($botId <= 0) {
        $b = $db->query('SELECT id FROM bots WHERE is_default_reporter = 1 AND active = 1 LIMIT 1')->fetchColumn();
        $botId = (int)$b;
    }
    if ($botId <= 0) throw new RuntimeException('Nhóm chưa gán bot và không có default reporter');

    $token = $db->prepare('SELECT token FROM bots WHERE id = ?');
    $token->execute([$botId]);
    $token = $token->fetchColumn();
    if (!$token) throw new RuntimeException("Bot #$botId không có token");

    $chatId = $group['report_chat_id'];
    if (!$chatId) throw new RuntimeException('Nhóm chưa cấu hình Chat ID nhận báo cáo — vào Nhóm channel để thêm');

    $tg = new Telegram($token);
    $resp = $tg->sendMessage($chatId, $msg);
    $success = !empty($resp['ok']);
    $error = $success ? null : ($resp['description'] ?? json_encode($resp));

    $ins = $db->prepare('
      INSERT INTO digest_history (group_id, send_date, message, success, error) VALUES (?, ?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE sent_at = NOW(), message = VALUES(message), success = VALUES(success), error = VALUES(error)
    ');
    $ins->execute([$groupId, $reportDate, $msg, $success ? 1 : 0, $error]);

    echo json_encode(['ok'=>$success, 'error'=>$error, 'preview'=>$msg], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
