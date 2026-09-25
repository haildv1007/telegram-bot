<?php
/**
 * setwebhook.php
 * CHỈ CHẠY 1 LẦN DUY NHẤT để đăng ký webhook, sau đó nên XOÁ file này khỏi hosting.
 * Cách chạy: mở link https://domain-cua-ban.com/telegram-bot/setwebhook.php trên trình duyệt.
 */

require_once __DIR__ . '/config.php';

// !!! Sửa lại đúng URL thật của webhook.php trên hosting của bạn !!!
$webhookUrl = 'https://taixefood.io.vn/telegram-bot/webhook.php';

$url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/setWebhook';
$data = [
    'url' => $webhookUrl,
    'secret_token' => WEBHOOK_SECRET,
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);

header('Content-Type: text/plain; charset=utf-8');
echo $response;
