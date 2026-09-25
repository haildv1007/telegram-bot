<?php
require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../../lib/GoogleAdsClient.php';
require_once __DIR__ . '/../../lib/FacebookAdsClient.php';

header('Content-Type: application/json; charset=utf-8');

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { echo json_encode(['ok'=>false, 'error'=>'Missing id']); exit; }

$db = get_db();
$stmt = $db->prepare('SELECT * FROM ads_credentials WHERE id = ?');
$stmt->execute([$id]);
$cred = $stmt->fetch();
if (!$cred) { echo json_encode(['ok'=>false, 'error'=>'Credential không tồn tại']); exit; }

try {
    if ($cred['platform'] === 'google') {
        $client = new GoogleAdsClient($db, $cred);
    } else {
        $client = new FacebookAdsClient($db, $cred);
    }
    $result = $client->testConnection();
    echo json_encode(['ok'=>true, 'data'=>$result], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
