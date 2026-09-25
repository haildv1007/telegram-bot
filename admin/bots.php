<?php
$page = 'bots';
$title = 'Bots Telegram';
$breadcrumb = ['Cấu hình', 'Bots Telegram'];
require_once __DIR__ . '/auth.php';
require_login();
$db = get_db();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $data = [
            'name' => trim($_POST['name'] ?? ''),
            'token' => trim($_POST['token'] ?? ''),
            'webhook_secret' => trim($_POST['webhook_secret'] ?? ''),
            'role' => $_POST['role'] ?? 'reader',
            'is_default_reporter' => isset($_POST['is_default_reporter']) ? 1 : 0,
            'active' => isset($_POST['active']) ? 1 : 0,
        ];

        try {
            if ($data['is_default_reporter']) {
                $db->exec('UPDATE bots SET is_default_reporter = 0');
            }
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE bots SET name=?, token=?, webhook_secret=?, role=?, is_default_reporter=?, active=? WHERE id=?');
                $stmt->execute([$data['name'], $data['token'], $data['webhook_secret'], $data['role'], $data['is_default_reporter'], $data['active'], $id]);
                flash('success', "Đã cập nhật bot #$id");
            } else {
                $stmt = $db->prepare('INSERT INTO bots (name, token, webhook_secret, role, is_default_reporter, active) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$data['name'], $data['token'], $data['webhook_secret'], $data['role'], $data['is_default_reporter'], $data['active']]);
                flash('success', 'Đã tạo bot mới');
            }
        } catch (Exception $e) {
            flash('error', 'Lỗi: ' . $e->getMessage());
        }
        header('Location: bots.php');
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $db->prepare('DELETE FROM bots WHERE id=?')->execute([$id]);
            flash('success', "Đã xóa bot #$id");
        } catch (Exception $e) {
            flash('error', 'Không xóa được: ' . $e->getMessage());
        }
        header('Location: bots.php');
        exit;
    }
}

// Load data
$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare('SELECT * FROM bots WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $editing = $stmt->fetch();
}
$isNew = isset($_GET['new']);
$showForm = $editing || $isNew;

$bots = $db->query('SELECT * FROM bots ORDER BY id DESC')->fetchAll();

include __DIR__ . '/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Bots Telegram</h1>
    <div class="page-subtitle">Quản lý các bot đọc lead và bot gửi báo cáo</div>
  </div>
  <?php if (!$showForm): ?>
    <a href="?new=1" class="btn btn-primary">+ Thêm bot</a>
  <?php endif; ?>
</div>

<?php if ($showForm): ?>
<div class="card">
  <div class="card-header">
    <h3><?= $editing ? 'Sửa bot #' . $editing['id'] : 'Thêm bot mới' ?></h3>
    <a href="bots.php" class="btn btn-sm">← Quay lại</a>
  </div>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $editing['id'] ?? 0 ?>">

    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Tên bot</label>
        <input type="text" name="name" class="form-control" required
               value="<?= h($editing['name'] ?? '') ?>" placeholder="VD: SPF Shipfood Bot">
      </div>
      <div class="form-group">
        <label class="form-label">Vai trò</label>
        <select name="role" class="form-select">
          <?php foreach (['reader'=>'Reader (chỉ đọc lead)', 'reporter'=>'Reporter (chỉ gửi báo cáo)', 'both'=>'Cả 2'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= ($editing['role'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Bot Token</label>
      <input type="text" name="token" class="form-control mono" required
             value="<?= h($editing['token'] ?? '') ?>" placeholder="123456:ABC-DEF...">
      <div class="form-help">Lấy từ @BotFather trên Telegram.</div>
    </div>

    <div class="form-group">
      <label class="form-label">Webhook Secret</label>
      <input type="text" name="webhook_secret" class="form-control mono"
             value="<?= h($editing['webhook_secret'] ?? '') ?>" placeholder="chuỗi bí mật tự đặt">
      <div class="form-help">Dùng khi setwebhook, tránh call giả mạo.</div>
    </div>

    <div class="form-check">
      <input type="checkbox" name="is_default_reporter" id="def" <?= !empty($editing['is_default_reporter']) ? 'checked' : '' ?>>
      <label for="def">Đặt làm bot gửi báo cáo mặc định (fallback khi channel chưa chọn bot)</label>
    </div>
    <div class="form-check mt-2">
      <input type="checkbox" name="active" id="act" <?= empty($editing) || !empty($editing['active']) ? 'checked' : '' ?>>
      <label for="act">Kích hoạt</label>
    </div>

    <div class="d-flex gap-2 mt-3">
      <button type="submit" class="btn btn-primary"><?= $editing ? 'Lưu thay đổi' : 'Tạo bot' ?></button>
      <a href="bots.php" class="btn">Hủy</a>
    </div>
  </form>
</div>
<?php else: ?>

<div class="card">
  <table class="table">
    <thead>
      <tr>
        <th>#</th>
        <th>Tên</th>
        <th>Token (rút gọn)</th>
        <th>Vai trò</th>
        <th>Default reporter</th>
        <th>Trạng thái</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($bots as $b): ?>
      <tr>
        <td class="mono text-dim">#<?= $b['id'] ?></td>
        <td><strong><?= h($b['name']) ?></strong></td>
        <td class="mono"><?= h(substr($b['token'], 0, 10) . '…' . substr($b['token'], -6)) ?></td>
        <td>
          <?php $rolelabel = ['reader'=>'Reader','reporter'=>'Reporter','both'=>'Both'][$b['role']] ?? $b['role']; ?>
          <span class="badge badge-accent"><?= h($rolelabel) ?></span>
        </td>
        <td><?= $b['is_default_reporter'] ? '<span class="badge badge-success">✓</span>' : '<span class="text-dim">—</span>' ?></td>
        <td><?= $b['active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-muted">Tắt</span>' ?></td>
        <td class="text-right">
          <a href="?edit=<?= $b['id'] ?>" class="btn btn-sm">Sửa</a>
          <form method="post" style="display:inline" onsubmit="return confirm('Xóa bot <?= h($b['name']) ?>?')">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $b['id'] ?>">
            <button class="btn btn-sm btn-danger">Xóa</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($bots)): ?>
        <tr><td colspan="7" class="text-muted" style="text-align:center;padding:24px;">Chưa có bot nào. <a href="?new=1">Thêm bot đầu tiên</a>.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>

<?php include __DIR__ . '/layout_end.php'; ?>
