<?php
$page = 'rules';
$title = 'Rule parse lead';
$breadcrumb = ['Cấu hình', 'Rule parse lead'];
require_once __DIR__ . '/auth.php';
require_login();
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $data = [
            'channel_id' => $editing ? $editing['channel_id'] : null,
            'priority' => (int)($_POST['priority'] ?? 100),
            'match_type' => $_POST['match_type'] ?? 'contains',
            'match_field' => $_POST['match_field'] ?? 'full_text',
            'pattern' => trim($_POST['pattern'] ?? ''),
            'extract_name_regex' => trim($_POST['extract_name_regex'] ?? '') ?: null,
            'extract_phone_regex' => trim($_POST['extract_phone_regex'] ?? '') ?: null,
            'extract_cccd_regex' => trim($_POST['extract_cccd_regex'] ?? '') ?: null,
            'active' => isset($_POST['active']) ? 1 : 0,
        ];
        try {
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE lead_rules SET channel_id=?, priority=?, match_type=?, match_field=?, pattern=?, extract_name_regex=?, extract_phone_regex=?, extract_cccd_regex=?, active=? WHERE id=?');
                $stmt->execute([$data['channel_id'], $data['priority'], $data['match_type'], $data['match_field'], $data['pattern'], $data['extract_name_regex'], $data['extract_phone_regex'], $data['extract_cccd_regex'], $data['active'], $id]);
                flash('success', "Đã cập nhật rule #$id");
            } else {
                $stmt = $db->prepare('INSERT INTO lead_rules (channel_id, priority, match_type, match_field, pattern, extract_name_regex, extract_phone_regex, extract_cccd_regex, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$data['channel_id'], $data['priority'], $data['match_type'], $data['match_field'], $data['pattern'], $data['extract_name_regex'], $data['extract_phone_regex'], $data['extract_cccd_regex'], $data['active']]);
                flash('success', 'Đã tạo rule mới');
            }
        } catch (Exception $e) {
            flash('error', 'Lỗi: ' . $e->getMessage());
        }
        header('Location: rules.php');
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $db->prepare('DELETE FROM lead_rules WHERE id=?')->execute([$id]);
            flash('success', "Đã xóa rule #$id");
        } catch (Exception $e) {
            flash('error', $e->getMessage());
        }
        header('Location: rules.php');
        exit;
    }
}

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare('SELECT * FROM lead_rules WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $editing = $stmt->fetch();
}
$isNew = isset($_GET['new']);
$showForm = $editing || $isNew;

