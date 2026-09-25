<?php
/**
 * daily_summary.php
 * Đặt file này chạy tự động mỗi ngày qua cPanel Cron Jobs (vd: 23:55 hàng ngày)
 * Nó sẽ tính thống kê lead trong ngày và gửi báo cáo về nhóm Telegram.
 *
 * Lệnh cron gợi ý (chạy qua PHP CLI, không cần webserver):
 * php /home/tenuser/public_html/telegram-bot/daily_summary.php
 */

require_once __DIR__ . '/config.php';

$pdo = get_db();

// Lấy chat_id đã lưu từ webhook.php
$stmt = $pdo->query('SELECT config_value FROM bot_config WHERE config_key = "chat_id"');
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo "Chua co chat_id - can it nhat 1 lead da vao truoc do\n";
    exit;
}
$chatId = $row['config_value'];

$todayStart = date('Y-m-d 00:00:00');
$todayEnd = date('Y-m-d 23:59:59');

// Lấy toàn bộ lead hôm nay
$stmt = $pdo->prepare('SELECT dup_key FROM leads WHERE created_at BETWEEN :start AND :end');
$stmt->execute(['start' => $todayStart, 'end' => $todayEnd]);
$todayKeys = $stmt->fetchAll(PDO::FETCH_COLUMN);

$total = count($todayKeys);
$uniqueKeysToday = array_unique($todayKeys);
$uniqueToday = count($uniqueKeysToday);

// Kiểm tra trong số lead unique hôm nay, cái nào đã từng xuất hiện trong 21 NGÀY GẦN NHẤT trước đó
// (không tính toàn bộ lịch sử, chỉ tính trong khoảng 21 ngày trở lại)
$dupWithHistory = 0;
if ($uniqueToday > 0) {
    $historyStart = date('Y-m-d 00:00:00', strtotime('-21 days', strtotime($todayStart)));
    $placeholders = implode(',', array_fill(0, count($uniqueKeysToday), '?'));
    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT dup_key) FROM leads
         WHERE created_at >= ? AND created_at < ? AND dup_key IN ($placeholders)"
    );
    $stmt->execute(array_merge([$historyStart, $todayStart], array_values($uniqueKeysToday)));
    $dupWithHistory = (int) $stmt->fetchColumn();
}

$newLeads = $uniqueToday - $dupWithHistory;

$msg = "📊 THỐNG KÊ LEAD ngày " . date('d/m/Y') . "\n"
     . "— Tổng lead nhận về: {$total}\n"
     . "— Lead trùng nhau trong ngày (cùng SĐT + CCCD): " . ($total - $uniqueToday) . "\n"
     . "— Lead unique trong ngày: {$uniqueToday}\n"
     . "— Trong đó trùng với lead trong 21 ngày trước: {$dupWithHistory}\n"
     . "— Lead HOÀN TOÀN MỚI: {$newLeads}\n\n"
     . "💡 Gõ /budget <số tiền> để nhập ngân sách quảng cáo cho ngày này và tự động tính chi phí/lead hoặc /budget <số tiền /budget 500000 dd/mm/yy để cập nhật cho ngày nhất định\n";

send_telegram_message($chatId, $msg);

function send_telegram_message($chatId, $text) {
    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/sendMessage';
    $data = ['chat_id' => $chatId, 'text' => $text];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}