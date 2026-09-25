<?php
$page = 'credentials';
$title = 'Tài khoản Ads';
$breadcrumb = ['Cấu hình', 'Tài khoản Ads'];
require_once __DIR__ . '/auth.php';
require_login();
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $data = [
            'platform' => $_POST['platform'] ?? 'google',
            'account_id' => trim($_POST['account_id'] ?? ''),
            'login_customer_id' => trim($_POST['login_customer_id'] ?? '') ?: null,
            'account_label' => trim($_POST['account_label'] ?? ''),
            'currency' => $_POST['currency'] ?? 'VND',
            'developer_token' => trim($_POST['developer_token'] ?? '') ?: null,
            'client_id' => trim($_POST['client_id'] ?? '') ?: null,
            'client_secret' => trim($_POST['client_secret'] ?? '') ?: null,
            'refresh_token' => trim($_POST['refresh_token'] ?? '') ?: null,
            'active' => isset($_POST['active']) ? 1 : 0,
        ];
        try {
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE ads_credentials SET platform=?, account_id=?, login_customer_id=?, account_label=?, currency=?, developer_token=?, client_id=?, client_secret=?, refresh_token=?, active=? WHERE id=?');
                $stmt->execute([$data['platform'], $data['account_id'], $data['login_customer_id'], $data['account_label'], $data['currency'], $data['developer_token'], $data['client_id'], $data['client_secret'], $data['refresh_token'], $data['active'], $id]);
                flash('success', "Đã cập nhật credential #$id");
            } else {
                $stmt = $db->prepare('INSERT INTO ads_credentials (platform, account_id, login_customer_id, account_label, currency, developer_token, client_id, client_secret, refresh_token, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$data['platform'], $data['account_id'], $data['login_customer_id'], $data['account_label'], $data['currency'], $data['developer_token'], $data['client_id'], $data['client_secret'], $data['refresh_token'], $data['active']]);
                flash('success', 'Đã thêm tài khoản Ads');
            }
        } catch (Exception $e) {
            flash('error', 'Lỗi: ' . $e->getMessage());
        }
        header('Location: credentials.php');
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $db->prepare('DELETE FROM ads_credentials WHERE id=?')->execute([$id]);
            flash('success', "Đã xóa #$id");
        } catch (Exception $e) {
            flash('error', 'Không xóa được: ' . $e->getMessage());
        }
        header('Location: credentials.php');
        exit;
    }
}

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare('SELECT * FROM ads_credentials WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $editing = $stmt->fetch();
}
$isNew = isset($_GET['new']);
$showForm = $editing || $isNew;

// Exchange rate settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_exchange') {
    csrf_check();
    $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('exchange_rate_multiplier', ?) ON DUPLICATE KEY UPDATE setting_value = ?")
       ->execute([$_POST['multiplier'], $_POST['multiplier']]);
    $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('usdt_vnd_rate', ?) ON DUPLICATE KEY UPDATE setting_value = ?")
       ->execute([$_POST['usdt_rate'], $_POST['usdt_rate']]);
    $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('usdt_vnd_rate_updated', ?) ON DUPLICATE KEY UPDATE setting_value = ?")
       ->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
    flash('success', 'Đã lưu tỷ giá');
    header('Location: credentials.php');
    exit;
}

$creds = $db->query('SELECT * FROM ads_credentials ORDER BY platform, id')->fetchAll();
$exSettings = $db->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('usdt_vnd_rate','exchange_rate_multiplier','usdt_vnd_rate_updated')")->fetchAll(PDO::FETCH_KEY_PAIR);
$usdtRate = (float)($exSettings['usdt_vnd_rate'] ?? 25500);
$multiplier = (float)($exSettings['exchange_rate_multiplier'] ?? 1.095);
$rateUpdated = $exSettings['usdt_vnd_rate_updated'] ?? '';
$hasUsdAccount = false;
foreach ($creds as $c) { if (($c['currency'] ?? 'VND') === 'USD') { $hasUsdAccount = true; break; } }

include __DIR__ . '/layout.php';
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Tài khoản Ads</h1>
    <div class="page-subtitle">Google Ads & Facebook Ads credentials</div>
  </div>
  <?php if (!$showForm): ?>
    <a href="?new=1" class="btn btn-primary">+ Thêm tài khoản</a>
  <?php endif; ?>
</div>

