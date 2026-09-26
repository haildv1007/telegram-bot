<?php
$page = 'dashboard';
$title = 'Dashboard';
$breadcrumb = ['Tổng quan', 'Dashboard'];
require_once __DIR__ . '/auth.php';
require_login();
$db = get_db();

$groupNames = $db->query('SELECT id, name FROM channel_groups ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);

include __DIR__ . '/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Dashboard</h1>
    <div class="page-subtitle">Tổng quan tình hình lead & ngân sách — <?= date('d/m/Y') ?></div>
  </div>
</div>

<div class="scope-tabs" id="scopeTabs">
  <button type="button" class="scope-tab active" data-scope="all" onclick="switchScope('all')">Toàn hệ thống</button>
  <?php foreach ($groupNames as $gid => $gname): ?>
    <button type="button" class="scope-tab" data-scope="<?= $gid ?>" onclick="switchScope('<?= $gid ?>')"><?= h($gname) ?></button>
  <?php endforeach; ?>
</div>

<div class="chart-filters" id="rangeFilters" style="position:relative">
  <button type="button" class="chart-filter-btn" data-range="today" onclick="setRange('today')">Hôm nay</button>
  <button type="button" class="chart-filter-btn" data-range="yesterday" onclick="setRange('yesterday')">Hôm qua</button>
  <button type="button" class="chart-filter-btn active" data-range="7" onclick="setRange('7')">7 ngày</button>
  <button type="button" class="chart-filter-btn" data-range="14" onclick="setRange('14')">14 ngày</button>
  <button type="button" class="chart-filter-btn" data-range="30" onclick="setRange('30')">30 ngày</button>
  <div style="position:relative;display:inline-block">
    <button type="button" class="chart-filter-btn" id="customToggleBtn" onclick="toggleCustomDropdown()">Tùy chỉnh ▾</button>
    <div id="customDropdown" style="display:none;position:absolute;top:calc(100% + 6px);left:0;z-index:20;background:var(--surface-2);border:1px solid var(--border-strong);border-radius:8px;padding:6px;min-width:220px;box-shadow:0 8px 24px rgba(0,0,0,0.4)">
      <button type="button" class="btn" style="width:100%;justify-content:flex-start;margin-bottom:4px" onclick="pickPreset('today')">Hôm nay</button>
      <button type="button" class="btn" style="width:100%;justify-content:flex-start;margin-bottom:4px" onclick="pickPreset('week')">Tuần này</button>
      <button type="button" class="btn" style="width:100%;justify-content:flex-start;margin-bottom:8px" onclick="pickPreset('month')">Tháng này</button>
      <div style="border-top:1px solid var(--border);padding-top:8px">
        <div class="d-flex gap-2" style="align-items:center;flex-wrap:wrap">
          <input type="date" id="customFrom" class="form-control" style="width:140px">
          <span class="text-muted">→</span>
          <input type="date" id="customTo" class="form-control" style="width:140px">
        </div>
        <button type="button" class="btn btn-sm btn-primary mt-2" style="width:100%" onclick="applyCustomRange()">Áp dụng khoảng tùy chọn</button>
      </div>
    </div>
  </div>
</div>
<div id="rangeLabel" class="text-muted mb-3" style="font-size:13px"></div>

<div id="statCardsOut" class="stat-grid"></div>

<div class="card">
  <div class="card-header">
    <h3 id="topCampaignTitle">Top Campaign</h3>
    <div class="d-flex gap-2">
      <button type="button" class="btn btn-sm" id="tabSpendBtn" onclick="switchTopTab('spend')">Theo Spend</button>
      <button type="button" class="btn btn-sm" id="tabLeadsBtn" onclick="switchTopTab('leads')">Theo Leads</button>
    </div>
  </div>
  <div id="topCampaignOut" style="overflow-x:auto"></div>
</div>

<div class="card">
  <div class="card-header">
    <h3 id="trendTitle">Xu hướng theo thời gian</h3>
  </div>
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

<div class="card" id="groupBreakdownCard" style="display:none">
  <div class="card-header"><h3 id="groupBreakdownTitle">Chi tiết theo channel</h3></div>
  <div id="groupChannelsOut"></div>
  <div id="groupTotalOut"></div>
</div>

<div class="card">
  <div class="card-header">
    <h3 id="overviewTitle">Tổng quan theo nhóm channel</h3>
    <a href="channels.php" class="btn btn-sm">Quản lý channels</a>
  </div>
  <div id="channelsOverviewOut" style="overflow-x:auto"></div>
</div>

<script>
// ==== Lightweight SVG line chart (single-series, 2px line, 10% area fill, crosshair+tooltip) ====
function renderLineChart(container, labels, values, colorVar, valueFormatter) {
  const W = 600, H = 220, padL = 40, padR = 16, padT = 12, padB = 28;
  const innerW = W - padL - padR, innerH = H - padT - padB;
  const maxVal = Math.max(1, ...values);
  const stepX = values.length > 1 ? innerW / (values.length - 1) : 0;

  const x = i => padL + (values.length > 1 ? i * stepX : innerW/2);
  const y = v => padT + innerH - (v / maxVal) * innerH;

  const color = getComputedStyle(document.documentElement).getPropertyValue(colorVar).trim() || '#4f8cff';

  let linePath = '';
  values.forEach((v, i) => {
    const px = x(i), py = y(v);
    linePath += (i === 0 ? 'M' : 'L') + px + ',' + py + ' ';
  });
  const areaPath = linePath + `L${x(values.length-1)},${padT+innerH} L${x(0)},${padT+innerH} Z`;

  let gridLines = '';
  for (let g = 0; g <= 3; g++) {
    const gy = padT + (innerH / 3) * g;
    const gv = Math.round(maxVal - (maxVal / 3) * g);
    gridLines += `<line x1="${padL}" y1="${gy}" x2="${W-padR}" y2="${gy}" stroke="var(--border)" stroke-width="1"/>`;
    gridLines += `<text x="${padL-8}" y="${gy+4}" text-anchor="end" font-size="10" fill="var(--text-dim)">${formatCompact(gv)}</text>`;
  }

  let xLabels = '';
  const labelIdxs = values.length <= 7 ? labels.map((_, i) => i) : [0, Math.floor((values.length-1)/2), values.length-1];
  labelIdxs.forEach(i => {
    xLabels += `<text x="${x(i)}" y="${H-8}" text-anchor="middle" font-size="10" fill="var(--text-dim)">${labels[i]}</text>`;
  });

  const lastX = x(values.length-1), lastY = y(values[values.length-1]);

  container.innerHTML = `
    <svg viewBox="0 0 ${W} ${H}" xmlns="http://www.w3.org/2000/svg">
      ${gridLines}
      <path d="${areaPath}" fill="${color}" opacity="0.1"/>
      <path d="${linePath}" fill="none" stroke="${color}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
      <circle cx="${lastX}" cy="${lastY}" r="4" fill="${color}" stroke="var(--surface-1)" stroke-width="2"/>
      ${xLabels}
      <line class="crosshair" x1="0" y1="${padT}" x2="0" y2="${padT+innerH}" stroke="var(--text-dim)" stroke-width="1" opacity="0" />
      <rect class="hit-area" x="${padL}" y="${padT}" width="${innerW}" height="${innerH}" fill="transparent" style="cursor:crosshair"/>
    </svg>
    <div class="chart-tooltip"></div>
  `;

  const svgEl = container.querySelector('svg');
  const crosshair = container.querySelector('.crosshair');
  const hitArea = container.querySelector('.hit-area');
  const tooltip = container.querySelector('.chart-tooltip');

  hitArea.addEventListener('mousemove', (e) => {
    const rect = svgEl.getBoundingClientRect();
    const scaleX = W / rect.width;
    const mx = (e.clientX - rect.left) * scaleX;
    let idx = values.length > 1 ? Math.round((mx - padL) / stepX) : 0;
    idx = Math.max(0, Math.min(values.length - 1, idx));
    const px = x(idx);
    crosshair.setAttribute('x1', px);
    crosshair.setAttribute('x2', px);
    crosshair.setAttribute('opacity', '1');

    const scaleXPage = rect.width / W;
    const scaleYPage = rect.height / H;
    tooltip.style.left = (px * scaleXPage) + 'px';
    tooltip.style.top = (y(values[idx]) * scaleYPage) + 'px';
    tooltip.innerHTML = `<div class="tt-date">${labels[idx]}</div><div class="tt-value"><span class="tt-key" style="background:${color}"></span>${valueFormatter(values[idx])}</div>`;
    tooltip.classList.add('visible');
  });
  hitArea.addEventListener('mouseleave', () => {
    crosshair.setAttribute('opacity', '0');
    tooltip.classList.remove('visible');
  });
}
function formatCompact(n) {
  if (n >= 1000000) return (n/1000000).toFixed(1).replace('.0','') + 'M';
  if (n >= 1000) return (n/1000).toFixed(0) + 'K';
  return String(n);
}
function fmtNum(n) { return Number(n).toLocaleString('vi-VN'); }
function fmtMoney(n) { return Number(n).toLocaleString('vi-VN') + 'đ'; }
function escapeHtmlG(s) { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
</script>

<script>
let currentScope = 'all';
let currentRangeType = '7';
let currentFrom = null, currentTo = null;
let currentTopTab = 'spend';
let lastAllData = null, lastGroupData = null;

function setRange(type) {
  currentRangeType = type;
  currentFrom = null; currentTo = null;
  updateRangeButtonStates();
  closeCustomDropdown();
  reloadCurrentScope();
}
function pickPreset(type) {
  // Preset trong dropdown (Hôm nay/Tuần này/Tháng này) — set trực tiếp, không cần chọn ngày
  currentRangeType = type;
  currentFrom = null; currentTo = null;
  updateRangeButtonStates();
  closeCustomDropdown();
  reloadCurrentScope();
}
function applyCustomRange() {
  currentFrom = document.getElementById('customFrom').value;
  currentTo = document.getElementById('customTo').value;
  if (!currentFrom || !currentTo) { alert('Chọn đủ 2 ngày'); return; }
  currentRangeType = 'custom';
  updateRangeButtonStates();
  closeCustomDropdown();
  reloadCurrentScope();
}
function updateRangeButtonStates() {
  document.querySelectorAll('#rangeFilters > .chart-filter-btn').forEach(b => b.classList.toggle('active', b.dataset.range === currentRangeType));
  const isCustomFamily = ['week','month','custom'].includes(currentRangeType);
  document.getElementById('customToggleBtn').classList.toggle('active', isCustomFamily);
}
function toggleCustomDropdown() {
  const dd = document.getElementById('customDropdown');
  dd.style.display = dd.style.display === 'none' ? 'block' : 'none';
}
function closeCustomDropdown() {
  document.getElementById('customDropdown').style.display = 'none';
}
document.addEventListener('click', (e) => {
  const wrap = document.getElementById('customToggleBtn')?.parentElement;
  if (wrap && !wrap.contains(e.target)) closeCustomDropdown();
});
function buildRangeQuery() {
  let q = 'range=' + currentRangeType;
  if (currentRangeType === 'custom' && currentFrom && currentTo) q += '&from=' + currentFrom + '&to=' + currentTo;
  return q;
}

async function switchScope(scope) {
  currentScope = scope;
  document.querySelectorAll('.scope-tab').forEach(b => b.classList.toggle('active', b.dataset.scope === scope));
  await reloadCurrentScope();
}

async function reloadCurrentScope() {
  if (currentScope === 'all') {
    const r = await fetch('api/trend_data.php?' + buildRangeQuery(), { credentials: 'same-origin' });
    const d = await r.json();
    if (!d.ok) return;
    lastAllData = d;
    renderAllScope(d);
  } else {
    const r = await fetch('api/group_detail.php?group_id=' + currentScope + '&' + buildRangeQuery(), { credentials: 'same-origin' });
    const d = await r.json();
    if (!d.ok) { document.getElementById('topCampaignOut').innerHTML = '<div class="alert alert-danger">' + escapeHtmlG(d.error) + '</div>'; return; }
    lastGroupData = d;
    renderGroupScope(d);
  }
}

function renderStatCards(stats, showUnmatched) {
  let html = `
    <div class="stat-card">
      <div class="label">Leads (khoảng đã chọn)</div>
      <div class="value">${fmtNum(stats.leads)}</div>
      <div class="delta text-muted">Unique: ${fmtNum(stats.leads_unique)} · Tháng: ${fmtNum(stats.leads_month)}</div>
    </div>
    <div class="stat-card">
      <div class="label">Ngân sách (khoảng đã chọn)</div>
      <div class="value">${fmtMoney(stats.spend)}</div>
      <div class="delta text-muted">Tháng: ${fmtMoney(stats.spend_month)}</div>
    </div>
    <div class="stat-card">
      <div class="label">CPL (khoảng đã chọn)</div>
      <div class="value">${fmtMoney(stats.cpl)}</div>
    </div>
    <div class="stat-card">
      <div class="label">Channel${showUnmatched ? ' hoạt động' : ' trong nhóm'}</div>
      <div class="value">${stats.channel_count}</div>
    </div>`;
  if (showUnmatched) {
    html += `
    <div class="stat-card">
      <div class="label">Lead chưa map</div>
      <div class="value" style="color:${stats.unmatched > 0 ? 'var(--warn)' : 'inherit'}">${stats.unmatched}</div>
      <div class="delta text-muted">${stats.unmatched > 0 ? 'Cần tạo rule' : 'Tất cả đã map'}</div>
    </div>`;
  }
  document.getElementById('statCardsOut').innerHTML = html;
}

function renderTopCampaignTable(campaigns, tab) {
  let list = campaigns.slice();
  if (tab === 'spend') {
    list.sort((a,b) => b.spend - a.spend);
    list = list.slice(0, 10);
  } else {
    list = list.filter(c => c.leads_unique > 0);
    list.sort((a,b) => b.leads_unique - a.leads_unique);
    list = list.slice(0, 10);
  }

  if (list.length === 0) {
    document.getElementById('topCampaignOut').innerHTML = '<div class="text-muted" style="text-align:center;padding:20px;">' +
      (tab === 'spend' ? 'Chưa có dữ liệu spend trong khoảng này.' : 'Chưa có camp nào ghi nhận lead trong khoảng này.') + '</div>';
    return;
  }

  let html = '<table class="table"><thead><tr><th>#</th><th>Campaign</th><th>TKQC</th><th>Impr</th><th>Clicks</th><th>CTR</th><th>CPC</th><th>Spend</th><th>Conv/Kết quả</th><th>CPA/Chi phí-KQ</th><th>Leads</th><th>CPL</th></tr></thead><tbody>';
  list.forEach((tc, i) => {
    const platformBadge = '<span class="badge ' + (tc.platform === 'google' ? 'badge-accent' : 'badge-warn') + '">' + (tc.platform||'').substring(0,2).toUpperCase() + '</span>';
    let convCell, cpaCell;
    if (tc.platform === 'google') {
      convCell = fmtNum(tc.conversions);
      cpaCell = fmtMoney(tc.cpa);
    } else {
      convCell = fmtNum(tc.conversions) +
        (tc.result_label ? '<div class="muted" style="font-size:11px">' + escapeHtmlG(tc.result_label) + '</div>' : '') +
        (tc.reach ? '<div class="muted" style="font-size:11px">Reach: ' + fmtNum(tc.reach) + ' · TS: ' + Number(tc.frequency).toFixed(2).replace('.',',') + '</div>' : '');
      cpaCell = fmtMoney(tc.cost_per_result);
    }
    html += '<tr>' +
      '<td class="mono text-dim">' + (i+1) + '</td>' +
      '<td><strong>' + escapeHtmlG(tc.camp_name) + '</strong></td>' +
      '<td>' + platformBadge + ' <span class="muted" style="font-size:12px">' + escapeHtmlG(tc.account_label||'') + '</span></td>' +
      '<td>' + fmtNum(tc.impressions) + '</td>' +
      '<td>' + fmtNum(tc.clicks) + '</td>' +
      '<td>' + Number(tc.ctr).toFixed(2).replace('.',',') + '%</td>' +
      '<td class="mono">' + fmtMoney(tc.cpc) + '</td>' +
      '<td class="mono">' + fmtMoney(tc.spend) + '</td>' +
      '<td>' + convCell + '</td>' +
      '<td class="mono">' + cpaCell + '</td>' +
      '<td>' + tc.leads + ' <span class="muted">(u: ' + tc.leads_unique + ')</span></td>' +
      '<td class="mono">' + fmtMoney(tc.cpl) + '</td>' +
      '</tr>';
  });
  html += '</tbody></table>';
  document.getElementById('topCampaignOut').innerHTML = html;
}

function switchTopTab(tab) {
  currentTopTab = tab;
  document.getElementById('tabSpendBtn').classList.toggle('btn-primary', tab === 'spend');
  document.getElementById('tabLeadsBtn').classList.toggle('btn-primary', tab === 'leads');
  const campaigns = currentScope === 'all' ? (lastAllData ? lastAllData.top_campaigns : []) : (lastGroupData ? lastGroupData.all_campaigns : []);
  renderTopCampaignTable(campaigns, tab);
}

function renderChannelsOverviewTable(rows, rangeLabel) {
  const groups = {};
  const standalone = [];
  rows.forEach(r => {
    if (r.group_id && r.group_name) {
      if (!groups[r.group_id]) groups[r.group_id] = { name: r.group_name, members: [] };
      groups[r.group_id].members.push(r);
    } else {
      standalone.push(r);
    }
  });

  let html = '<table class="table"><thead><tr>' +
    '<th>Channel</th><th>Ads</th><th>Lead</th><th>Spend</th><th>CPL</th><th>Conv</th><th>CPA</th><th>Lịch báo cáo</th><th>Trạng thái</th>' +
    '</tr></thead><tbody>';

  if (rows.length === 0) {
    html += '<tr><td colspan="9" class="text-muted" style="text-align:center;padding:24px;">Chưa có channel nào.</td></tr>';
  }

  function platformBadges(csv) {
    if (!csv) return '<span class="text-dim">—</span>';
    return csv.split(',').map(p => '<span class="badge ' + (p==='google'?'badge-accent':'badge-warn') + '">' + p.substring(0,2).toUpperCase() + '</span>').join(' ');
  }
  function statusBadge(active) {
    return active ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-muted">Tắt</span>';
  }

  Object.values(groups).forEach(g => {
    let gl=0, gu=0, gs=0, gc=0; let anyActive = false;
    g.members.forEach(m => { gl+=m.leads; gu+=m.leads_unique; gs+=m.spend; gc+=(m.conversions||0); if (m.active) anyActive = true; });
    const gcpl = gu > 0 ? Math.round(gs/gu) : 0;
    const gcpa = gc > 0 ? Math.round(gs/gc) : 0;
    html += '<tr style="background:var(--surface-2)"><td><strong>' + escapeHtmlG(g.name) + '</strong> <span class="badge badge-accent" style="margin-left:6px">Nhóm ×' + g.members.length + '</span></td>' +
      '<td>' + platformBadges([...new Set(g.members.flatMap(m => m.cred_platforms ? m.cred_platforms.split(',') : []))].join(',')) + '</td>' +
      '<td>' + gl + ' <span class="muted">(u: ' + gu + ')</span></td>' +
      '<td class="mono">' + fmtMoney(gs) + '</td>' +
      '<td class="mono">' + fmtMoney(gcpl) + '</td>' +
      '<td>' + gc + '</td><td class="mono">' + fmtMoney(gcpa) + '</td>' +
      '<td class="text-dim">—</td><td>' + statusBadge(anyActive) + '</td></tr>';
    g.members.forEach(m => {
      html += '<tr><td style="padding-left:32px"><span class="text-dim">↳</span> ' + escapeHtmlG(m.name) + '<div class="mono text-dim" style="font-size:11px">#' + m.id + '</div></td>' +
        '<td>' + platformBadges(m.cred_platforms) + '</td>' +
        '<td>' + m.leads + ' <span class="muted">(u: ' + m.leads_unique + ')</span></td>' +
        '<td class="mono">' + fmtMoney(m.spend) + '</td>' +
        '<td class="mono">' + fmtMoney(m.cpl) + '</td>' +
        '<td>' + (m.conversions||0) + '</td><td class="mono">' + fmtMoney(m.cpa||0) + '</td>' +
        '<td class="mono">' + escapeHtmlG(m.schedules || '—') + '</td>' +
        '<td>' + statusBadge(m.active) + '</td></tr>';
    });
  });

  standalone.forEach(c => {
    html += '<tr><td><strong>' + escapeHtmlG(c.name) + '</strong><div class="mono text-dim" style="font-size:11px">#' + c.id + '</div></td>' +
      '<td>' + platformBadges(c.cred_platforms) + '</td>' +
      '<td>' + c.leads + ' <span class="muted">(u: ' + c.leads_unique + ')</span></td>' +
      '<td class="mono">' + fmtMoney(c.spend) + '</td>' +
      '<td class="mono">' + fmtMoney(c.cpl) + '</td>' +
      '<td>' + (c.conversions||0) + '</td><td class="mono">' + fmtMoney(c.cpa||0) + '</td>' +
      '<td class="mono">' + escapeHtmlG(c.schedules || '—') + '</td>' +
      '<td>' + statusBadge(c.active) + '</td></tr>';
  });

  html += '</tbody></table>';
  document.getElementById('channelsOverviewOut').innerHTML = html;
}

function renderGroupBreakdown(data) {
  const card = document.getElementById('groupBreakdownCard');
  card.style.display = '';
  document.getElementById('groupBreakdownTitle').textContent = 'Chi tiết theo channel — ' + data.group.name;

  let html = '';
  for (const ch of data.channels) {
    html += '<h4 style="margin-top:20px;margin-bottom:10px;font-size:14px;">🏷 ' + escapeHtmlG(ch.name) + '</h4>';
    if (ch.campaigns.length === 0) {
      html += '<div class="text-muted" style="margin-bottom:10px">Chưa có dữ liệu camp trong khoảng này.</div>';
    } else {
      html += '<div style="overflow-x:auto"><table class="table"><thead><tr>' +
        '<th>Campaign</th><th>TKQC</th><th>Impr</th><th>Clicks</th><th>CTR</th><th>CPC</th><th>Spend</th><th>Conv/KQ</th><th>Leads</th><th>CPL</th>' +
        '</tr></thead><tbody>';
      for (const c of ch.campaigns) {
        const platformBadge = '<span class="badge ' + (c.platform === 'google' ? 'badge-accent' : 'badge-warn') + '">' + (c.platform||'').substring(0,2).toUpperCase() + '</span>';
        const convCell = c.platform === 'google'
          ? c.conversions + ' <span class="muted">(CPA ' + fmtMoney(c.cpa) + ')</span>'
          : c.conversions + (c.result_label ? ' <span class="muted">(' + escapeHtmlG(c.result_label) + ')</span>' : '');
        html += '<tr>' +
          '<td><strong>' + escapeHtmlG(c.name) + '</strong></td>' +
          '<td>' + platformBadge + ' <span class="muted" style="font-size:12px">' + escapeHtmlG(c.account_label||'') + '</span></td>' +
          '<td>' + fmtNum(c.impressions) + '</td>' +
          '<td>' + fmtNum(c.clicks) + '</td>' +
          '<td>' + Number(c.ctr).toFixed(2).replace('.',',') + '%</td>' +
          '<td class="mono">' + fmtMoney(c.cpc) + '</td>' +
          '<td class="mono">' + fmtMoney(c.spend) + '</td>' +
          '<td>' + convCell + '</td>' +
          '<td>' + c.leads + ' <span class="muted">(u:' + c.leads_unique + ')</span></td>' +
          '<td class="mono">' + fmtMoney(c.cpl) + '</td>' +
          '</tr>';
      }
      html += '</tbody></table></div>';
    }
    html += '<div class="text-muted" style="margin-top:6px">— Tổng hợp channel: Leads ' + ch.subtotal.leads + ' (u:' + ch.subtotal.leads_unique + ') · CPL ' + fmtMoney(ch.subtotal.cpl) + ' · Ngân sách ' + fmtMoney(ch.subtotal.spend) + '</div>';
  }
  document.getElementById('groupChannelsOut').innerHTML = html;

  document.getElementById('groupTotalOut').innerHTML =
    '<div class="card" style="background:var(--surface-2);margin-top:16px">' +
    '<div class="card-header"><h3>📊 Tổng nhóm ' + escapeHtmlG(data.group.name) + '</h3></div>' +
    '<div>— Leads: ' + data.total.leads + ' (u:' + data.total.leads_unique + ')</div>' +
    '<div>— Ngân sách: ' + fmtMoney(data.total.spend) + '</div>' +
    '<div>— CPL trung bình: ' + fmtMoney(data.total.cpl) + '</div>' +
    '</div>';
}

function renderAllScope(d) {
  document.getElementById('rangeLabel').textContent = 'Đang xem: ' + d.range.label + ' (' + d.range.from + ' → ' + d.range.to + ')';
  renderStatCards(d.stats, true);
  document.getElementById('topCampaignTitle').textContent = 'Top Campaign toàn hệ thống';
  document.getElementById('trendTitle').textContent = 'Xu hướng theo thời gian — toàn hệ thống';
  document.getElementById('overviewTitle').textContent = 'Tổng quan theo nhóm channel';
  renderTopCampaignTable(d.top_campaigns, currentTopTab);
  renderChannelsOverviewTable(d.channels_overview, d.range.label);
  document.getElementById('groupBreakdownCard').style.display = 'none';

  const leadsBox = document.getElementById('chartLeadsBox');
  const spendBox = document.getElementById('chartSpendBox');
  if (d.trend.leads_total.every(v => v === 0)) leadsBox.innerHTML = '<div class="chart-empty">Chưa có lead trong khoảng này.</div>';
  else renderLineChart(leadsBox, d.trend.labels, d.trend.leads_total, '--accent', v => v + ' leads');
  if (d.trend.spend.every(v => v === 0)) spendBox.innerHTML = '<div class="chart-empty">Chưa có spend trong khoảng này.</div>';
  else renderLineChart(spendBox, d.trend.labels, d.trend.spend, '--success', v => v.toLocaleString('vi-VN') + 'đ');
}

function renderGroupScope(d) {
  document.getElementById('rangeLabel').textContent = 'Đang xem: ' + d.range.label + ' (' + d.range.from + ' → ' + d.range.to + ')';
  renderStatCards(d.stats, false);
  document.getElementById('topCampaignTitle').textContent = 'Top Campaign — ' + d.group.name;
  document.getElementById('trendTitle').textContent = 'Xu hướng theo thời gian — ' + d.group.name;
  document.getElementById('overviewTitle').textContent = 'Tổng quan — ' + d.group.name;
  renderTopCampaignTable(d.all_campaigns, currentTopTab);
  renderGroupBreakdown(d);

  // Bảng overview rút gọn: chỉ hiện channel trong nhóm này
  const rows = d.channels.map(ch => ({
    id: ch.id, name: ch.name, group_id: null, group_name: null,
    leads: ch.subtotal.leads, leads_unique: ch.subtotal.leads_unique, spend: ch.subtotal.spend, cpl: ch.subtotal.cpl,
    cred_platforms: null, schedules: null, active: true,
  }));
  renderChannelsOverviewTable(rows, d.range.label);

  const leadsBox = document.getElementById('chartLeadsBox');
  const spendBox = document.getElementById('chartSpendBox');
  if (d.trend.leads.every(v => v === 0)) leadsBox.innerHTML = '<div class="chart-empty">Chưa có lead trong khoảng này.</div>';
  else renderLineChart(leadsBox, d.trend.labels, d.trend.leads, '--accent', v => v + ' leads');
  if (d.trend.spend.every(v => v === 0)) spendBox.innerHTML = '<div class="chart-empty">Chưa có spend trong khoảng này.</div>';
  else renderLineChart(spendBox, d.trend.labels, d.trend.spend, '--success', v => v.toLocaleString('vi-VN') + 'đ');
}

document.getElementById('tabSpendBtn').classList.add('btn-primary');
reloadCurrentScope();
</script>

<?php include __DIR__ . '/layout_end.php'; ?>
