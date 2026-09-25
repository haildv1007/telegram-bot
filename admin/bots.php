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
            'gemini_api_key' => trim($_POST['gemini_api_key'] ?? '') ?: null,
            'role' => $_POST['role'] ?? 'reader',
            'is_default_reporter' => isset($_POST['is_default_reporter']) ? 1 : 0,
            'active' => isset($_POST['active']) ? 1 : 0,
        ];

        try {
            if ($data['is_default_reporter']) {
                $db->exec('UPDATE bots SET is_default_reporter = 0');
            }
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE bots SET name=?, token=?, webhook_secret=?, gemini_api_key=?, role=?, is_default_reporter=?, active=? WHERE id=?');
                $stmt->execute([$data['name'], $data['token'], $data['webhook_secret'], $data['gemini_api_key'], $data['role'], $data['is_default_reporter'], $data['active'], $id]);
                flash('success', "Đã cập nhật bot #$id");
            } else {
                $stmt = $db->prepare('INSERT INTO bots (name, token, webhook_secret, gemini_api_key, role, is_default_reporter, active) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$data['name'], $data['token'], $data['webhook_secret'], $data['gemini_api_key'], $data['role'], $data['is_default_reporter'], $data['active']]);
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
      <input type="password" name="token" class="form-control mono" required autocomplete="off"
             value="<?= h($editing['token'] ?? '') ?>" placeholder="123456:ABC-DEF...">
      <label class="form-check mt-1" style="font-size:12px"><input type="checkbox" onchange="this.closest('.form-group').querySelector('input[name]').type=this.checked?'text':'password'"> Hiện token</label>
      <div class="form-help">Lấy từ @BotFather trên Telegram.</div>
    </div>

    <div class="form-group">
      <label class="form-label">Webhook Secret</label>
      <input type="password" name="webhook_secret" class="form-control mono" autocomplete="off"
             value="<?= h($editing['webhook_secret'] ?? '') ?>" placeholder="chuỗi bí mật tự đặt">
      <label class="form-check mt-1" style="font-size:12px"><input type="checkbox" onchange="this.closest('.form-group').querySelector('input[name]').type=this.checked?'text':'password'"> Hiện</label>
      <div class="form-help">Dùng khi setwebhook, tránh call giả mạo. Tự đặt chuỗi bất kỳ, hệ thống sẽ dùng khi set webhook.</div>
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
        <td class="mono"><?= h(substr($b['token'], 0, 6) . '••••••' . substr($b['token'], -4)) ?></td>
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

<!-- Gemini API Key -->
<div class="card mt-4">
  <div class="card-header">
    <h3>AI Chatbot — Gemini API Key</h3>
  </div>
  <div style="padding:16px">
    <div class="form-help mb-3">Key riêng cho từng bot. Để trống sẽ dùng GEMINI_API_KEY mặc định trong config.php.</div>
    <table class="table">
      <thead>
        <tr><th>#</th><th>Bot</th><th>Gemini API Key</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($bots as $b): ?>
        <tr>
          <td class="mono text-dim">#<?= $b['id'] ?></td>
          <td><strong><?= h($b['name']) ?></strong></td>
          <td>
            <input type="password" class="form-control mono" id="gemini-key-<?= $b['id'] ?>" style="font-size:13px" autocomplete="off"
                   value="<?= h($b['gemini_api_key'] ?? '') ?>" placeholder="Để trống = dùng key mặc định">
            <label style="font-size:12px;margin-top:4px;cursor:pointer"><input type="checkbox" onchange="document.getElementById('gemini-key-<?= $b['id'] ?>').type=this.checked?'text':'password'"> Hiện</label>
          </td>
          <td><button class="btn btn-sm btn-primary" onclick="saveGeminiKey(<?= $b['id'] ?>)">Lưu</button></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($bots)): ?>
        <tr><td colspan="4" class="text-muted" style="text-align:center;padding:16px;">Chưa có bot nào.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
async function saveGeminiKey(botId) {
  const key = document.getElementById('gemini-key-' + botId).value;
  const form = new FormData();
  form.append('bot_id', botId);
  form.append('gemini_api_key', key);
  const r = await fetch('api/save_gemini_key.php', { method: 'POST', body: form, credentials: 'same-origin' });
  const data = await r.json();
  if (data.ok) {
    alert('Đã lưu Gemini API Key cho bot #' + botId);
  } else {
    alert('Lỗi: ' + (data.error || 'Không rõ'));
  }
}
</script>

<!-- Webhook Management -->
<div class="card mt-4">
  <div class="card-header">
    <h3>Webhook Management</h3>
  </div>
  <div style="padding:16px">
    <div class="form-group">
      <label class="form-label">Base URL (domain gốc, không trailing slash)</label>
      <input type="text" id="wh-base-url" class="form-control mono" placeholder="https://yourdomain.com/telegram-bot" style="max-width:500px">
      <div class="form-help">VD: <code>https://yourdomain.com/telegram-bot</code> hoặc <code>https://xxx.ngrok-free.app</code></div>
    </div>

    <table class="table mt-3">
      <thead>
        <tr>
          <th>#</th>
          <th>Bot</th>
          <th>Webhook URL</th>
          <th>Trạng thái</th>
          <th>Hành động</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($bots as $b): ?>
        <tr id="wh-row-<?= $b['id'] ?>">
          <td class="mono text-dim">#<?= $b['id'] ?></td>
          <td><strong><?= h($b['name']) ?></strong></td>
          <td class="mono" style="font-size:12px;word-break:break-all" id="wh-url-<?= $b['id'] ?>">—</td>
          <td id="wh-status-<?= $b['id'] ?>"><span class="badge badge-muted">Chưa kiểm tra</span></td>
          <td style="white-space:nowrap">
            <button class="btn btn-sm" onclick="whAction(<?= $b['id'] ?>,'info')">Kiểm tra</button>
            <button class="btn btn-sm btn-primary" onclick="whAction(<?= $b['id'] ?>,'set')">Set Webhook</button>
            <button class="btn btn-sm btn-danger" onclick="whAction(<?= $b['id'] ?>,'delete')">Xóa Webhook</button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div id="wh-result" style="margin-top:12px;display:none">
      <label class="form-label">Kết quả:</label>
      <pre style="background:#1a1a2e;color:#16c784;padding:12px;border-radius:8px;font-size:12px;max-height:200px;overflow:auto" id="wh-result-text"></pre>
    </div>
  </div>
</div>

<script>
function whAction(botId, action) {
  const baseUrl = document.getElementById('wh-base-url').value.trim();
  if (action === 'set' && !baseUrl) {
    alert('Vui lòng nhập Base URL trước!');
    return;
  }

  const form = new FormData();
  form.append('bot_id', botId);
  form.append('wh_action', action);
  if (baseUrl) form.append('base_url', baseUrl);

  const statusEl = document.getElementById('wh-status-' + botId);
  const urlEl = document.getElementById('wh-url-' + botId);
  statusEl.innerHTML = '<span class="badge badge-accent">Đang xử lý...</span>';

  fetch('api/webhook.php', {method: 'POST', body: form})
    .then(r => r.json())
    .then(data => {
      const resultBox = document.getElementById('wh-result');
      const resultText = document.getElementById('wh-result-text');
      resultBox.style.display = 'block';
      resultText.textContent = JSON.stringify(data, null, 2);

      if (action === 'info') {
        const info = data.result || {};
        if (info.url) {
          urlEl.textContent = info.url;
          const pending = info.pending_update_count || 0;
          const lastErr = info.last_error_message || '';
          if (lastErr) {
            statusEl.innerHTML = '<span class="badge badge-danger">Lỗi: ' + lastErr + '</span>';
          } else {
            statusEl.innerHTML = '<span class="badge badge-success">OK (pending: ' + pending + ')</span>';
          }
        } else {
          urlEl.textContent = '(chưa set)';
          statusEl.innerHTML = '<span class="badge badge-muted">Chưa set webhook</span>';
        }
      } else if (action === 'set') {
        if (data.ok) {
          urlEl.textContent = data.webhook_url || '';
          statusEl.innerHTML = '<span class="badge badge-success">Set thành công!</span>';
        } else {
          statusEl.innerHTML = '<span class="badge badge-danger">FAIL: ' + (data.description || data.msg || '') + '</span>';
        }
      } else if (action === 'delete') {
        if (data.ok) {
          urlEl.textContent = '(đã xóa)';
          statusEl.innerHTML = '<span class="badge badge-muted">Đã xóa webhook</span>';
        } else {
          statusEl.innerHTML = '<span class="badge badge-danger">FAIL</span>';
        }
      }
    })
    .catch(err => {
      statusEl.innerHTML = '<span class="badge badge-danger">Network error</span>';
    });
}
</script>

<?php include __DIR__ . '/layout_end.php'; ?>