<?php if ($showForm): $platform = $editing['platform'] ?? ($_GET['platform'] ?? 'google'); ?>
<div class="card">
  <div class="card-header">
    <h3><?= $editing ? 'Sửa credential #' . $editing['id'] : 'Thêm tài khoản Ads' ?></h3>
    <a href="credentials.php" class="btn btn-sm">← Quay lại</a>
  </div>

  <form method="post" id="credForm">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $editing['id'] ?? 0 ?>">

    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Nền tảng</label>
        <select name="platform" id="platformSel" class="form-select" <?= $editing ? 'disabled' : '' ?>>
          <option value="google" <?= $platform==='google'?'selected':'' ?>>Google Ads</option>
          <option value="facebook" <?= $platform==='facebook'?'selected':'' ?>>Facebook Ads</option>
        </select>
        <?php if ($editing): ?><input type="hidden" name="platform" value="<?= h($platform) ?>"><?php endif; ?>
      </div>
      <div class="form-group">
        <label class="form-label">Account ID</label>
        <input type="text" name="account_id" class="form-control mono" required
               value="<?= h($editing['account_id'] ?? '') ?>"
               placeholder="GG: customer_id 10 chữ số | FB: act_xxxxxx">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Nhãn hiển thị</label>
        <input type="text" name="account_label" class="form-control"
               value="<?= h($editing['account_label'] ?? '') ?>" placeholder="VD: SPF - MCC chính">
      </div>
      <div class="form-group" style="max-width:140px">
        <label class="form-label">Loại tiền</label>
        <select name="currency" class="form-select">
          <option value="VND" <?= ($editing['currency'] ?? 'VND') === 'VND' ? 'selected' : '' ?>>VND</option>
          <option value="USD" <?= ($editing['currency'] ?? '') === 'USD' ? 'selected' : '' ?>>USD</option>
        </select>
      </div>
    </div>

    <div id="ggFields" class="<?= $platform==='facebook'?'d-none':'' ?>" style="<?= $platform==='facebook'?'display:none':'' ?>">
      <div class="form-group">
        <label class="form-label">Login Customer ID (MCC)</label>
        <input type="text" name="login_customer_id" class="form-control mono"
               value="<?= h($editing['login_customer_id'] ?? '') ?>"
               placeholder="Bỏ trống nếu Account ID là MCC. Nếu là client, điền customer_id của MCC (10 số).">
        <div class="form-help">Bắt buộc nếu account là client customer dưới MCC.</div>
      </div>
      <div class="form-group">
        <label class="form-label">Developer Token (Google Ads)</label>
        <input type="password" name="developer_token" class="form-control mono" autocomplete="off" value="<?= h($editing['developer_token'] ?? '') ?>">
        <label style="font-size:12px;margin-top:4px;cursor:pointer"><input type="checkbox" onchange="this.closest('.form-group').querySelector('input[name]').type=this.checked?'text':'password'"> Hiện</label>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">OAuth Client ID</label>
          <input type="text" name="client_id" class="form-control mono" value="<?= h($editing['client_id'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">OAuth Client Secret</label>
          <input type="password" name="client_secret" class="form-control mono" autocomplete="off" value="<?= h($editing['client_secret'] ?? '') ?>">
          <label style="font-size:12px;margin-top:4px;cursor:pointer"><input type="checkbox" onchange="this.closest('.form-group').querySelector('input[name]').type=this.checked?'text':'password'"> Hiện</label>
        </div>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label" id="tokenLbl"><?= $platform==='facebook' ? 'System User Access Token' : 'Refresh Token' ?></label>
      <textarea name="refresh_token" class="form-control mono" style="-webkit-text-security:disc"><?= h($editing['refresh_token'] ?? '') ?></textarea>
      <label style="font-size:12px;margin-top:4px;cursor:pointer"><input type="checkbox" onchange="var t=this.closest('.form-group').querySelector('textarea');t.style.webkitTextSecurity=this.checked?'none':'disc'"> Hiện</label>
      <div class="form-help" id="tokenHelp">
        <?php if ($platform==='facebook'): ?>
          Long-lived token của System User (scope ads_read + business_management).
        <?php else: ?>
          Refresh token lấy từ OAuth flow của tài khoản có quyền đọc Google Ads.
        <?php endif; ?>
      </div>
    </div>

    <div class="form-check">
      <input type="checkbox" name="active" id="act" <?= empty($editing) || !empty($editing['active']) ? 'checked' : '' ?>>
      <label for="act">Kích hoạt</label>
    </div>

    <div class="d-flex gap-2 mt-3">
      <button type="submit" class="btn btn-primary"><?= $editing ? 'Lưu thay đổi' : 'Thêm' ?></button>
      <a href="credentials.php" class="btn">Hủy</a>
    </div>
  </form>
</div>

<script>
document.getElementById('platformSel')?.addEventListener('change', e => {
  const isFb = e.target.value === 'facebook';
  document.getElementById('ggFields').style.display = isFb ? 'none' : '';
  document.getElementById('tokenLbl').textContent = isFb ? 'System User Access Token' : 'Refresh Token';
  document.getElementById('tokenHelp').textContent = isFb
    ? 'Long-lived token của System User (scope ads_read + business_management).'
    : 'Refresh token lấy từ OAuth flow của tài khoản có quyền đọc Google Ads.';
});
</script>

