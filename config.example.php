<?php
date_default_timezone_set('Asia/Ho_Chi_Minh');

define('BOT_TOKEN', 'YOUR_BOT_TOKEN');
define('WEBHOOK_SECRET', 'YOUR_WEBHOOK_SECRET');
define('GEMINI_API_KEY', 'YOUR_GEMINI_API_KEY');

define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', 'your_db_name');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_pass');

define('ADMIN_SESSION_NAME', 'tgbot_admin');

function get_db() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }
    return $pdo;
}
