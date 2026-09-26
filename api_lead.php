<?php
/**
 * api_lead.php — API endpoint cho LadiPage / webhook bên ngoài POST lead trực tiếp.
 *
 * Hỗ trợ 2 format:
 * 1) LadiPage API: POST từng field riêng (name, phone, email, message, ...)
 * 2) Raw text: POST text giống tin nhắn Telegram → dùng LeadParser
 *
 * POST /api_lead.php
 * Content-Type: application/json hoặc application/x-www-form-urlencoded
 *
 * Params:
 *   secret     (required) — khóa bảo mật
 *   channel_id (optional) — ID channel trong hệ thống (nếu biết)
 *   text       (optional) — nội dung raw text (dùng LeadParser)
 *   name       (optional) — tên khách hàng (LadiPage field)
 *   phone      (optional) — SĐT (LadiPage field)
 *   email      (optional) — email (LadiPage field)
 *   message    (optional) — ghi chú (LadiPage field)
 *   chat_id    (optional) — chat_id group để forward tin vào Telegram
 *   bot_id     (optional) — bot dùng để gửi tin vào group
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
$channelId = (int)($input['channel_id'] ?? 0);

// LadiPage fields
$lpName = trim($input['name'] ?? '');
$lpPhone = trim($input['phone'] ?? '');
$lpEmail = trim($input['email'] ?? '');
$lpMessage = trim($input['message'] ?? '');

// Validate secret
if ($secret !== WEBHOOK_SECRET) {
    http_response_code(403);
    $log("API_LEAD: invalid secret");
    echo json_encode(['ok' => false, 'error' => 'Invalid secret']);
    exit;
}

$db = get_db();
$result = ['ok' => true, 'matched' => false];

// Mode 1: LadiPage structured fields (name/phone present)
if ($lpPhone !== '' || $lpName !== '') {
    $log("API_LEAD: LadiPage fields - name=$lpName phone=$lpPhone email=$lpEmail");

    $phone = $lpPhone;
    $name = $lpName;

    if ($phone === '' && $name === '') {
        echo json_encode(['ok' => true, 'matched' => false, 'reason' => 'No phone or name']);
        exit;
    }

    $dupKey = normalize_key($phone) . '|';
    $createdAt = date('Y-m-d H:i:s');

    // Build raw text for storage
    $rawParts = [];
    if ($name !== '') $rawParts[] = "Tên: $name";
    if ($phone !== '') $rawParts[] = "SĐT: $phone";
    if ($lpEmail !== '') $rawParts[] = "Email: $lpEmail";
    if ($lpMessage !== '') $rawParts[] = $lpMessage;
    $rawText = implode("\n", $rawParts);

    // Determine channel_id: from param, or try to match via rules
    if ($channelId <= 0) {
        $rules = LeadParser::loadRulesFor($db, $chatId ?: null);
        $parsed = LeadParser::parse($rawText, $rules);
        $channelId = $parsed['channel_id'] ?? 0;
    }

    if ($channelId > 0) {
        try {
            $stmt = $db->prepare('
              INSERT INTO leads (channel_id, platform_campaign_id, created_at, name, phone, cccd, area, vehicle, source, ip, dup_key, raw_text)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $channelId, '', $createdAt, $name, $phone, '', '', '', 'ladipage', '', $dupKey, $rawText,
            ]);
            $log("API_LEAD: saved LadiPage lead to channel $channelId dup_key=$dupKey");
            $result['matched'] = true;
            $result['channel_id'] = $channelId;
            $result['lead_id'] = (int)$db->lastInsertId();
        } catch (Throwable $e) {
            $log("API_LEAD: DB ERROR: " . $e->getMessage());
            $result['error'] = 'DB error';
        }
    } else {
        $ins = $db->prepare('INSERT INTO unmatched_leads (bot_id, chat_id, raw_text) VALUES (?, ?, ?)');
        $ins->execute([0, $chatId ?: 'api-ladipage', $rawText]);
        $log("API_LEAD: LadiPage lead unmatched, saved to unmatched_leads");
        $result['reason'] = 'No channel matched';
    }

    // Forward to Telegram
    forwardToTelegram($db, $botId, $chatId, $channelId, $rawText, $log);

    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// Mode 2: Raw text (original flow)
if (trim($text) === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing text or phone']);
    exit;
}

$log("API_LEAD: received text=" . substr($text, 0, 200));

$rules = LeadParser::loadRulesFor($db, $chatId ?: null);
$parsed = LeadParser::parse($text, $rules);

if ($parsed['channel_id']) {
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
        $result['reason'] = 'No phone or cccd found';
    }
} else {
    $ins = $db->prepare('INSERT INTO unmatched_leads (bot_id, chat_id, raw_text) VALUES (?, ?, ?)');
    $ins->execute([0, $chatId ?: 'api', $text]);
    $log("API_LEAD: unmatched, saved to unmatched_leads");
    $result['reason'] = 'No rule matched';
}

forwardToTelegram($db, $botId, $chatId, $parsed['channel_id'] ?? 0, $text, $log);

echo json_encode($result, JSON_UNESCAPED_UNICODE);

// --- helpers ---

function forwardToTelegram(PDO $db, int $botId, string $chatId, int $channelId, string $text, callable $log): void {
    if ($botId <= 0) {
        $b = $db->query('SELECT id, token FROM bots WHERE is_default_reporter = 1 AND active = 1 LIMIT 1')->fetch();
    } else {
        $b = $db->prepare('SELECT id, token FROM bots WHERE id = ? AND active = 1');
        $b->execute([$botId]);
        $b = $b->fetch();
    }

    if (!$b || !$b['token']) return;

    $targetChat = $chatId;
    if (!$targetChat && $channelId > 0) {
        $chStmt = $db->prepare('SELECT source_chat_id FROM ad_channels WHERE id = ?');
        $chStmt->execute([$channelId]);
        $targetChat = $chStmt->fetchColumn();
    }

    if ($targetChat) {
        $tg = new Telegram($b['token']);
        $prefix = "📥 Lead từ LadiPage\n";
        $resp = $tg->sendMessage($targetChat, $prefix . $text);
        if (!empty($resp['ok'])) {
            $log("API_LEAD: forwarded to chat $targetChat via bot #{$b['id']}");
        } else {
            $log("API_LEAD: forward FAILED to $targetChat: " . json_encode($resp));
        }
    }
}

function normalize_key(?string $s): string {
    $s = trim(mb_strtolower((string)$s));
    return preg_replace('/\s+/', '', $s);
}
