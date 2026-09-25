<?php
require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../../lib/RangeHelper.php';

header('Content-Type: application/json; charset=utf-8');

$groupId = (int)($_GET['group_id'] ?? 0);
$rangeType = $_GET['range'] ?? '14';
$from = $_GET['from'] ?? null;
$to = $_GET['to'] ?? null;
if ($groupId <= 0) { echo json_encode(['ok'=>false, 'error'=>'Missing group_id']); exit; }

$db = get_db();

require_once __DIR__ . '/../../lib/CurrencyHelper.php';
$exRate = CurrencyHelper::getRate($db);
$currencyMap = [];
foreach ($db->query('SELECT id, currency FROM ads_credentials')->fetchAll() as $_cr) {
    $currencyMap[(int)$_cr['id']] = $_cr['currency'] ?? 'VND';
}

$g = $db->prepare('SELECT * FROM channel_groups WHERE id = ?');
$g->execute([$groupId]);
$group = $g->fetch();
if (!$group) { echo json_encode(['ok'=>false, 'error'=>'Nhóm không tồn tại']); exit; }

$members = $db->prepare('SELECT * FROM ad_channels WHERE group_id = ? ORDER BY id');
$members->execute([$groupId]);
$members = $members->fetchAll();

[$startDate, $endDate, $rangeLabel] = RangeHelper::resolve($rangeType, $from, $to);
$today = date('Y-m-d');
$rangeStart = $startDate . ' 00:00:00';
$rangeEnd = $endDate . ' 23:59:59';

