<?php
$page = 'reports';
$title = 'Lịch sử báo cáo';
$breadcrumb = ['Vận hành', 'Lịch sử báo cáo'];
require_once __DIR__ . '/auth.php';
require_login();
$db = get_db();

$rows = $db->query('
  SELECT r.*, c.name AS channel_name
  FROM report_history r
  LEFT JOIN ad_channels c ON c.id = r.channel_id
  ORDER BY r.sent_at DESC LIMIT 100
')->fetchAll();

include __DIR__ . '/layout.php';
?>

<?php
$channels = $db->query('SELECT id, name FROM ad_channels WHERE active=1 ORDER BY id')->fetchAll();
$groups = $db->query('SELECT id, name FROM channel_groups ORDER BY name')->fetchAll();
$digestRows = $db->query('
  SELECT d.*, g.name AS group_name
  FROM digest_history d
  LEFT JOIN channel_groups g ON g.id = d.group_id
  ORDER BY d.sent_at DESC LIMIT 50
')->fetchAll();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Lịch sử báo cáo</h1>
    <div class="page-subtitle">100 báo cáo gần nhất đã gửi qua Telegram</div>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3>Gửi báo cáo thủ công</h3></div>
  <div class="form-row">
    <div class="form-group">
      <label class="form-label">Channel</label>
      <select id="mChannel" class="form-select">
        <?php foreach ($channels as $c): ?>
          <option value="<?= $c['id'] ?>">#<?= $c['id'] ?> · <?= h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Loại</label>
      <select id="mType" class="form-select">
        <option value="summary">Tổng kết</option>
        <option value="progress">Tiến độ</option>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Ngày</label>
      <input type="date" id="mDate" class="form-control" value="<?= date('Y-m-d') ?>">
    </div>
  </div>
  <button class="btn btn-primary" onclick="sendNow()">Gửi ngay</button>
  <div id="mResult" class="mt-3"></div>
</div>

<div class="card">
  <div class="card-header"><h3>Gửi digest lũy kế nhóm thủ công</h3></div>
  <?php if (empty($groups)): ?>
    <p class="text-muted">Chưa có nhóm channel nào. <a href="groups.php">Tạo nhóm</a> trước.</p>
  <?php else: ?>
  <div class="form-row">
    <div class="form-group">
      <label class="form-label">Nhóm</label>
      <select id="dGroup" class="form-select">
        <?php foreach ($groups as $g): ?>
          <option value="<?= $g['id'] ?>">#<?= $g['id'] ?> · <?= h($g['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Ngày (lũy kế tính đến hết ngày này)</label>
      <input type="date" id="dDate" class="form-control" value="<?= date('Y-m-d', strtotime('-1 day')) ?>">
    </div>
  </div>
  <button class="btn btn-primary" onclick="sendDigestNow()">Gửi digest ngay</button>
  <div id="dResult" class="mt-3"></div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-header"><h3>Lịch sử digest nhóm</h3></div>
  <?php if (empty($digestRows)): ?>
    <p class="text-muted" style="text-align:center;padding:16px;">Chưa có digest nào được gửi.</p>
  <?php else: ?>
  <table class="table">
    <thead><tr><th>#</th><th>Gửi lúc</th><th>Nhóm</th><th>Ngày lũy kế</th><th>Trạng thái</th><th>Nội dung</th></tr></thead>
    <tbody>
      <?php foreach ($digestRows as $d): ?>
      <tr>
        <td class="mono text-dim">#<?= $d['id'] ?></td>
        <td class="mono"><?= h(date('d/m H:i', strtotime($d['sent_at']))) ?></td>
        <td><?= h($d['group_name'] ?: '—') ?></td>
        <td class="mono"><?= h(date('d/m/Y', strtotime($d['send_date']))) ?></td>
        <td><?= $d['success'] ? '<span class="badge badge-success">OK</span>' : '<span class="badge badge-danger">Lỗi</span>' ?></td>
        <td>
          <details>
            <summary style="cursor:pointer"><?= h(mb_substr($d['message'] ?? '', 0, 60)) ?>…</summary>
            <pre style="white-space:pre-wrap;background:var(--bg);padding:12px;border-radius:4px;margin-top:8px;font-size:12px;"><?= h($d['message']) ?></pre>
            <?php if ($d['error']): ?><div class="alert alert-danger" style="margin-top:8px"><?= h($d['error']) ?></div><?php endif; ?>
          </details>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<script>
async function sendDigestNow() {
  const box = document.getElementById('dResult');
  box.innerHTML = '<div class="text-muted">Đang gửi…</div>';
  const fd = new FormData();
  fd.append('group_id', document.getElementById('dGroup').value);
  fd.append('report_date', document.getElementById('dDate').value);
  const r = await fetch('api/send_digest.php', { method:'POST', body:fd, credentials:'same-origin' });
  const d = await r.json();
  if (d.ok) {
    box.innerHTML = '<div class="alert alert-success">✓ Đã gửi</div><pre style="white-space:pre-wrap;background:var(--bg);padding:12px;border-radius:6px;">' + escapeHtml(d.preview) + '</pre>';
    setTimeout(() => location.reload(), 1500);
  } else {
    box.innerHTML = '<div class="alert alert-danger">✗ ' + escapeHtml(d.error) + '</div>' +
      (d.preview ? '<pre style="white-space:pre-wrap;background:var(--bg);padding:12px;border-radius:6px;">' + escapeHtml(d.preview) + '</pre>' : '');
  }
}
</script>

<script>
async function sendNow() {
  const box = document.getElementById('mResult');
  box.innerHTML = '<div class="text-muted">Đang gửi…</div>';
  const fd = new FormData();
  fd.append('channel_id', document.getElementById('mChannel').value);
  fd.append('report_type', document.getElementById('mType').value);
  fd.append('report_date', document.getElementById('mDate').value);
  const r = await fetch('api/send_report.php', { method:'POST', body:fd, credentials:'same-origin' });
  const d = await r.json();
  if (d.ok) {
    box.innerHTML = '<div class="alert alert-success">✓ Đã gửi</div><pre style="white-space:pre-wrap;background:var(--bg);padding:12px;border-radius:6px;">' + escapeHtml(d.preview) + '</pre>';
    setTimeout(() => location.reload(), 1500);
  } else {
    box.innerHTML = '<div class="alert alert-danger">✗ ' + escapeHtml(d.error) + '</div>' +
      (d.preview ? '<pre style="white-space:pre-wrap;background:var(--bg);padding:12px;border-radius:6px;">' + escapeHtml(d.preview) + '</pre>' : '');
  }
}
function escapeHtml(s) { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
</script>

<div class="card">
  <?php if (empty($rows)): ?>
    <p class="text-muted" style="text-align:center;padding:24px;">Chưa có báo cáo nào được gửi.</p>
  <?php else: ?>
  <table class="table">
    <thead><tr><th>#</th><th>Gửi lúc</th><th>Channel</th><th>Ngày</th><th>Loại</th><th>Trạng thái</th><th>Nội dung</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td class="mono text-dim">#<?= $r['id'] ?></td>
        <td class="mono"><?= h(date('d/m H:i', strtotime($r['sent_at']))) ?></td>
        <td><?= h($r['channel_name'] ?: '—') ?></td>
        <td class="mono"><?= h(date('d/m/Y', strtotime($r['report_date']))) ?></td>
        <td><span class="badge badge-muted"><?= h($r['report_type']) ?></span></td>
        <td>
          <?= $r['success'] ? '<span class="badge badge-success">OK</span>' : '<span class="badge badge-danger">Lỗi</span>' ?>
        </td>
        <td>
          <details>
            <summary style="cursor:pointer"><?= h(mb_substr($r['message'] ?? '', 0, 60)) ?>…</summary>
            <pre style="white-space:pre-wrap;background:var(--bg);padding:12px;border-radius:4px;margin-top:8px;font-size:12px;"><?= h($r['message']) ?></pre>
            <?php if ($r['error']): ?><div class="alert alert-danger" style="margin-top:8px"><?= h($r['error']) ?></div><?php endif; ?>
          </details>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/layout_end.php'; ?>