$rules = $db->query('
  SELECT r.*, c.name AS channel_name
  FROM lead_rules r
  LEFT JOIN ad_channels c ON c.id = r.channel_id
  ORDER BY r.channel_id, r.priority DESC, r.id
')->fetchAll();

include __DIR__ . '/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Rule parse lead</h1>
    <div class="page-subtitle">Nhận diện tin lead thuộc channel nào dựa vào nội dung tin nhắn</div>
  </div>
  <?php if (!$showForm): ?>
    <a href="?new=1" class="btn btn-primary">+ Thêm rule</a>
  <?php endif; ?>
</div>

<?php if ($showForm): ?>
<div class="form-row">
  <div class="card">
    <div class="card-header">
      <h3><?= $editing ? 'Sửa rule #' . $editing['id'] : 'Tạo rule mới' ?></h3>
      <a href="rules.php" class="btn btn-sm">← Quay lại</a>
    </div>

    <form method="post">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= $editing['id'] ?? 0 ?>">

      <div class="form-group">
        <label class="form-label">Ưu tiên</label>
        <input type="number" name="priority" class="form-control" value="<?= $editing['priority'] ?? 100 ?>" style="max-width:200px">
        <div class="form-help">Số cao hơn = ưu tiên hơn. Gắn rule vào channel ở trang Channels báo cáo.</div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Kiểu match</label>
          <select name="match_type" class="form-select">
            <option value="contains" <?= ($editing['match_type'] ?? '')==='contains'?'selected':'' ?>>Contains (chứa chuỗi)</option>
            <option value="regex" <?= ($editing['match_type'] ?? '')==='regex'?'selected':'' ?>>Regex</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Field áp dụng</label>
          <select name="match_field" class="form-select">
            <option value="full_text" <?= ($editing['match_field'] ?? '')==='full_text'?'selected':'' ?>>Full text</option>
            <option value="source" <?= ($editing['match_field'] ?? '')==='source'?'selected':'' ?>>Field "Nguồn"</option>
            <option value="utm" <?= ($editing['match_field'] ?? '')==='utm'?'selected':'' ?>>UTM</option>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Pattern</label>
        <input type="text" name="pattern" class="form-control mono" required
               value="<?= h($editing['pattern'] ?? '') ?>" placeholder="VD: shipfood.io.vn hoặc utm_source=fb_camp_a">
      </div>

      <h4 style="margin-top:20px;margin-bottom:12px;font-size:13px;text-transform:uppercase;letter-spacing:0.6px;color:var(--text-muted);">Extract regex (optional)</h4>
      <div class="form-group">
        <label class="form-label">Regex extract Họ tên</label>
        <input type="text" name="extract_name_regex" class="form-control mono"
               value="<?= h($editing['extract_name_regex'] ?? '') ?>" placeholder="Bỏ trống = dùng parser mặc định">
      </div>
      <div class="form-group">
        <label class="form-label">Regex extract SĐT</label>
        <input type="text" name="extract_phone_regex" class="form-control mono"
               value="<?= h($editing['extract_phone_regex'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Regex extract CCCD</label>
        <input type="text" name="extract_cccd_regex" class="form-control mono"
               value="<?= h($editing['extract_cccd_regex'] ?? '') ?>">
      </div>

      <div class="form-check">
        <input type="checkbox" name="active" id="act" <?= empty($editing) || !empty($editing['active']) ? 'checked' : '' ?>>
        <label for="act">Kích hoạt</label>
      </div>

      <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn btn-primary"><?= $editing ? 'Lưu' : 'Tạo' ?></button>
        <a href="rules.php" class="btn">Hủy</a>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="card-header"><h3>Test rule (preview)</h3></div>
    <div class="form-group">
      <label class="form-label">Paste nội dung tin lead</label>
      <textarea id="testText" class="form-control" style="min-height:180px" placeholder="Paste 1 tin Telegram thật vào đây để test..."></textarea>
    </div>
    <button type="button" class="btn btn-primary" onclick="runTest()">Test parse</button>
    <div id="testResult" class="mt-3"></div>
  </div>
</div>

<script>
async function runTest() {
  const text = document.getElementById('testText').value;
  const res = document.getElementById('testResult');
  if (!text.trim()) { res.innerHTML = '<div class="alert alert-danger">Nhập text để test</div>'; return; }
  res.innerHTML = '<div class="text-muted">Đang chạy…</div>';

  const form = new FormData();
  form.append('text', text);
  const r = await fetch('api/test_rule.php', { method: 'POST', body: form, credentials: 'same-origin' });
  const data = await r.json();

  if (!data.ok) { res.innerHTML = '<div class="alert alert-danger">' + data.error + '</div>'; return; }

  let html = '';
  if (data.matched) {
    html += '<div class="alert alert-success">✓ Match channel <strong>#' + data.channel_id + ' · ' + escapeHtml(data.channel_name) + '</strong> (rule #' + data.rule_id + ')</div>';
  } else {
    html += '<div class="alert alert-danger">✗ Không match rule nào</div>';
  }
  html += '<div class="text-muted mb-2" style="font-size:12px;text-transform:uppercase;letter-spacing:0.5px;">Fields extract</div>';
  html += '<table class="table">';
  for (const k of ['name','phone','cccd','area','source','ip']) {
    const v = data.fields[k] || '';
    html += '<tr><td class="text-muted" style="width:80px">' + k + '</td><td class="mono">' + (v ? escapeHtml(v) : '<span class="text-dim">—</span>') + '</td></tr>';
  }
  html += '</table>';
  res.innerHTML = html;
}
function escapeHtml(s) { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
</script>

<?php else: ?>

<div class="card">
  <table class="table">
    <thead>
      <tr>
        <th>#</th><th>Channel</th><th>Kiểu</th><th>Pattern</th><th>Ưu tiên</th><th>Trạng thái</th><th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rules as $r): ?>
      <tr>
        <td class="mono text-dim">#<?= $r['id'] ?></td>
        <td><?= $r['channel_name'] ? h($r['channel_name']) : '<span class="text-dim">Chưa gắn</span>' ?></td>
        <td>
          <span class="badge <?= $r['match_type']==='regex'?'badge-warn':'badge-muted' ?>"><?= h($r['match_type']) ?></span>
          <span class="text-muted"><?= h($r['match_field']) ?></span>
        </td>
        <td class="mono"><?= h($r['pattern']) ?></td>
        <td><?= (int)$r['priority'] ?></td>
        <td><?= $r['active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-muted">Tắt</span>' ?></td>
        <td class="text-right">
          <a href="?edit=<?= $r['id'] ?>" class="btn btn-sm">Sửa</a>
          <form method="post" style="display:inline" onsubmit="return confirm('Xóa rule?')">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $r['id'] ?>">
            <button class="btn btn-sm btn-danger">Xóa</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($rules)): ?>
      <tr><td colspan="7" class="text-muted" style="text-align:center;padding:24px;">Chưa có rule. <a href="?new=1">Tạo rule đầu tiên</a>.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>

<?php include __DIR__ . '/layout_end.php'; ?>
