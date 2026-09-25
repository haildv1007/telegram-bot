<?php
/**
 * Backfill spend data từ Google/Facebook Ads.
 * Chạy 1 lần: php tools/backfill_spend.php [--from=2024-01-01] [--to=2026-09-25]
 * Mặc định: from = 1 năm trước, to = hôm nay.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/GoogleAdsClient.php';
require_once __DIR__ . '/../lib/FacebookAdsClient.php';

$db = get_db();

// Parse args
$from = date('Y-m-d', strtotime('-30 days'));
$to = date('Y-m-d');
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--from=')) $from = substr($arg, 7);
    if (str_starts_with($arg, '--to=')) $to = substr($arg, 5);
}

echo "Backfill spend from $from to $to\n";

$credentials = $db->query('SELECT * FROM ads_credentials WHERE active = 1')->fetchAll();
if (empty($credentials)) {
    echo "Không có credentials nào active.\n";
    exit;
}

foreach ($credentials as $c) {
    $label = $c['account_label'] ?: $c['account_id'];
    echo "\n=== #{$c['id']} {$c['platform']} — $label ===\n";

    try {
        if ($c['platform'] === 'google') {
            $client = new GoogleAdsClient($db, $c);
        } else {
            $client = new FacebookAdsClient($db, $c);
        }

        // Chia thành chunks 30 ngày để tránh timeout/quá tải
        $current = $from;
        while ($current <= $to) {
            $chunkEnd = date('Y-m-d', min(strtotime($current . ' +29 days'), strtotime($to)));
            echo "  Syncing $current → $chunkEnd ... ";
            $rows = $client->syncSpend($current, $chunkEnd, null);
            echo count($rows) . " rows\n";
            $current = date('Y-m-d', strtotime($chunkEnd . ' +1 day'));
        }

        echo "  Done.\n";
    } catch (Throwable $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
    }
}

echo "\nBackfill complete!\n";
