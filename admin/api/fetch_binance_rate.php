<?php
require_once __DIR__ . '/../auth.php';
require_login();
header('Content-Type: application/json');

$db = get_db();
require_once __DIR__ . '/../../lib/CurrencyHelper.php';

$rate = CurrencyHelper::fetchBinanceRate();
if ($rate) {
    CurrencyHelper::saveRate($db, $rate);
    echo json_encode(['ok' => true, 'rate' => $rate]);
} else {
    echo json_encode(['ok' => false, 'error' => 'Không lấy được tỷ giá từ Binance']);
}
