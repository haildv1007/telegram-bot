<?php
/**
 * webhook.php?bot=<bot_id>
 * Telegram gọi vào đây cho mỗi bot. bot_id nằm trong URL.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/LeadParser.php';
require_once __DIR__ . '/lib/Telegram.php';
require_once __DIR__ . '/lib/ChatBot.php';

// ---------- Init ----------
$logFile = __DIR__ . '/webhook_debug.log';
$log = function(string $msg) use ($logFile) {
    @file_put_contents($logFile, date('Y-m-d H:i:s') . " - $msg\n", FILE_APPEND);
};

$rawInput = file_get_contents('php://input');
$log("REQUEST: " . substr($rawInput, 0, 500));

// ---------- Bot lookup ----------
$botId = (int)($_GET['bot'] ?? 0);
if ($botId <= 0) {
    http_response_code(400);
    $log("MISSING bot id in URL");
    exit('missing bot');
}

$db = get_db();
$stmt = $db->prepare('SELECT * FROM bots WHERE id = ? AND active = 1');
$stmt->execute([$botId]);
$bot = $stmt->fetch();
if (!$bot) {
    http_response_code(404);
    $log("Bot $botId not found or inactive");
    exit('bot not found');
}

// ---------- Verify secret ----------
$secretHeader = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (!empty($bot['webhook_secret']) && $secretHeader !== $bot['webhook_secret']) {
    http_response_code(403);
    $log("Bot $botId secret mismatch");
    exit;
}

// ---------- Parse update ----------
$update = json_decode($rawInput, true);
if (!$update) { echo 'ok'; exit; }

$tg = new Telegram($bot['token']);

// Callback query (inline button)
if (isset($update['callback_query'])) {
    handle_callback($db, $tg, $update['callback_query']);
    echo 'ok'; exit;
}

$message = $update['message'] ?? ($update['channel_post'] ?? null);
if (!$message || empty($message['text'])) { echo 'ok'; exit; }

$text = $message['text'];
$chatId = (string)$message['chat']['id'];
$msgDate = $message['date'];

// ---------- Command handling ----------
$trimmed = trim($text);
if (stripos($trimmed, '/thongke') === 0) {
    handle_thongke($db, $tg, $chatId);
    echo 'ok'; exit;
}
if (stripos($trimmed, '/budget') === 0) {
    handle_budget($db, $tg, $chatId, $trimmed);
    echo 'ok'; exit;
}

// ---------- Lead handling ----------
$rules = LeadParser::loadRulesFor($db, $chatId);
$parsed = LeadParser::parse($text, $rules);

if ($parsed['channel_id']) {
    save_lead($db, $parsed, $text, $msgDate, $chatId, $botId, $log);
} elseif (looks_like_lead($text)) {
    $ins = $db->prepare('INSERT INTO unmatched_leads (bot_id, chat_id, raw_text) VALUES (?, ?, ?)');
    $ins->execute([$botId, $chatId, $text]);
    $log("Unmatched lead saved from chat $chatId");
} else {
    // Thư Ký Kim — AI chatbot
    $chatType = $message['chat']['type'] ?? 'private';
    $isPrivate = ($chatType === 'private');

    // Cho phép chatbot trong nhóm báo cáo (report_chat_id khớp) hoặc private chat
    $isReportGroup = false;
    if (!$isPrivate) {
        $rgQ = $db->prepare('SELECT COUNT(*) FROM channel_groups WHERE report_chat_id = ?');
        $rgQ->execute([$chatId]);
        $isReportGroup = (int)$rgQ->fetchColumn() > 0;
    }

    if ($isPrivate || $isReportGroup) {
        $log("ChatBot query from $chatId: " . substr($text, 0, 200));
        $cleanText = preg_replace('/@\S+/', '', $text);
        $cleanText = trim($cleanText);
        if ($cleanText !== '') {
            $chatbot = new ChatBot($db, GEMINI_API_KEY);
            $reply = $chatbot->answer($cleanText);
            $chunks = mb_str_split($reply, 4000);
            foreach ($chunks as $i => $chunk) {
                $replyTo = ($i === 0) ? $message['message_id'] : null;
                $tg->sendMessage($chatId, $chunk, null, $replyTo);
            }
            $log("ChatBot replied to $chatId (" . count($chunks) . " msg)");
        }
    }
}

echo 'ok';

// ==================================================================
// Handlers
// ==================================================================

function save_lead(PDO $db, array $parsed, string $text, int $msgDate, string $chatId, int $botId, callable $log): void {
    $f = $parsed['fields'];
    $phone = $f['phone'] ?? '';
    $cccd = $f['cccd'] ?? '';
    if ($phone === '' && $cccd === '') {
        $log("Rule matched channel {$parsed['channel_id']} but no phone/cccd - skipping insert");
        return;
    }
    $dupKey = normalize_key($phone) . '|' . normalize_key($cccd);
    $createdAt = date('Y-m-d H:i:s', $msgDate);

    try {
        $stmt = $db->prepare('
          INSERT INTO leads (channel_id, platform_campaign_id, created_at, name, phone, cccd, area, vehicle, source, ip, dup_key, raw_text)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $parsed['channel_id'],
            $parsed['platform_campaign_id'],
            $createdAt,
            $f['name'] ?? '',
            $phone,
            $cccd,
            $f['area'] ?? '',
            $f['vehicle'] ?? '',
            $f['source'] ?? '',
            $f['ip'] ?? '',
            $dupKey,
            $text,
        ]);
        $log("Lead saved to channel {$parsed['channel_id']} camp={$parsed['platform_campaign_id']} (rule {$parsed['rule_id']}) dup_key=$dupKey");
    } catch (Throwable $e) {
        $log("DB ERROR insert lead: " . $e->getMessage());
    }
}

function handle_callback(PDO $db, Telegram $tg, array $cq): void {
    $tg->answerCallback($cq['id']);
    $chatId = (string)$cq['message']['chat']['id'];
    $data = $cq['data'] ?? '';
    if (strpos($data, 'stats_') === 0) {
        $period = substr($data, 6);
        $msg = build_stats_message($db, $chatId, $period);
        $tg->sendMessage($chatId, $msg);
    }
}

function handle_thongke(PDO $db, Telegram $tg, string $chatId): void {
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📅 7 ngày', 'callback_data' => 'stats_7'],
                ['text' => '📅 14 ngày', 'callback_data' => 'stats_14'],
            ],
            [['text' => '🗓 Tháng này', 'callback_data' => 'stats_month']],
        ],
    ];
    $tg->sendMessage($chatId, '📊 Chọn khoảng thời gian:', $keyboard);
}

function handle_budget(PDO $db, Telegram $tg, string $chatId, string $text): void {
    $arg = trim(substr($text, 7));
    $parts = preg_split('/\s+/', $arg, 2);
    $budget = (float) preg_replace('/[^\d.]/', '', str_replace(',', '', $parts[0] ?? ''));
    $targetDate = parse_budget_date($parts[1] ?? '');

    if ($budget <= 0) {
        $tg->sendMessage($chatId, "Cú pháp: /budget <số tiền> [ngày]\nVD: /budget 500000  → mặc định hôm qua\n/budget 500000 28/07/2026");
        return;
    }

    $stmt = $db->prepare('SELECT id FROM ad_channels WHERE source_chat_id = ? AND active = 1 LIMIT 1');
    $stmt->execute([$chatId]);
    $channelId = (int) $stmt->fetchColumn();
    if ($channelId <= 0) $channelId = 0;

    $up = $db->prepare('
      INSERT INTO ad_budget (budget_date, channel_id, budget) VALUES (?, ?, ?)
      ON DUPLICATE KEY UPDATE budget = VALUES(budget)
    ');
    $up->execute([$targetDate, $channelId, $budget]);

    $ds = $targetDate . ' 00:00:00'; $de = $targetDate . ' 23:59:59';
    $s = $db->prepare('SELECT dup_key FROM leads WHERE created_at BETWEEN ? AND ?' . ($channelId ? ' AND channel_id = ?' : ''));
    $params = [$ds, $de]; if ($channelId) $params[] = $channelId;
    $s->execute($params);
    $keys = $s->fetchAll(PDO::FETCH_COLUMN);
    $total = count($keys);
    $unique = count(array_unique($keys));

    $cpl = $total > 0 ? round($budget / $total) : 0;
    $cplu = $unique > 0 ? round($budget / $unique) : 0;

    $msg = "💰 CHI PHÍ QUẢNG CÁO " . date('d/m/Y', strtotime($targetDate)) . "\n"
         . ($channelId ? "Channel: #$channelId\n" : "")
         . "— Ngân sách: " . number_format($budget, 0, ',', '.') . "đ\n"
         . "— Tổng lead: {$total} → CPL: " . number_format($cpl, 0, ',', '.') . "đ\n"
         . "— Lead unique: {$unique} → CPL unique: " . number_format($cplu, 0, ',', '.') . "đ";
    $tg->sendMessage($chatId, $msg);
}

// ==================================================================
// Helpers
// ==================================================================

function looks_like_lead(string $text): bool {
    if (preg_match('/(0|\+84)[0-9]{9,10}/', $text)) return true;
    if (preg_match('/\b\d{9}\b|\b\d{12}\b/', $text)) return true;
    if (stripos($text, 'LEAD') !== false) return true;
    return false;
}

function normalize_key(?string $s): string {
    $s = trim(mb_strtolower((string)$s));
    return preg_replace('/\s+/', '', $s);
}

function parse_budget_date(string $token): string {
    $token = trim(mb_strtolower($token));
    if ($token === '' || in_array($token, ['hôm qua','hom qua'])) return date('Y-m-d', strtotime('-1 day'));
    if (in_array($token, ['hôm nay','hom nay'])) return date('Y-m-d');
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $token, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    return date('Y-m-d', strtotime('-1 day'));
}

function build_stats_message(PDO $db, string $chatId, string $period): string {
    switch ($period) {
        case '7': $start = date('Y-m-d 00:00:00', strtotime('-6 days')); $label = '7 NGÀY GẦN NHẤT'; break;
        case '14': $start = date('Y-m-d 00:00:00', strtotime('-13 days')); $label = '14 NGÀY GẦN NHẤT'; break;
        case 'month': default: $start = date('Y-m-01 00:00:00'); $label = 'THÁNG ' . date('m/Y'); break;
    }
    $end = date('Y-m-d 23:59:59');

    $stmt = $db->prepare('SELECT id FROM ad_channels WHERE source_chat_id = ? AND active = 1');
    $stmt->execute([$chatId]);
    $channelIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($channelIds)) {
        $s = $db->prepare('SELECT dup_key FROM leads WHERE created_at BETWEEN ? AND ?');
        $s->execute([$start, $end]);
    } else {
        $ph = implode(',', array_fill(0, count($channelIds), '?'));
        $s = $db->prepare("SELECT dup_key FROM leads WHERE created_at BETWEEN ? AND ? AND channel_id IN ($ph)");
        $s->execute(array_merge([$start, $end], $channelIds));
    }
    $keys = $s->fetchAll(PDO::FETCH_COLUMN);
    $total = count($keys);
    $unique = count(array_unique($keys));

    $bs = date('Y-m-d', strtotime($start));
    $be = date('Y-m-d', strtotime($end));
    if (empty($channelIds)) {
        $bq = $db->prepare('SELECT COALESCE(SUM(budget),0) FROM ad_budget WHERE budget_date BETWEEN ? AND ?');
        $bq->execute([$bs, $be]);
    } else {
        $ph = implode(',', array_fill(0, count($channelIds), '?'));
        $bq = $db->prepare("SELECT COALESCE(SUM(budget),0) FROM ad_budget WHERE budget_date BETWEEN ? AND ? AND channel_id IN ($ph)");
        $bq->execute(array_merge([$bs, $be], $channelIds));
    }
    $budget = (float) $bq->fetchColumn();
    $cpl = $total > 0 ? round($budget / $total) : 0;
    $cplu = $unique > 0 ? round($budget / $unique) : 0;

    return "📊 THỐNG KÊ {$label}\n"
         . "🗓 Từ " . date('d/m/Y', strtotime($start)) . " đến " . date('d/m/Y', strtotime($end)) . "\n\n"
         . "— Tổng lead: {$total}\n"
         . "— Lead unique: {$unique}\n"
         . "— Ngân sách: " . number_format($budget, 0, ',', '.') . "đ\n"
         . "— Chi phí/lead: " . number_format($cpl, 0, ',', '.') . "đ\n"
         . "— Chi phí/lead unique: " . number_format($cplu, 0, ',', '.') . "đ";
}
