<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/RangeHelper.php';
require_once __DIR__ . '/lib/CurrencyHelper.php';

session_name('partner_session');
session_start();

$slug = $_GET['slug'] ?? '';
if ($slug === '') { http_response_code(404); echo 'Not found'; exit; }

$db = get_db();
$partner = $db->prepare('SELECT * FROM partners WHERE slug = ? AND active = 1');
$partner->execute([$slug]);
$partner = $partner->fetch();
if (!$partner) { http_response_code(404); echo 'Not found'; exit; }

$groupIds = array_map('intval', array_filter(explode(',', $partner['group_ids'])));
if (empty($groupIds)) { echo 'No groups assigned'; exit; }

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Auth check
$sessionKey = 'partner_auth_' . $partner['id'];

// Handle logout
if (isset($_GET['logout'])) {
    unset($_SESSION[$sessionKey]);
    header('Location: /partner/' . $slug);
    exit;
}

$isAuthed = ($_SESSION[$sessionKey] ?? false) === true;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['passcode'])) {
    if ($_POST['passcode'] === $partner['passcode']) {
        $_SESSION[$sessionKey] = true;
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
    $loginError = 'Mã xác thực không đúng';
}

if (!$isAuthed) {
    // Show login form
    ?>
    <!doctype html>
    <html lang="vi">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <title><?= h($partner['name']) ?> — Báo cáo</title>
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
      <style>
        :root{--bg:#0d1117;--surface-1:#161b22;--surface-2:#21262d;--border:#30363d;--text:#e6edf3;--text-dim:#8b949e;--accent:#4f8cff;--accent-hover:#6ea1ff}
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center}
        .login-card{background:var(--surface-1);border:1px solid var(--border);border-radius:12px;padding:40px;width:100%;max-width:380px;text-align:center}
        .login-card h1{font-size:20px;margin-bottom:8px}
        .login-card p{color:var(--text-dim);font-size:14px;margin-bottom:24px}
        .passcode-input{width:200px;padding:12px;font-size:24px;text-align:center;letter-spacing:8px;background:var(--surface-2);border:1px solid var(--border);border-radius:8px;color:var(--text);outline:none}
        .passcode-input:focus{border-color:var(--accent)}
        .login-btn{margin-top:16px;padding:10px 32px;background:var(--accent);color:#fff;border:none;border-radius:8px;font-size:14px;cursor:pointer;font-weight:500}
        .login-btn:hover{background:var(--accent-hover)}
        .error{color:#f85149;font-size:13px;margin-top:12px}
      </style>
    </head>
    <body>
      <div class="login-card">
        <h1><?= h($partner['name']) ?></h1>
        <p>Nhập mã 6 số để xem báo cáo</p>
        <form method="POST">
          <input type="password" name="passcode" class="passcode-input" maxlength="6" pattern="\d{6}" required autofocus>
          <br>
          <button type="submit" class="login-btn">Xem báo cáo</button>
          <?php if (!empty($loginError)): ?><div class="error"><?= h($loginError) ?></div><?php endif; ?>
        </form>
      </div>
    </body>
    </html>
    <?php
    exit;
}

// ===== DASHBOARD =====
// API endpoint for AJAX
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');

    $exRate = CurrencyHelper::getRate($db);
    $currencyMap = [];
    foreach ($db->query('SELECT id, currency FROM ads_credentials')->fetchAll() as $_cr) {
        $currencyMap[(int)$_cr['id']] = $_cr['currency'] ?? 'VND';
    }

    $rangeType = $_GET['range'] ?? '7';
    $from = $_GET['from'] ?? null;
    $to = $_GET['to'] ?? null;
    [$startDate, $endDate, $rangeLabel] = RangeHelper::resolve($rangeType, $from, $to);
    $startDt = "$startDate 00:00:00";
    $endDt = "$endDate 23:59:59";

    $groupPh = implode(',', array_fill(0, count($groupIds), '?'));

    // Channels in partner's groups
    $channels = $db->prepare("
        SELECT c.id, c.name, c.group_id, g.name AS group_name, c.platform_campaign_id, c.credential_id,
          (SELECT GROUP_CONCAT(DISTINCT cr.platform) FROM channel_credentials cc JOIN ads_credentials cr ON cr.id=cc.credential_id WHERE cc.channel_id=c.id) AS cred_platforms
        FROM ad_channels c
        LEFT JOIN channel_groups g ON g.id = c.group_id
        WHERE c.group_id IN ($groupPh) AND c.active = 1
        ORDER BY c.group_id, c.id
    ");
    $channels->execute($groupIds);
    $channels = $channels->fetchAll();
    $channelIds = array_column($channels, 'id');

    if (empty($channelIds)) {
        echo json_encode(['ok' => true, 'stats' => ['leads' => 0, 'leads_unique' => 0, 'spend' => 0, 'cpl' => 0, 'leads_month' => 0, 'spend_month' => 0, 'channel_count' => 0], 'top_campaigns' => [], 'channels_overview' => [], 'trend' => ['labels' => [], 'leads_total' => [], 'spend' => []], 'range' => ['from' => $startDate, 'to' => $endDate, 'label' => $rangeLabel], 'groups' => []]);
        exit;
    }

    $chPh = implode(',', array_fill(0, count($channelIds), '?'));

    // Leads
    $lq = $db->prepare("SELECT dup_key FROM leads WHERE channel_id IN ($chPh) AND created_at BETWEEN ? AND ?");
    $lq->execute(array_merge($channelIds, [$startDt, $endDt]));
    $leadKeys = $lq->fetchAll(PDO::FETCH_COLUMN);
    $leadsTotal = count($leadKeys);
    $leadsUnique = count(array_unique($leadKeys));

    // Spend per channel
    $totalSpend = 0.0;
    $channelsOverview = [];
    foreach ($channels as $ch) {
        $credQ = $db->prepare('SELECT credential_id FROM channel_credentials WHERE channel_id = ?');
        $credQ->execute([$ch['id']]);
        $credIds = array_map('intval', $credQ->fetchAll(PDO::FETCH_COLUMN));
        if (empty($credIds) && !empty($ch['credential_id'])) $credIds = [(int)$ch['credential_id']];

        $chSpend = 0.0;
        if (!empty($credIds)) {
            $cph = implode(',', array_fill(0, count($credIds), '?'));
            $params = array_merge($credIds, [$startDate, $endDate]);
            $extra = '';
            if (!empty($ch['platform_campaign_id'])) {
                $campIds = array_values(array_filter(array_map('trim', explode(',', $ch['platform_campaign_id']))));
                if ($campIds) {
                    $phC = implode(',', array_fill(0, count($campIds), '?'));
                    $extra = " AND campaign_id IN ($phC)";
                    $params = array_merge($params, $campIds);
                }
            }
            $sq = $db->prepare("SELECT credential_id, SUM(spend) AS s FROM ads_spend_cache WHERE credential_id IN ($cph) AND spend_date BETWEEN ? AND ?$extra GROUP BY credential_id");
            $sq->execute($params);
            foreach ($sq as $_r) {
                $converted = (($currencyMap[(int)$_r['credential_id']] ?? 'VND') === 'USD') ? (float)$_r['s'] * $exRate : (float)$_r['s'];
                $chSpend += $converted;
            }
        }

        $chLeads = $db->prepare('SELECT dup_key FROM leads WHERE channel_id = ? AND created_at BETWEEN ? AND ?');
        $chLeads->execute([$ch['id'], $startDt, $endDt]);
        $chKeys = $chLeads->fetchAll(PDO::FETCH_COLUMN);
        $chTotal = count($chKeys);
        $chUnique = count(array_unique($chKeys));

        $totalSpend += $chSpend;
        $channelsOverview[] = [
            'id' => $ch['id'], 'name' => $ch['name'], 'group_id' => $ch['group_id'], 'group_name' => $ch['group_name'],
            'cred_platforms' => $ch['cred_platforms'], 'active' => true,
            'leads' => $chTotal, 'leads_unique' => $chUnique, 'spend' => $chSpend,
            'cpl' => $chUnique > 0 ? round($chSpend / $chUnique) : 0,
        ];
    }

    $cpl = $leadsUnique > 0 ? round($totalSpend / $leadsUnique) : 0;

    // Month stats
    $monthStart = date('Y-m-01');
    $lm = $db->prepare("SELECT COUNT(*) FROM leads WHERE channel_id IN ($chPh) AND created_at >= ?");
    $lm->execute(array_merge($channelIds, ["$monthStart 00:00:00"]));
    $leadsMonth = (int)$lm->fetchColumn();

    // Top campaigns
    $credIdsAll = [];
    $assignedCamps = [];
    foreach ($channels as $ch) {
        $cq = $db->prepare('SELECT credential_id FROM channel_credentials WHERE channel_id = ?');
        $cq->execute([$ch['id']]);
        foreach ($cq->fetchAll(PDO::FETCH_COLUMN) as $cid) $credIdsAll[(int)$cid] = true;
        if (!empty($ch['platform_campaign_id'])) {
            foreach (array_filter(array_map('trim', explode(',', $ch['platform_campaign_id']))) as $pcid) {
                $assignedCamps[$pcid] = true;
            }
        }
    }

    $topCampaigns = [];
    if (!empty($credIdsAll)) {
        $crPh = implode(',', array_fill(0, count($credIdsAll), '?'));
        $crIds = array_keys($credIdsAll);
        $tcq = $db->prepare("
            SELECT s.credential_id, s.campaign_id, COALESCE(camp.name, s.campaign_id) AS camp_name,
                cr.platform, cr.account_label,
                SUM(s.spend) AS spend, SUM(s.impressions) AS impressions, SUM(s.clicks) AS clicks,
                SUM(s.conversions) AS conversions, SUM(s.reach) AS reach, AVG(s.frequency) AS frequency,
                MAX(s.result_label) AS result_label
            FROM ads_spend_cache s
            LEFT JOIN campaigns camp ON camp.credential_id = s.credential_id AND camp.platform_campaign_id = s.campaign_id
            LEFT JOIN ads_credentials cr ON cr.id = s.credential_id
            WHERE s.credential_id IN ($crPh) AND s.spend_date BETWEEN ? AND ?
            GROUP BY s.credential_id, s.campaign_id, camp_name, cr.platform, cr.account_label
        ");
        $tcq->execute(array_merge($crIds, [$startDate, $endDate]));
        $topCampaigns = $tcq->fetchAll();

        // Filter assigned campaigns only
        if (!empty($assignedCamps)) {
            $topCampaigns = array_values(array_filter($topCampaigns, fn($tc) => isset($assignedCamps[$tc['campaign_id']])));
        }

        foreach ($topCampaigns as &$tc) {
            $tc['spend'] = (($currencyMap[(int)$tc['credential_id']] ?? 'VND') === 'USD') ? (float)$tc['spend'] * $exRate : (float)$tc['spend'];
            $tc['impressions'] = (int)$tc['impressions'];
            $tc['clicks'] = (int)$tc['clicks'];
            $tc['conversions'] = (int)$tc['conversions'];
            $tc['reach'] = (int)$tc['reach'];
            $tc['frequency'] = (float)$tc['frequency'];
            $tc['cpc'] = $tc['clicks'] > 0 ? round($tc['spend'] / $tc['clicks']) : 0;
            $tc['ctr'] = $tc['impressions'] > 0 ? ($tc['clicks'] / $tc['impressions']) * 100 : 0;
            $tc['cpa'] = $tc['conversions'] > 0 ? round($tc['spend'] / $tc['conversions']) : 0;
            $tc['cost_per_result'] = $tc['conversions'] > 0 ? round($tc['spend'] / $tc['conversions']) : 0;

            $lp = $db->prepare("SELECT COUNT(*) t, COUNT(DISTINCT dup_key) u FROM leads WHERE platform_campaign_id = ? AND channel_id IN ($chPh) AND created_at BETWEEN ? AND ?");
            $lp->execute(array_merge([$tc['campaign_id']], $channelIds, [$startDt, $endDt]));
            $ld = $lp->fetch();
            $tc['leads'] = (int)$ld['t'];
            $tc['leads_unique'] = (int)$ld['u'];
            $tc['cpl'] = $tc['leads_unique'] > 0 ? round($tc['spend'] / $tc['leads_unique']) : 0;
        }
        unset($tc);
    }

    // Trend
    $days = [];
    $cursor = new DateTime($startDate);
    $end = new DateTime($endDate);
    while ($cursor <= $end) { $days[] = $cursor->format('Y-m-d'); $cursor->modify('+1 day'); }

    $leadByDay = [];
    $ldq = $db->prepare("SELECT DATE(created_at) d, COUNT(*) t FROM leads WHERE channel_id IN ($chPh) AND created_at BETWEEN ? AND ? GROUP BY DATE(created_at)");
    $ldq->execute(array_merge($channelIds, [$startDt, $endDt]));
    foreach ($ldq as $r) $leadByDay[$r['d']] = (int)$r['t'];

    $labels = []; $leadsSeries = []; $spendSeries = [];
    foreach ($days as $d) {
        $labels[] = date('d/m', strtotime($d));
        $leadsSeries[] = $leadByDay[$d] ?? 0;
        $spendSeries[] = 0;
    }

    // Groups info
    $groups = $db->prepare("SELECT id, name FROM channel_groups WHERE id IN ($groupPh) ORDER BY id");
    $groups->execute($groupIds);
    $groups = $groups->fetchAll();

    echo json_encode([
        'ok' => true,
        'range' => ['from' => $startDate, 'to' => $endDate, 'label' => $rangeLabel],
        'stats' => ['leads' => $leadsTotal, 'leads_unique' => $leadsUnique, 'spend' => $totalSpend, 'cpl' => $cpl, 'leads_month' => $leadsMonth, 'spend_month' => 0, 'channel_count' => count($channelIds)],
        'top_campaigns' => $topCampaigns,
        'channels_overview' => $channelsOverview,
        'trend' => ['labels' => $labels, 'leads_total' => $leadsSeries, 'spend' => $spendSeries],
        'groups' => $groups,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Groups for tabs
$groupPh = implode(',', array_fill(0, count($groupIds), '?'));
$groups = $db->prepare("SELECT id, name FROM channel_groups WHERE id IN ($groupPh) ORDER BY id");
$groups->execute($groupIds);
$groups = $groups->fetchAll();
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= h($partner['name']) ?> — Báo cáo quảng cáo</title>
  <link rel="stylesheet" href="/admin/assets/admin.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    .sidebar { display: none; }
    .topbar { display: none; }
    .main { margin-left: 0; padding: 16px; }
    .partner-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; padding: 8px 0; border-bottom: 1px solid var(--border); }
    .partner-header h1 { font-size: 18px; margin: 0; }
    .partner-header .logout-btn { color: var(--text-dim); text-decoration: none; font-size: 13px; }
    .partner-header .logout-btn:hover { color: var(--text); }
    .stat-grid { grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; }
    .scope-tabs { flex-wrap: wrap; gap: 6px; }
    .chart-filters { flex-wrap: wrap; gap: 6px; }
    @media (max-width: 640px) {
      .main { padding: 10px; }
      .partner-header h1 { font-size: 15px; }
      .stat-grid { grid-template-columns: repeat(2, 1fr); gap: 8px; }
      .stat-card { padding: 12px; }
      .stat-card .value { font-size: 20px; }
      .stat-card .label { font-size: 11px; }
      .table { font-size: 12px; }
      .table th, .table td { padding: 6px 8px; }
      .card { margin-bottom: 12px; }
      .scope-tab, .chart-filter-btn { font-size: 12px; padding: 6px 10px; }
      .chart-filters span { width: 100%; margin-left: 0 !important; margin-top: 6px; }
    }
  </style>
</head>
<body>

<main class="main">
  <div class="partner-header">
    <div>
      <h1><?= h($partner['name']) ?> — Báo cáo quảng cáo</h1>
      <div class="text-muted" style="font-size:13px"><?= date('d/m/Y') ?></div>
    </div>
    <a href="?slug=<?= h($slug) ?>&logout=1" class="logout-btn">Đăng xuất</a>
  </div>

  <div class="scope-tabs" id="scopeTabs">
    <button type="button" class="scope-tab active" data-scope="all" onclick="switchScope('all')">Tổng quan</button>
    <?php foreach ($groups as $g): ?>
      <button type="button" class="scope-tab" data-scope="<?= $g['id'] ?>" onclick="switchScope('<?= $g['id'] ?>')"><?= h($g['name']) ?></button>
    <?php endforeach; ?>
  </div>

  <div class="chart-filters" id="rangeFilters">
    <button type="button" class="chart-filter-btn active" data-range="today" onclick="setRange('today')">Hôm nay</button>
    <button type="button" class="chart-filter-btn" data-range="7" onclick="setRange('7')">7 ngày</button>
    <button type="button" class="chart-filter-btn" data-range="14" onclick="setRange('14')">14 ngày</button>
    <button type="button" class="chart-filter-btn" data-range="30" onclick="setRange('30')">30 ngày</button>
    <span style="display:inline-flex;align-items:center;gap:6px;margin-left:8px">
      <input type="date" id="dateFrom" class="form-control" style="padding:5px 8px;font-size:13px;width:auto">
      <span class="text-muted">→</span>
      <input type="date" id="dateTo" class="form-control" style="padding:5px 8px;font-size:13px;width:auto">
      <button type="button" class="btn btn-sm btn-primary" onclick="applyCustomRange()">Lọc</button>
    </span>
  </div>
  <div id="rangeLabel" class="text-muted mb-3" style="font-size:13px"></div>

  <div id="statCardsOut" class="stat-grid"></div>

  <div class="card">
    <div class="card-header">
      <h3 id="topCampaignTitle">Top Campaign</h3>
      <div class="d-flex gap-2">
        <button type="button" class="btn btn-sm btn-primary" id="tabSpendBtn" onclick="switchTopTab('spend')">Theo Spend</button>
        <button type="button" class="btn btn-sm" id="tabLeadsBtn" onclick="switchTopTab('leads')">Theo Leads</button>
      </div>
    </div>
    <div id="topCampaignOut" style="overflow-x:auto"></div>
  </div>

  <div class="card">
    <div class="card-header"><h3>Xu hướng theo thời gian</h3></div>
    <div class="chart-grid">
      <div>
        <div class="chart-title">Leads theo ngày</div>
        <div class="chart-box" id="chartLeadsBox"></div>
      </div>
      <div>
        <div class="chart-title">Ngân sách theo ngày (đ)</div>
        <div class="chart-box" id="chartSpendBox"></div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3>Tổng quan theo channel</h3></div>
    <div id="channelsOverviewOut" style="overflow-x:auto"></div>
  </div>
</main>

<script>
<?php
// Include the same chart/render functions from admin/index.php
?>
function renderLineChart(container, labels, values, colorVar, valueFormatter) {
  const W = 600, H = 220, padL = 40, padR = 16, padT = 12, padB = 28;
  const innerW = W - padL - padR, innerH = H - padT - padB;
  const maxVal = Math.max(1, ...values);
  const stepX = values.length > 1 ? innerW / (values.length - 1) : 0;
  const x = i => padL + (values.length > 1 ? i * stepX : innerW/2);
  const y = v => padT + innerH - (v / maxVal) * innerH;
  const color = getComputedStyle(document.documentElement).getPropertyValue(colorVar).trim() || '#4f8cff';
  let linePath = '';
  values.forEach((v, i) => { linePath += (i === 0 ? 'M' : 'L') + x(i) + ',' + y(v) + ' '; });
  const areaPath = linePath + `L${x(values.length-1)},${padT+innerH} L${x(0)},${padT+innerH} Z`;
  let gridLines = '';
  for (let g = 0; g <= 3; g++) {
    const gy = padT + (innerH / 3) * g;
    const gv = Math.round(maxVal - (maxVal / 3) * g);
    gridLines += `<line x1="${padL}" y1="${gy}" x2="${W-padR}" y2="${gy}" stroke="var(--border)" stroke-width="1"/>`;
    gridLines += `<text x="${padL-8}" y="${gy+4}" text-anchor="end" font-size="10" fill="var(--text-dim)">${formatCompact(gv)}</text>`;
  }
  container.innerHTML = `<svg viewBox="0 0 ${W} ${H}" xmlns="http://www.w3.org/2000/svg">${gridLines}<path d="${areaPath}" fill="${color}" opacity="0.1"/><path d="${linePath}" fill="none" stroke="${color}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/></svg>`;
}
function formatCompact(n) { if (n >= 1e6) return (n/1e6).toFixed(1).replace('.0','')+'M'; if (n >= 1e3) return (n/1e3).toFixed(0)+'K'; return String(n); }
function fmtNum(n) { return Number(n).toLocaleString('vi-VN'); }
function fmtMoney(n) { return Number(n).toLocaleString('vi-VN') + 'đ'; }
function escHtml(s) { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

const SLUG = '<?= h($slug) ?>';
let currentScope = 'all', currentRange = 'today', currentTopTab = 'spend', lastData = null;

let customFrom = '', customTo = '';
function setRange(r) { currentRange = r; customFrom = ''; customTo = ''; document.querySelectorAll('.chart-filter-btn').forEach(b => b.classList.toggle('active', b.dataset.range === r)); loadData(); }
function applyCustomRange() { const f = document.getElementById('dateFrom').value, t = document.getElementById('dateTo').value; if (!f || !t) return; customFrom = f; customTo = t; currentRange = 'custom'; document.querySelectorAll('.chart-filter-btn').forEach(b => b.classList.remove('active')); loadData(); }
function switchScope(s) { currentScope = s; document.querySelectorAll('.scope-tab').forEach(b => b.classList.toggle('active', b.dataset.scope === s)); loadData(); }
function switchTopTab(t) { currentTopTab = t; document.getElementById('tabSpendBtn').classList.toggle('btn-primary', t==='spend'); document.getElementById('tabLeadsBtn').classList.toggle('btn-primary', t==='leads'); if (lastData) renderTopTable(lastData.top_campaigns, t); }

async function loadData() {
  let url = `?slug=${SLUG}&api=1&range=${currentRange}`;
  if (customFrom && customTo) url += `&from=${customFrom}&to=${customTo}`;
  const r = await fetch(url, {credentials:'same-origin'});
  const d = await r.json();
  if (!d.ok) return;
  lastData = d;

  document.getElementById('rangeLabel').textContent = 'Đang xem: ' + d.range.label + ' (' + d.range.from + ' → ' + d.range.to + ')';

  // Filter by scope
  let stats = d.stats;
  let campaigns = d.top_campaigns;
  let chOverview = d.channels_overview;

  if (currentScope !== 'all') {
    const gid = parseInt(currentScope);
    chOverview = d.channels_overview.filter(c => c.group_id === gid);
    const chIds = chOverview.map(c => c.id);
    stats = {
      leads: chOverview.reduce((s,c) => s + c.leads, 0),
      leads_unique: chOverview.reduce((s,c) => s + c.leads_unique, 0),
      spend: chOverview.reduce((s,c) => s + c.spend, 0),
      channel_count: chOverview.length,
      leads_month: d.stats.leads_month,
      spend_month: 0,
    };
    stats.cpl = stats.leads_unique > 0 ? Math.round(stats.spend / stats.leads_unique) : 0;
  }

  // Stat cards
  document.getElementById('statCardsOut').innerHTML = `
    <div class="stat-card"><div class="label">Leads</div><div class="value">${fmtNum(stats.leads)}</div><div class="delta text-muted">Unique: ${fmtNum(stats.leads_unique)}</div></div>
    <div class="stat-card"><div class="label">Ngân sách</div><div class="value">${fmtMoney(stats.spend)}</div></div>
    <div class="stat-card"><div class="label">CPL</div><div class="value">${fmtMoney(stats.cpl)}</div></div>
    <div class="stat-card"><div class="label">Channels</div><div class="value">${stats.channel_count}</div></div>`;

  renderTopTable(campaigns, currentTopTab);

  // Channels overview
  let coHtml = '<table class="table"><thead><tr><th>Channel</th><th>Ads</th><th>Leads</th><th>Spend</th><th>CPL</th></tr></thead><tbody>';
  if (chOverview.length === 0) coHtml += '<tr><td colspan="5" class="text-muted" style="text-align:center">Chưa có dữ liệu</td></tr>';
  chOverview.forEach(c => {
    const pb = c.cred_platforms ? c.cred_platforms.split(',').map(p => `<span class="badge ${p==='google'?'badge-accent':'badge-warn'}">${p.substring(0,2).toUpperCase()}</span>`).join(' ') : '—';
    coHtml += `<tr><td><strong>${escHtml(c.name)}</strong></td><td>${pb}</td><td>${c.leads} <span class="muted">(u: ${c.leads_unique})</span></td><td class="mono">${fmtMoney(c.spend)}</td><td class="mono">${fmtMoney(c.cpl)}</td></tr>`;
  });
  coHtml += '</tbody></table>';
  document.getElementById('channelsOverviewOut').innerHTML = coHtml;

  // Trend charts
  const leadsBox = document.getElementById('chartLeadsBox');
  const spendBox = document.getElementById('chartSpendBox');
  if (d.trend.leads_total.every(v => v === 0)) leadsBox.innerHTML = '<div class="chart-empty">Chưa có lead.</div>';
  else renderLineChart(leadsBox, d.trend.labels, d.trend.leads_total, '--accent', v => v + ' leads');
  if (d.trend.spend.every(v => v === 0)) spendBox.innerHTML = '<div class="chart-empty">Chưa có spend.</div>';
  else renderLineChart(spendBox, d.trend.labels, d.trend.spend, '--success', v => fmtMoney(v));
}

function renderTopTable(campaigns, tab) {
  let list = campaigns.slice();
  if (tab === 'spend') { list.sort((a,b) => b.spend - a.spend); list = list.slice(0,10); }
  else { list = list.filter(c => c.leads_unique > 0); list.sort((a,b) => b.leads_unique - a.leads_unique); list = list.slice(0,10); }

  if (list.length === 0) { document.getElementById('topCampaignOut').innerHTML = '<div class="text-muted" style="text-align:center;padding:20px">Chưa có dữ liệu.</div>'; return; }

  let html = '<table class="table"><thead><tr><th>#</th><th>Campaign</th><th>TKQC</th><th>Impr</th><th>Clicks</th><th>CTR</th><th>CPC</th><th>Spend</th><th>Conv</th><th>CPA</th><th>Leads</th><th>CPL</th></tr></thead><tbody>';
  list.forEach((tc, i) => {
    const pb = `<span class="badge ${tc.platform==='google'?'badge-accent':'badge-warn'}">${(tc.platform||'').substring(0,2).toUpperCase()}</span>`;
    html += `<tr><td class="mono text-dim">${i+1}</td><td><strong>${escHtml(tc.camp_name)}</strong></td><td>${pb} <span class="muted" style="font-size:12px">${escHtml(tc.account_label||'')}</span></td><td>${fmtNum(tc.impressions)}</td><td>${fmtNum(tc.clicks)}</td><td>${Number(tc.ctr).toFixed(2).replace('.',',')}%</td><td class="mono">${fmtMoney(tc.cpc)}</td><td class="mono">${fmtMoney(tc.spend)}</td><td>${fmtNum(tc.conversions)}</td><td class="mono">${fmtMoney(tc.cpa)}</td><td>${tc.leads} <span class="muted">(u:${tc.leads_unique})</span></td><td class="mono">${fmtMoney(tc.cpl)}</td></tr>`;
  });
  html += '</tbody></table>';
  document.getElementById('topCampaignOut').innerHTML = html;
}

loadData();
</script>

</body>
</html>
