<?php
require __DIR__ . '/config.php';
require __DIR__ . '/lib/GoogleAdsClient.php';

$db = get_db();
$creds = $db->query("SELECT * FROM ads_credentials WHERE active = 1 AND platform = 'google'")->fetchAll();
foreach ($creds as $c) {
    echo "Syncing search terms for cred #{$c['id']}...";
    try {
        $client = new GoogleAdsClient($db, $c);
        $rows = $client->syncSearchTerms(date('Y-m-01'), date('Y-m-d'));
        echo count($rows) . " terms synced\n";
    } catch (Throwable $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
}
