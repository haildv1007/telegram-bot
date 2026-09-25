<?php
/**
 * Backfill platform_campaign_id cho các lead có raw_text.
 * Chạy: php tools/backfill_camp.php [--dry]
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/LeadParser.php';

$dry = in_array('--dry', $argv);
$db = get_db();
$rows = $db->query('SELECT id, raw_text FROM leads WHERE raw_text IS NOT NULL AND raw_text != "" AND (platform_campaign_id IS NULL OR platform_campaign_id = "")')->fetchAll(PDO::FETCH_ASSOC);
$total = count($rows);
$updated = 0;
$up = $db->prepare('UPDATE leads SET platform_campaign_id = ? WHERE id = ?');
foreach ($rows as $r) {
    $cid = LeadParser::extractCampaignId($r['raw_text']);
    if ($cid) {
        if (!$dry) $up->execute([$cid, $r['id']]);
        $updated++;
    }
}
echo ($dry ? "[DRY] " : "") . "Scanned $total, found camp for $updated leads.\n";
