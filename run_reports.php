<?php
/**
 * run_reports.php
 * Cron chạy mỗi 5 phút. Quét channel_schedules đến giờ → gửi báo cáo.
 * Đồng thời gửi "digest tổng hợp toàn hệ thống" theo giờ cấu hình trong app_settings.
 *
 * Cron cPanel:
 *   Chạy mỗi 5 phút: php /home/user/public_html/telegram-bot/run_reports.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Telegram.php';
require_once __DIR__ . '/lib/GoogleAdsClient.php';
require_once __DIR__ . '/lib/FacebookAdsClient.php';
require_once __DIR__ . '/lib/ReportBuilder.php';
require_once __DIR__ . '/lib/DigestBuilder.php';

$logFile = __DIR__ . '/reports.log';
$log = function(string $msg) use ($logFile) {
    @file_put_contents($logFile, date('Y-m-d H:i:s') . " - $msg\n", FILE_APPEND);
    if (PHP_SAPI === 'cli') echo "$msg\n";
};

$db = get_db();
$now = new DateTime();
$today = $now->format('Y-m-d');
$yesterday = (clone $now)->modify('-1 day')->format('Y-m-d');
$windowStart = (clone $now)->modify('-5 minutes')->format('H:i:s');
$windowEnd = $now->format('H:i:s');

// ---------- Auto-update tỷ giá Binance (mỗi giờ) ----------
$hasUsd = (int)$db->query("SELECT COUNT(*) FROM ads_credentials WHERE currency='USD' AND active=1")->fetchColumn();
if ($hasUsd > 0) {
    require_once __DIR__ . '/lib/CurrencyHelper.php';
    $rateInfo = CurrencyHelper::getSettings($db);
    $lastUpdate = $rateInfo['updated_at'] ? strtotime($rateInfo['updated_at']) : 0;
    if (time() - $lastUpdate > 3600) {
        $newRate = CurrencyHelper::fetchBinanceRate();
        if ($newRate) {
            CurrencyHelper::saveRate($db, $newRate);
            $log("Updated Binance USDT/VND rate: $newRate");
        }
    }
}

// ---------- Sync spend hôm nay + hôm qua (đảm bảo data đủ cho báo cáo 7h sáng) ----------
sync_spend_for_date($db, $today, $log);
sync_spend_for_date($db, $yesterday, $log);

// ---------- Báo cáo từng channel ----------
$sql = "
  SELECT s.id AS sched_id, s.report_time, s.report_type, s.report_for,
         c.*
  FROM channel_schedules s
  JOIN ad_channels c ON c.id = s.channel_id
  WHERE s.active = 1 AND c.active = 1
    AND s.report_time > ? AND s.report_time <= ?
";
$stmt = $db->prepare($sql);
$stmt->execute([$windowStart, $windowEnd]);
$schedules = $stmt->fetchAll();

$log("Found " . count($schedules) . " schedule(s) in window $windowStart..$windowEnd");

foreach ($schedules as $sched) {
    $effectiveDate = ($sched['report_for'] === 'yesterday') ? $yesterday : $today;

    // Idempotent theo NGÀY CHẠY CRON (fire_date), không phải ngày dữ liệu
    $chk = $db->prepare('SELECT 1 FROM report_history WHERE channel_id = ? AND schedule_id = ? AND fire_date = ? LIMIT 1');
    $chk->execute([$sched['id'], $sched['sched_id'], $today]);
    if ($chk->fetchColumn()) {
        $log("SKIP channel {$sched['id']} schedule {$sched['sched_id']} - đã gửi hôm nay (fire_date=$today)");
        continue;
    }

    try {
        $msg = ReportBuilder::build($db, $sched, $sched['report_type'], $effectiveDate);

        $botId = (int)($sched['report_bot_id'] ?? 0);
        if ($botId <= 0) {
            $b = $db->query('SELECT id FROM bots WHERE is_default_reporter = 1 AND active = 1 LIMIT 1')->fetchColumn();
            $botId = (int)$b;
        }
        if ($botId <= 0) throw new RuntimeException('Không có bot gửi báo cáo (channel chưa gán và không có default reporter)');

        $bStmt = $db->prepare('SELECT token FROM bots WHERE id = ?');
        $bStmt->execute([$botId]);
        $token = $bStmt->fetchColumn();
        if (!$token) throw new RuntimeException("Bot #$botId không có token");

        $chatId = $sched['report_chat_id'];
        if (!$chatId) throw new RuntimeException('Channel chưa set report_chat_id');

        $tg = new Telegram($token);
        $resp = $tg->sendMessage($chatId, $msg);
        $success = !empty($resp['ok']);
        $error = $success ? null : ($resp['description'] ?? json_encode($resp));

        $ins = $db->prepare('INSERT INTO report_history (channel_id, schedule_id, report_date, fire_date, report_type, message, success, error) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $ins->execute([$sched['id'], $sched['sched_id'], $effectiveDate, $today, $sched['report_type'], $msg, $success ? 1 : 0, $error]);

        $log(($success ? "OK" : "FAIL") . " channel {$sched['id']} ({$sched['name']}) [{$sched['report_type']}, for=$effectiveDate] → chat $chatId" . ($error ? " - $error" : ""));
    } catch (Throwable $e) {
        $log("ERROR channel {$sched['id']}: " . $e->getMessage());
        $ins = $db->prepare('INSERT INTO report_history (channel_id, schedule_id, report_date, fire_date, report_type, message, success, error) VALUES (?, ?, ?, ?, ?, ?, 0, ?)');
        $ins->execute([$sched['id'], $sched['sched_id'], $effectiveDate, $today, $sched['report_type'], '', $e->getMessage()]);
    }
}

// ---------- Digest tổng hợp toàn hệ thống ----------
run_digest($db, $today, $yesterday, $windowStart, $windowEnd, $log);

// ==================================================================

function sync_spend_for_date(PDO $db, string $date, callable $log): void {
    $creds = $db->query('SELECT * FROM ads_credentials WHERE active = 1')->fetchAll();
    foreach ($creds as $c) {
        try {
            $chk = $db->prepare('SELECT MAX(updated_at) FROM ads_spend_cache WHERE credential_id = ? AND spend_date = ?');
            $chk->execute([$c['id'], $date]);
            $last = $chk->fetchColumn();
            if ($last && strtotime($last) > time() - 3600) continue;

            $client = $c['platform'] === 'google' ? new GoogleAdsClient($db, $c) : new FacebookAdsClient($db, $c);
            $client->syncSpend($date, $date, null);
            $log("Synced spend {$c['platform']}/#{$c['id']} for $date");
            if ($c['platform'] === 'google') {
                try { $client->syncSearchTerms($date, $date); $log("Synced search terms #{$c['id']} for $date"); }
                catch (Throwable $e2) { $log("Search terms sync FAIL #{$c['id']}: " . $e2->getMessage()); }
            }
        } catch (Throwable $e) {
            $log("Sync FAIL cred #{$c['id']} ($date): " . $e->getMessage());
        }
    }
}

function run_digest(PDO $db, string $today, string $yesterday, string $windowStart, string $windowEnd, callable $log): void {
    $groups = $db->query('SELECT * FROM channel_groups')->fetchAll();
    if (empty($groups)) {
        $log("SKIP digest - chưa có nhóm channel nào");
        return;
    }

    $dow = (int) date('N'); // 1=Mon..7=Sun
    $dom = (int) date('j'); // 1..31

    foreach ($groups as $group) {
        $groupId = (int) $group['id'];
        $schedule = $group['digest_schedule'] ?? 'off';
        if ($schedule === 'off') continue;

        // Check schedule match
        if ($schedule === 'weekly' && $dow !== (int)($group['digest_day'] ?? 1)) continue;
        if ($schedule === 'monthly' && $dom !== (int)($group['digest_day'] ?? 1)) continue;

        // Check time window
        $digestTime = substr($group['digest_time'] ?? '07:00:00', 0, 5);
        $digestTimeSec = $digestTime . ':00';
        if (!($digestTimeSec > $windowStart && $digestTimeSec <= $windowEnd)) continue;

        $chk = $db->prepare('SELECT 1 FROM digest_history WHERE group_id = ? AND send_date = ?');
        $chk->execute([$groupId, $today]);
        if ($chk->fetchColumn()) {
            $log("SKIP digest nhóm #{$groupId} - đã gửi hôm nay ($today)");
            continue;
        }

        $chatId = $group['report_chat_id'];
        if (!$chatId) {
            $log("SKIP digest nhóm #{$groupId} ({$group['name']}) - chưa cấu hình chat đích");
            continue;
        }

        // Cho monthly: báo cáo lũy kế tháng trước (ngày cuối tháng trước)
        $reportDate = ($schedule === 'monthly') ? date('Y-m-t', strtotime('last month')) : $yesterday;

        try {
            $msg = DigestBuilder::buildForGroup($db, $groupId, $reportDate);
            if ($msg === null) {
                $log("SKIP digest nhóm #{$groupId} - không có thành viên");
                continue;
            }

            $botId = (int)($group['report_bot_id'] ?? 0);
            if ($botId <= 0) {
                $b = $db->query('SELECT id FROM bots WHERE is_default_reporter = 1 AND active = 1 LIMIT 1')->fetchColumn();
                $botId = (int)$b;
            }
            if ($botId <= 0) throw new RuntimeException('Nhóm chưa gán bot và không có default reporter');

            $bStmt = $db->prepare('SELECT token FROM bots WHERE id = ?');
            $bStmt->execute([$botId]);
            $token = $bStmt->fetchColumn();
            if (!$token) throw new RuntimeException("Bot #$botId không có token");

            $tg = new Telegram($token);
            $resp = $tg->sendMessage($chatId, $msg);
            $success = !empty($resp['ok']);
            $error = $success ? null : ($resp['description'] ?? json_encode($resp));

            $ins = $db->prepare('INSERT INTO digest_history (group_id, send_date, message, success, error) VALUES (?, ?, ?, ?, ?)');
            $ins->execute([$groupId, $today, $msg, $success ? 1 : 0, $error]);

            $log(($success ? "OK" : "FAIL") . " digest nhóm #{$groupId} ({$group['name']}) → chat $chatId" . ($error ? " - $error" : ""));
        } catch (Throwable $e) {
            $log("ERROR digest nhóm #{$groupId}: " . $e->getMessage());
            $ins = $db->prepare('INSERT INTO digest_history (group_id, send_date, message, success, error) VALUES (?, ?, "", 0, ?)');
            $ins->execute([$groupId, $today, $e->getMessage()]);
        }
    }
}