<?php else: ?>

<?php if ($hasUsdAccount): ?>
<div class="card" style="margin-bottom:20px">
  <div class="card-header">
    <h3>💱 Quy đổi USD → VND</h3>
  </div>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="save_exchange">
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Tỷ giá USDT/VND (Binance)</label>
        <input type="number" name="usdt_rate" class="form-control mono" value="<?= $usdtRate ?>" step="1" style="max-width:180px">
        <div class="form-help">Lấy từ Binance P2P. <?= $rateUpdated ? 'Cập nhật: ' . h($rateUpdated) : '' ?></div>
      </div>
      <div class="form-group">
        <label class="form-label">Hệ số N</label>
        <input type="number" name="multiplier" class="form-control mono" value="<?= $multiplier ?>" step="0.001" style="max-width:140px">
        <div class="form-help">Mặc định 1.095</div>
      </div>
      <div class="form-group">
        <label class="form-label">Kết quả</label>
        <div class="mono" style="padding:8px 0;font-size:16px;font-weight:600">1 USD = <?= number_format($usdtRate * $multiplier, 0, ',', '.') ?>đ</div>
      </div>
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Lưu tỷ giá</button>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <table class="table">
    <thead>
      <tr><th>#</th><th>Nền tảng</th><th>Account ID</th><th>Nhãn</th><th>Tiền</th><th>Có token</th><th>Trạng thái</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($creds as $c): ?>
      <tr>
        <td class="mono text-dim">#<?= $c['id'] ?></td>
        <td>
          <span class="badge <?= $c['platform']==='google'?'badge-accent':'badge-warn' ?>">
            <?= $c['platform']==='google' ? 'Google' : 'Facebook' ?>
          </span>
        </td>
        <td class="mono"><?= h($c['account_id']) ?></td>
        <td><?= h($c['account_label'] ?: '—') ?></td>
        <td><span class="badge <?= ($c['currency'] ?? 'VND') === 'USD' ? 'badge-warn' : 'badge-muted' ?>"><?= h($c['currency'] ?? 'VND') ?></span></td>
        <td><?= $c['refresh_token'] ? '<span class="badge badge-success">✓</span>' : '<span class="badge badge-danger">✗</span>' ?></td>
        <td><?= $c['active'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-muted">Tắt</span>' ?></td>
        <td class="text-right">
          <button type="button" class="btn btn-sm" onclick="testCred(<?= $c['id'] ?>)">Test API</button>
          <button type="button" class="btn btn-sm" onclick="syncCred(<?= $c['id'] ?>)">Sync 7d</button>
          <a href="?edit=<?= $c['id'] ?>" class="btn btn-sm">Sửa</a>
          <form method="post" style="display:inline" onsubmit="return confirm('Xóa credential?')">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $c['id'] ?>">
            <button class="btn btn-sm btn-danger">Xóa</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($creds)): ?>
        <tr><td colspan="8" class="text-muted" style="text-align:center;padding:24px;">Chưa có tài khoản nào. <a href="?new=1">Thêm mới</a>.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<div id="apiResult" class="mt-3"></div>

<script>
async function testCred(id) {
  const box = document.getElementById('apiResult');
  box.innerHTML = '<div class="text-muted">Đang test…</div>';
  const fd = new FormData(); fd.append('id', id);
  const r = await fetch('api/test_credential.php', { method:'POST', body:fd, credentials:'same-origin' });
  const d = await r.json();
  if (!d.ok) { box.innerHTML = '<div class="alert alert-danger">✗ ' + escapeHtml(d.error) + '</div>'; return; }
  box.innerHTML = '<div class="alert alert-success">✓ Kết nối OK</div><pre class="mono" style="background:var(--bg);padding:12px;border-radius:6px;overflow:auto">' + escapeHtml(JSON.stringify(d.data, null, 2)) + '</pre>';
}
async function syncCred(id) {
  const box = document.getElementById('apiResult');
  box.innerHTML = '<div class="text-muted">Đang sync 7 ngày gần nhất…</div>';
  const fd = new FormData(); fd.append('id', id);
  const r = await fetch('api/sync_spend.php', { method:'POST', body:fd, credentials:'same-origin' });
  const d = await r.json();
  if (!d.ok) { box.innerHTML = '<div class="alert alert-danger">✗ ' + escapeHtml(d.error) + '</div>'; return; }
  box.innerHTML = '<div class="alert alert-success">✓ Đã sync ' + d.rows + ' rows</div><pre class="mono" style="background:var(--bg);padding:12px;border-radius:6px;overflow:auto">' + escapeHtml(JSON.stringify(d.preview, null, 2)) + '</pre>';
}
function escapeHtml(s) { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
</script>

<?php endif; ?>

<?php include __DIR__ . '/layout_end.php'; ?>