// ---------- Breakdown per channel > per campaign (tháng này, đầy đủ chỉ số) ----------
function campaign_rows(PDO $db, array $channel, string $start, string $end): array {
    $credQ = $db->prepare('SELECT credential_id FROM channel_credentials WHERE channel_id = ?');
    $credQ->execute([$channel['id']]);
    $credIds = array_map('intval', $credQ->fetchAll(PDO::FETCH_COLUMN));
    if (empty($credIds) && !empty($channel['credential_id'])) $credIds = [(int)$channel['credential_id']];
    if (empty($credIds)) return [];

    $ph = implode(',', array_fill(0, count($credIds), '?'));
    $params = array_merge($credIds, [substr($start,0,10), substr($end,0,10)]);
    $extra = '';
    if (!empty($channel['platform_campaign_id'])) {
        $campIds = array_values(array_filter(array_map('trim', explode(',', $channel['platform_campaign_id']))));
        if ($campIds) {
            $phC = implode(',', array_fill(0, count($campIds), '?'));
            $extra = " AND s.campaign_id IN ($phC)";
            $params = array_merge($params, $campIds);
        }
    }
    $q = $db->prepare("
      SELECT s.credential_id AS cred_id, s.campaign_id, COALESCE(c.name, s.campaign_id) AS name, cr.platform, cr.account_label,
             SUM(s.spend) AS spend, SUM(s.impressions) AS impressions, SUM(s.clicks) AS clicks,
             SUM(s.conversions) AS conversions, SUM(s.reach) AS reach, AVG(s.frequency) AS frequency,
             MAX(s.result_label) AS result_label
      FROM ads_spend_cache s
      LEFT JOIN campaigns c ON c.credential_id = s.credential_id AND c.platform_campaign_id = s.campaign_id
      LEFT JOIN ads_credentials cr ON cr.id = s.credential_id
      WHERE s.credential_id IN ($ph) AND s.spend_date BETWEEN ? AND ?$extra
      GROUP BY s.credential_id, s.campaign_id, name, cr.platform, cr.account_label
      ORDER BY spend DESC
    ");
    $q->execute($params);
    $rows = $q->fetchAll();

    global $exRate, $currencyMap;
    foreach ($rows as &$r) {
        $rawSpend = (float)$r['spend'];
        $r['spend'] = ($currencyMap[(int)$r['cred_id']] ?? 'VND') === 'USD' ? $rawSpend * $exRate : $rawSpend;
        $r['impressions'] = (int)$r['impressions'];
        $r['clicks'] = (int)$r['clicks'];
        $r['conversions'] = (int)$r['conversions'];
        $r['reach'] = (int)$r['reach'];
        $r['frequency'] = (float)$r['frequency'];
        $r['ctr'] = $r['impressions'] > 0 ? ($r['clicks'] / $r['impressions']) * 100 : 0;
        $r['cpc'] = $r['clicks'] > 0 ? round($r['spend'] / $r['clicks']) : 0;
        $r['cpa'] = $r['conversions'] > 0 ? round($r['spend'] / $r['conversions']) : 0;

        $lp = $db->prepare('SELECT dup_key FROM leads WHERE channel_id = ? AND platform_campaign_id = ? AND created_at BETWEEN ? AND ?');
        $lp->execute([$channel['id'], $r['campaign_id'], $start, $end]);
        $keys = $lp->fetchAll(PDO::FETCH_COLUMN);
        $r['leads'] = count($keys);
        $r['leads_unique'] = count(array_unique($keys));
        $r['cpl'] = $r['leads_unique'] > 0 ? round($r['spend'] / $r['leads_unique']) : 0;
    }
    unset($r);
    return $rows;
}

$channelsOut = [];
$grpSpend = 0.0; $grpKeys = [];

foreach ($members as $ch) {
    $rows = campaign_rows($db, $ch, $rangeStart, $rangeEnd);
    $chSpend = array_sum(array_column($rows, 'spend'));
    if (empty($rows)) {
        $bq = $db->prepare('SELECT COALESCE(SUM(budget),0) FROM ad_budget WHERE budget_date BETWEEN ? AND ? AND channel_id = ?');
        $bq->execute([$startDate, $endDate, $ch['id']]);
        $chSpend = (float) $bq->fetchColumn();
    }

    $s = $db->prepare('SELECT dup_key FROM leads WHERE channel_id = ? AND created_at BETWEEN ? AND ?');
    $s->execute([$ch['id'], $rangeStart, $rangeEnd]);
    $chKeys = $s->fetchAll(PDO::FETCH_COLUMN);
    $chTotal = count($chKeys);
    $chUnique = count(array_unique($chKeys));
    $chCpl = $chUnique > 0 ? round($chSpend / $chUnique) : 0;

    $channelsOut[] = [
        'id' => $ch['id'],
        'name' => $ch['name'],
        'campaigns' => $rows,
        'subtotal' => ['leads' => $chTotal, 'leads_unique' => $chUnique, 'spend' => $chSpend, 'cpl' => $chCpl],
    ];

    $grpSpend += $chSpend;
    $grpKeys = array_merge($grpKeys, $chKeys);
}

$grpTotal = count($grpKeys);
$grpUnique = count(array_unique($grpKeys));
$grpCpl = $grpUnique > 0 ? round($grpSpend / $grpUnique) : 0;

// ---------- Reference: tháng này (luôn hiện kèm, không phụ thuộc filter) ----------
$monthStart = date('Y-m-01');
$memberIdsForStats = array_column($members, 'id');
$leadsMonth = 0; $spendMonth = 0.0;
if (!empty($memberIdsForStats)) {
    $ph = implode(',', array_fill(0, count($memberIdsForStats), '?'));
    $mq = $db->prepare("SELECT COUNT(*) FROM leads WHERE channel_id IN ($ph) AND created_at >= ?");
    $mq->execute(array_merge($memberIdsForStats, ["$monthStart 00:00:00"]));
    $leadsMonth = (int) $mq->fetchColumn();

    foreach ($members as $ch) {
        $credQ = $db->prepare('SELECT credential_id FROM channel_credentials WHERE channel_id = ?');
        $credQ->execute([$ch['id']]);
        $credIds = array_map('intval', $credQ->fetchAll(PDO::FETCH_COLUMN));
        if (empty($credIds) && !empty($ch['credential_id'])) $credIds = [(int)$ch['credential_id']];
        if (empty($credIds)) continue;
        $phC = implode(',', array_fill(0, count($credIds), '?'));
        $params = array_merge($credIds, [$monthStart, $today]);
        $extra = '';
        if (!empty($ch['platform_campaign_id'])) {
            $campIds = array_values(array_filter(array_map('trim', explode(',', $ch['platform_campaign_id']))));
            if ($campIds) {
                $phCamp = implode(',', array_fill(0, count($campIds), '?'));
                $extra = " AND campaign_id IN ($phCamp)";
                $params = array_merge($params, $campIds);
            }
        }
        $sq = $db->prepare("SELECT credential_id, SUM(spend) AS s FROM ads_spend_cache WHERE credential_id IN ($phC) AND spend_date BETWEEN ? AND ?$extra GROUP BY credential_id");
        $sq->execute($params);
        foreach ($sq as $_r) {
            $v = (float)$_r['s'];
            $spendMonth += ($currencyMap[(int)$_r['credential_id']] ?? 'VND') === 'USD' ? $v * $exRate : $v;
        }
    }
}

// ---------- Flat campaign list (cho bảng Top Campaign khi xem theo nhóm) ----------
$allCampaigns = [];
foreach ($channelsOut as $cOut) {
    foreach ($cOut['campaigns'] as $camp) {
        $allCampaigns[] = $camp; // đã có đủ field: name(camp), platform, account_label, impressions, clicks, ctr, cpc, spend, conversions, reach, frequency, result_label, leads, leads_unique, cpl, cpa
    }
}
foreach ($allCampaigns as &$ac) { $ac['camp_name'] = $ac['name']; $ac['cost_per_result'] = $ac['cpa']; }
unset($ac);

// ---------- Trend series (leads + spend theo ngày, scoped riêng nhóm) ----------
$trendStart = $startDate;

$memberIds = array_column($members, 'id');
$days = [];
$cursor = new DateTime($trendStart);
$end = new DateTime($endDate);
while ($cursor <= $end) { $days[] = $cursor->format('Y-m-d'); $cursor->modify('+1 day'); }

$leadByDay = []; $spendByDay = [];
if (!empty($memberIds)) {
    $ph = implode(',', array_fill(0, count($memberIds), '?'));
    $lq = $db->prepare("SELECT DATE(created_at) d, COUNT(*) t FROM leads WHERE channel_id IN ($ph) AND created_at BETWEEN ? AND ? GROUP BY DATE(created_at)");
    $lq->execute(array_merge($memberIds, ["$trendStart 00:00:00", "$endDate 23:59:59"]));
    foreach ($lq as $r) { $leadByDay[$r['d']] = (int)$r['t']; }

    foreach ($members as $ch) {
        $credQ = $db->prepare('SELECT credential_id FROM channel_credentials WHERE channel_id = ?');
        $credQ->execute([$ch['id']]);
        $credIds = array_map('intval', $credQ->fetchAll(PDO::FETCH_COLUMN));
        if (empty($credIds) && !empty($ch['credential_id'])) $credIds = [(int)$ch['credential_id']];
        if (empty($credIds)) continue;

        $phC = implode(',', array_fill(0, count($credIds), '?'));
        $params = array_merge($credIds, [$trendStart, $endDate]);
        $extra = '';
        if (!empty($ch['platform_campaign_id'])) {
            $campIds = array_values(array_filter(array_map('trim', explode(',', $ch['platform_campaign_id']))));
            if ($campIds) {
                $phCamp = implode(',', array_fill(0, count($campIds), '?'));
                $extra = " AND campaign_id IN ($phCamp)";
                $params = array_merge($params, $campIds);
            }
        }
        $sq = $db->prepare("SELECT spend_date d, credential_id, SUM(spend) s FROM ads_spend_cache WHERE credential_id IN ($phC) AND spend_date BETWEEN ? AND ?$extra GROUP BY spend_date, credential_id");
        $sq->execute($params);
        foreach ($sq as $r) {
            $v = (float)$r['s'];
            $converted = ($currencyMap[(int)$r['credential_id']] ?? 'VND') === 'USD' ? $v * $exRate : $v;
            $spendByDay[$r['d']] = ($spendByDay[$r['d']] ?? 0) + $converted;
        }
    }
}

$labels = []; $leadsSeries = []; $spendSeries = [];
foreach ($days as $d) {
    $labels[] = date('d/m', strtotime($d));
    $leadsSeries[] = $leadByDay[$d] ?? 0;
    $spendSeries[] = round($spendByDay[$d] ?? 0);
}

echo json_encode([
    'ok' => true,
    'group' => ['id' => $group['id'], 'name' => $group['name']],
    'range' => ['from' => $startDate, 'to' => $endDate, 'label' => $rangeLabel],
    'channels' => $channelsOut,
    'total' => ['leads' => $grpTotal, 'leads_unique' => $grpUnique, 'spend' => $grpSpend, 'cpl' => $grpCpl],
    'trend' => ['labels' => $labels, 'leads' => $leadsSeries, 'spend' => $spendSeries],
    'stats' => [
        'leads' => $grpTotal, 'leads_unique' => $grpUnique, 'spend' => $grpSpend, 'cpl' => $grpCpl,
        'leads_month' => $leadsMonth, 'spend_month' => $spendMonth,
        'channel_count' => count($members),
    ],
    'all_campaigns' => $allCampaigns,
], JSON_UNESCAPED_UNICODE);
