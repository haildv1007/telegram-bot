<?php
require_once __DIR__ . '/auth.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username === '' || $password === '') {
        $error = 'Vui lòng nhập đủ thông tin.';
    } else {
        $db = get_db();
        $stmt = $db->prepare('SELECT * FROM admin_users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['admin_user_id'] = $user['id'];
            $_SESSION['admin_username'] = $user['username'];
            $db->prepare('UPDATE admin_users SET last_login = NOW() WHERE id = ?')
               ->execute([$user['id']]);
            header('Location: index.php');
            exit;
        }
        $error = 'Sai tài khoản hoặc mật khẩu.';
    }
}
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Đăng nhập — Ads Bot Admin</title>
  <link rel="stylesheet" href="assets/admin.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
</head>
<body>
  <div class="login-wrapper">
    <div class="login-card">
      <h1><span class="sidebar-brand-dot" style="display:inline-block;vertical-align:middle;margin-right:8px;"></span>Ads Bot Admin</h1>
      <p class="subtitle">Đăng nhập để quản lý cấu hình</p>

      <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
      <?php endif; ?>

      <form method="post" autocomplete="off">
        <div class="form-group">
          <label class="form-label">Tài khoản</label>
          <input type="text" name="username" class="form-control" autofocus required>
        </div>
        <div class="form-group">
          <label class="form-label">Mật khẩu</label>
          <input type="password" name="password" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;">Đăng nhập</button>
      </form>
    </div>
  </div>
</body>
</html>
