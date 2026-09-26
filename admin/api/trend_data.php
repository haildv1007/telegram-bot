<?php
require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../../lib/RangeHelper.php';

header('Content-Type: application/json; charset=utf-8');

$rangeType = $_GET['range'] ?? '14';
$from = $_GET['from'] ?? null;
$to = $_GET['to'] ?? null;
[$startDate, $endDate, $rangeLabel] = RangeHelper::resolve($rangeType, $from, $to);

$db = get_db();
$startDt = $startDate . ' 00:00:00';
$endDt = $endDate . ' 23:59:59';

// Currency conversion map
require_once __DIR__ . '/../../lib/CurrencyHelper.php';
$exRate = CurrencyHelper::getRate($db);
$currencyMap = [];
foreach ($db->query('SELECT id, currency FROM ads_credentials')->fetchAll() as $_cr) {
    $currencyMap[(int)$_cr['id']] = $_cr['currency'] ?? 'VND';
}
function convertSpend(float $spend, int $credId, float $rate, array $map): float {
    return ($map[$credId] ?? 'VND') === 'USD' ? $spend * $rate : $spend;
}

// ---------- Stats tổng (leads + spend trong khoảng đã chọn) ----------
$leadKeys = $db->prepare("SELECT dup_key FROM leads WHERE created_at BETWEEN ? AND ?");
$leadKeys->execute([$startDt, $endDt]);
$leadKeys = $leadKeys->fetchAll(PDO::FETCH_COLUMN);
$leadsTotal = count($leadKeys);
$leadsUnique = count(array_unique($leadKeys));

$spendRows = $db->prepare("SELECT credential_id, SUM(spend) AS s FROM ads_spend_cache WHERE spend_date BETWEEN ? AND ? GROUP BY credential_id");
$spendRows->execute([$startDate, $endDate]);
$spendTotal = 0.0;
foreach ($spendRows as $_r) $spendTotal += convertSpend((float)$_r['s'], (int)$_r['credential_id'], $exRate, $currencyMap);

$cpl = $leadsUnique > 0 ? round($spendTotal / $leadsUnique) : 0;

// Reference: tháng này (luôn hiện kèm, không phụ thuộc filter)
$monthStart = date('Y-m-01');
$leadsMonth = (int) $db->query("SELECT COUNT(*) FROM leads WHERE created_at >= '$monthStart 00:00:00'")->fetchColumn();
$spendMonthRows = $db->query("SELECT credential_id, SUM(spend) AS s FROM ads_spend_cache WHERE spend_date >= '$monthStart' GROUP BY credential_id")->fetchAll();
$spendMonth = 0.0;
foreach ($spendMonthRows as $_r) $spendMonth += convertSpend((float)$_r['s'], (int)$_r['credential_id'], $exRate, $currencyMap);

$channelsActive = (int) $db->query("SELECT COUNT(*) FROM ad_channels WHERE active=1")->fetchColumn();
$unmatchedOpen = (int) $db->query("SELECT COUNT(*) FROM unmatched_leads WHERE resolved=0")->fetchColumn();

