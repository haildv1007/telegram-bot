<?php
require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../../lib/GoogleAdsClient.php';
require_once __DIR__ . '/../../lib/FacebookAdsClient.php';

header('Content-Type: application/json; charset=utf-8');

$id = (int)($_POST['id'] ?? 0);
$from = $_POST['from'] ?? date('Y-m-d', strtotime('-7 days'));
$to = $_POST['to'] ?? date('Y-m-d');

if ($id <= 0) { echo json_encode(['ok'=>false, 'error'=>'Missing id']); exit; }

$db = get_db();
$stmt = $db->prepare('SELECT * FROM ads_credentials WHERE id = ?');
$stmt->execute([$id]);
$cred = $stmt->fetch();
if (!$cred) { echo json_encode(['ok'=>false, 'error'=>'Credential không tồn tại']); exit; }

try {
    if ($cred['platform'] === 'google') $client = new GoogleAdsClient($db, $cred);
    else $client = new FacebookAdsClient($db, $cred);
    $rows = $client->syncSpend($from, $to, null);
    echo json_encode([
        'ok' => true,
        'rows' => count($rows),
        'preview' => array_slice($rows, 0, 5),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
