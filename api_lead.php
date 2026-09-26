<?php
/**
 * api_lead.php — API endpoint cho LadiPage / webhook bên ngoài POST lead trực tiếp.
 *
 * LadiPage POST raw text vào đây → parse lead → lưu DB → forward tin vào group Telegram.
 * Thay thế flow: LadiPage → bot gửi group → bot cào (bot không đọc được tin bot khác).
 *
 * POST /api_lead.php
 * Content-Type: application/json hoặc application/x-www-form-urlencoded
 *
 * Params:
 *   secret  (required) — khóa bảo mật
 *   text    (required) — nội dung lead (raw text giống tin nhắn Telegram)
 *   chat_id (optional) — chat_id group để forward tin vào (dùng source_chat_id của channel nếu không truyền)
 *   bot_id  (optional) — bot dùng để gửi tin vào group (default: bot có is_default_reporter)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/LeadParser.php';
require_once __DIR__ . '/lib/Telegram.php';

header('Content-Type: application/json; charset=utf-8');

$logFile = __DIR__ . '/webhook_debug.log';
$log = function(string $msg) use ($logFile) {
    @file_put_contents($logFile, date('Y-m-d H:i:s') . " - $msg\n", FILE_APPEND);
};

// Parse input
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
} else {
    $input = $_POST;
}

$secret = $input['secret'] ?? $_GET['secret'] ?? '';
$text = $input['text'] ?? '';
$chatId = $input['chat_id'] ?? '';
$botId = (int)($input['bot_id'] ?? 0);

// Validate secret
if ($secret !== WEBHOOK_SECRET) {
    http_response_code(403);
    $log("API_LEAD: invalid secret");
    echo json_encode(['ok' => false, 'error' => 'Invalid secret']);
    exit;
}

if (trim($text) === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing text']);
    exit;
}

$log("API_LEAD: received text=" . substr($text, 0, 200));

$db = get_db();

// Parse lead using rules (no chat_id filter — match all active rules)
$rules = LeadParser::loadRulesFor($db, $chatId ?: null);
$parsed = LeadParser::parse($text, $rules);

$result = ['ok' => true, 'matched' => false];

if ($parsed['channel_id']) {
    // Save lead
    $f = $parsed['fields'];
    $phone = $f['phone'] ?? '';
    $cccd = $f['cccd'] ?? '';

    if ($phone !== '' || $cccd !== '') {
        $dupKey = normalize_key($phone) . '|' . normalize_key($cccd);
        $createdAt = date('Y-m-d H:i:s');

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
            $log("API_LEAD: saved to channel {$parsed['channel_id']} camp={$parsed['platform_campaign_id']} dup_key=$dupKey");
            $result['matched'] = true;
            $result['channel_id'] = $parsed['channel_id'];
            $result['lead_id'] = (int)$db->lastInsertId();
        } catch (Throwable $e) {
            $log("API_LEAD: DB ERROR: " . $e->getMessage());
            $result['error'] = 'DB error';
        }
    } else {
        $log("API_LEAD: rule matched but no phone/cccd");
        $result['matched'] = false;
        $result['reason'] = 'No phone or cccd found';
    }
} else {
    // Unmatched lead
    $ins = $db->prepare('INSERT INTO unmatched_leads (bot_id, chat_id, raw_text) VALUES (?, ?, ?)');
    $ins->execute([0, $chatId ?: 'api', $text]);
    $log("API_LEAD: unmatched, saved to unmatched_leads");
    $result['reason'] = 'No rule matched';
}

// Forward to Telegram group
if ($botId <= 0) {
    $b = $db->query('SELECT id, token FROM bots WHERE is_default_reporter = 1 AND active = 1 LIMIT 1')->fetch();
} else {
    $b = $db->prepare('SELECT id, token FROM bots WHERE id = ? AND active = 1');
    $b->execute([$botId]);
    $b = $b->fetch();
}

if ($b && $b['token']) {
    // Determine chat_id to send to
    $targetChat = $chatId;
    if (!$targetChat && $parsed['channel_id']) {
        $chStmt = $db->prepare('SELECT source_chat_id FROM ad_channels WHERE id = ?');
        $chStmt->execute([$parsed['channel_id']]);
        $targetChat = $chStmt->fetchColumn();
    }

    if ($targetChat) {
        $tg = new Telegram($b['token']);
        $prefix = "📥 Thông báo dữ liệu từ LadiPage\n";
        $resp = $tg->sendMessage($targetChat, $prefix . $text);
        $result['forwarded'] = !empty($resp['ok']);
        if (!empty($resp['ok'])) {
            $log("API_LEAD: forwarded to chat $targetChat via bot #{$b['id']}");
        } else {
            $log("API_LEAD: forward FAILED to $targetChat: " . json_encode($resp));
        }
    }
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);

function normalize_key(?string $s): string {
    $s = trim(mb_strtolower((string)$s));
    return preg_replace('/\s+/', '', $s);
}
