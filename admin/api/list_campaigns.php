<?php
require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../../lib/GoogleAdsClient.php';
require_once __DIR__ . '/../../lib/FacebookAdsClient.php';

header('Content-Type: application/json; charset=utf-8');

// Accept both credential_id (backward compat) and credential_ids (CSV)
$ids = [];
if (!empty($_GET['credential_ids'])) {
    $ids = array_filter(array_map('intval', explode(',', $_GET['credential_ids'])));
} elseif (!empty($_GET['credential_id'])) {
    $ids = [(int)$_GET['credential_id']];
}
if (empty($ids)) { echo json_encode(['ok'=>false, 'error'=>'Missing credential_ids']); exit; }

$db = get_db();
$ph = implode(',', array_fill(0, count($ids), '?'));
$stmt = $db->prepare("SELECT * FROM ads_credentials WHERE id IN ($ph)");
$stmt->execute($ids);
$creds = $stmt->fetchAll();
if (empty($creds)) { echo json_encode(['ok'=>false, 'error'=>'Không tồn tại']); exit; }

$all = [];
$errors = [];
foreach ($creds as $cred) {
    try {
        if ($cred['platform'] === 'google') $client = new GoogleAdsClient($db, $cred);
        else $client = new FacebookAdsClient($db, $cred);
        $result = $client->testConnection();
        $camps = $result['campaigns'] ?? [];

        // Cache metadata
        if ($camps) {
            $up = $db->prepare('
              INSERT INTO campaigns (credential_id, platform_campaign_id, name, status, synced_at)
              VALUES (?, ?, ?, ?, NOW())
              ON DUPLICATE KEY UPDATE name = VALUES(name), status = VALUES(status), synced_at = NOW()
            ');
            foreach ($camps as $c) {
                $up->execute([$cred['id'], $c['id'], $c['name'], $c['status'] ?? '']);
            }
        }

        foreach ($camps as $c) {
            $all[] = [
                'id' => (string)$c['id'],
                'name' => $c['name'],
                'status' => $c['status'] ?? '',
                'account_label' => $cred['account_label'] ?: $cred['account_id'],
                'credential_id' => (int)$cred['id'],
            ];
        }
    } catch (Throwable $e) {
        $errors[] = "#{$cred['id']} " . ($cred['account_label'] ?: $cred['account_id']) . ": " . $e->getMessage();
    }
}

echo json_encode([
    'ok' => empty($errors) || !empty($all),  // OK nếu có ít nhất 1 account trả về data
    'campaigns' => $all,
    'errors' => $errors,
], JSON_UNESCAPED_UNICODE);