// ---------- Top campaign (trong khoảng đã chọn, chỉ lấy camp đã gán vào channel) ----------
$assignedCamps = [];
$credHasFilter = [];
$chRows = $db->query("
  SELECT cc.credential_id, ch.platform_campaign_id
  FROM ad_channels ch
  JOIN channel_credentials cc ON cc.channel_id = ch.id
  WHERE ch.active = 1
")->fetchAll();
foreach ($chRows as $_ch) {
    $cid = (int)$_ch['credential_id'];
    if (!empty($_ch['platform_campaign_id'])) {
        $credHasFilter[$cid] = true;
        foreach (array_filter(array_map('trim', explode(',', $_ch['platform_campaign_id']))) as $_campId) {
            $assignedCamps[$_campId] = true;
        }
    }
}

$topCampaigns = $db->query("
  SELECT s.credential_id, s.campaign_id,
    COALESCE(camp.name, s.campaign_id) AS camp_name,
    cr.platform, cr.account_label,
    SUM(s.spend) AS spend, SUM(s.impressions) AS impressions, SUM(s.clicks) AS clicks,
    SUM(s.conversions) AS conversions, SUM(s.reach) AS reach, AVG(s.frequency) AS frequency,
    MAX(s.result_label) AS result_label
  FROM ads_spend_cache s
  LEFT JOIN campaigns camp ON camp.credential_id = s.credential_id AND camp.platform_campaign_id = s.campaign_id
  LEFT JOIN ads_credentials cr ON cr.id = s.credential_id
  WHERE s.spend_date BETWEEN '$startDate' AND '$endDate'
  GROUP BY s.credential_id, s.campaign_id, camp_name, cr.platform, cr.account_label
")->fetchAll();

// Filter: chỉ giữ campaign đã gán, hoặc tất cả nếu cred không có filter
$topCampaigns = array_values(array_filter($topCampaigns, function($tc) use ($assignedCamps, $credHasFilter) {
    if (!isset($credHasFilter[(int)$tc['credential_id']])) return true;
    return isset($assignedCamps[$tc['campaign_id']]);
}));

foreach ($topCampaigns as &$tc) {
    $lp = $db->prepare("SELECT COUNT(*) t, COUNT(DISTINCT dup_key) u FROM leads WHERE platform_campaign_id = ? AND created_at BETWEEN ? AND ?");
    $lp->execute([$tc['campaign_id'], $startDt, $endDt]);
    $ld = $lp->fetch();
    $tc['leads'] = (int) $ld['t'];
    $tc['leads_unique'] = (int) $ld['u'];
    $tc['spend'] = convertSpend((float)$tc['spend'], (int)$tc['credential_id'], $exRate, $currencyMap);
    $tc['impressions'] = (int) $tc['impressions'];
    $tc['clicks'] = (int) $tc['clicks'];
    $tc['conversions'] = (int) $tc['conversions'];
    $tc['reach'] = (int) $tc['reach'];
    $tc['frequency'] = (float) $tc['frequency'];
    $tc['cpl'] = $tc['leads_unique'] > 0 ? round($tc['spend'] / $tc['leads_unique']) : 0;
    $tc['cpc'] = $tc['clicks'] > 0 ? round($tc['spend'] / $tc['clicks']) : 0;
    $tc['ctr'] = $tc['impressions'] > 0 ? ($tc['clicks'] / $tc['impressions']) * 100 : 0;
    $tc['cpa'] = $tc['conversions'] > 0 ? round($tc['spend'] / $tc['conversions']) : 0;
    $tc['cost_per_result'] = ($tc['result_label'] && $tc['conversions'] > 0) ? round($tc['spend'] / $tc['conversions']) : 0;
}
unset($tc);

// ---------- Channels overview (cho bảng "Tổng quan theo nhóm channel", theo khoảng đã chọn) ----------
function range_channel_stats(PDO $db, array $channel, string $startDt, string $endDt, string $startDate, string $endDate): array {
    $s = $db->prepare('SELECT dup_key FROM leads WHERE channel_id = ? AND created_at BETWEEN ? AND ?');
    $s->execute([$channel['id'], $startDt, $endDt]);
    $keys = $s->fetchAll(PDO::FETCH_COLUMN);

    $credQ = $db->prepare('SELECT credential_id FROM channel_credentials WHERE channel_id = ?');
    $credQ->execute([$channel['id']]);
    $credIds = array_map('intval', $credQ->fetchAll(PDO::FETCH_COLUMN));
    if (empty($credIds) && !empty($channel['credential_id'])) $credIds = [(int)$channel['credential_id']];

    $spend = 0.0;
    $conversions = 0;
    if (!empty($credIds)) {
        global $exRate, $currencyMap;
        $ph = implode(',', array_fill(0, count($credIds), '?'));
        $params = array_merge($credIds, [$startDate, $endDate]);
        $extra = '';
        if (!empty($channel['platform_campaign_id'])) {
            $campIds = array_values(array_filter(array_map('trim', explode(',', $channel['platform_campaign_id']))));
            if ($campIds) {
                $phC = implode(',', array_fill(0, count($campIds), '?'));
                $extra = " AND campaign_id IN ($phC)";
                $params = array_merge($params, $campIds);
            }
        }
        $q = $db->prepare("SELECT credential_id, SUM(spend) AS s, SUM(conversions) AS conv FROM ads_spend_cache WHERE credential_id IN ($ph) AND spend_date BETWEEN ? AND ?$extra GROUP BY credential_id");
        $q->execute($params);
        foreach ($q as $_r) { $spend += convertSpend((float)$_r['s'], (int)$_r['credential_id'], $exRate, $currencyMap); $conversions += (int)$_r['conv']; }
    }
    if ($spend <= 0) {
        $bq = $db->prepare('SELECT COALESCE(SUM(budget),0) FROM ad_budget WHERE budget_date BETWEEN ? AND ? AND channel_id = ?');
        $bq->execute([$startDate, $endDate, $channel['id']]);
        $spend = (float) $bq->fetchColumn();
    }

    $total = count($keys);
    $unique = count(array_unique($keys));
    $cpa = $conversions > 0 ? round($spend / $conversions) : 0;
    return ['leads' => $total, 'leads_unique' => $unique, 'spend' => $spend, 'cpl' => $unique > 0 ? round($spend / $unique) : 0, 'conversions' => $conversions, 'cpa' => $cpa];
}

$channels = $db->query("
  SELECT c.*, g.name AS group_name,
    (SELECT GROUP_CONCAT(TIME_FORMAT(report_time, '%H:%i') ORDER BY report_time) FROM channel_schedules s WHERE s.channel_id = c.id AND s.active=1) AS schedules,
    (SELECT GROUP_CONCAT(DISTINCT cr.platform) FROM channel_credentials cc JOIN ads_credentials cr ON cr.id=cc.credential_id WHERE cc.channel_id=c.id) AS cred_platforms
  FROM ad_channels c
  LEFT JOIN channel_groups g ON g.id = c.group_id
  ORDER BY COALESCE(c.group_id, 999999), c.id
")->fetchAll();

$channelsOverview = [];
foreach ($channels as $ch) {
    $stats = range_channel_stats($db, $ch, $startDt, $endDt, $startDate, $endDate);
    $channelsOverview[] = array_merge([
        'id' => $ch['id'], 'group_id' => $ch['group_id'], 'group_name' => $ch['group_name'],
        'name' => $ch['name'], 'active' => (bool)$ch['active'],
        'schedules' => $ch['schedules'], 'cred_platforms' => $ch['cred_platforms'],
    ], $stats);
}

// ---------- Trend series (luôn theo ngày trong khoảng, tối thiểu hiện đủ điểm để vẽ đường) ----------
$trendFrom = $startDate;
$days = [];
$cursor = new DateTime($trendFrom);
$end = new DateTime($endDate);
while ($cursor <= $end) { $days[] = $cursor->format('Y-m-d'); $cursor->modify('+1 day'); }

$leadByDay = [];
$lq = $db->prepare("SELECT DATE(created_at) d, COUNT(*) t, COUNT(DISTINCT dup_key) u FROM leads WHERE created_at BETWEEN ? AND ? GROUP BY DATE(created_at)");
$lq->execute([$startDt, $endDt]);
foreach ($lq as $r) { $leadByDay[$r['d']] = ['t'=>(int)$r['t'],'u'=>(int)$r['u']]; }

$spendByDay = [];
$sq = $db->prepare("SELECT spend_date d, credential_id, SUM(spend) s FROM ads_spend_cache WHERE spend_date BETWEEN ? AND ? GROUP BY spend_date, credential_id");
$sq->execute([$startDate, $endDate]);
foreach ($sq as $r) {
    $converted = convertSpend((float)$r['s'], (int)$r['credential_id'], $exRate, $currencyMap);
    $spendByDay[$r['d']] = ($spendByDay[$r['d']] ?? 0) + $converted;
}

$labels = []; $leadsTotalSeries = []; $spendSeries = [];
foreach ($days as $d) {
    $labels[] = date('d/m', strtotime($d));
    $leadsTotalSeries[] = $leadByDay[$d]['t'] ?? 0;
    $spendSeries[] = round($spendByDay[$d] ?? 0);
}

echo json_encode([
    'ok' => true,
    'range' => ['from' => $startDate, 'to' => $endDate, 'label' => $rangeLabel],
    'stats' => [
        'leads' => $leadsTotal, 'leads_unique' => $leadsUnique, 'spend' => $spendTotal, 'cpl' => $cpl,
        'leads_month' => $leadsMonth, 'spend_month' => $spendMonth,
        'channel_count' => $channelsActive, 'unmatched' => $unmatchedOpen,
    ],
    'top_campaigns' => $topCampaigns,
    'channels_overview' => $channelsOverview,
    'trend' => ['labels' => $labels, 'leads_total' => $leadsTotalSeries, 'spend' => $spendSeries],
], JSON_UNESCAPED_UNICODE);
