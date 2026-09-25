<?php
/**
 * Reset password admin. Chạy: php tools/seed_admin.php [username] [password]
 * Mặc định: admin / admin123
 */
require_once __DIR__ . '/../config.php';

$username = $argv[1] ?? 'admin';
$password = $argv[2] ?? 'admin123';

$hash = password_hash($password, PASSWORD_BCRYPT);
$db = get_db();

$stmt = $db->prepare('
  INSERT INTO admin_users (username, password_hash) VALUES (?, ?)
  ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)
');
$stmt->execute([$username, $hash]);

echo "OK — Admin user \"$username\" đã được set với password \"$password\"\n";
