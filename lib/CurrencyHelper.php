<?php
class CurrencyHelper {

    public static function getSettings(PDO $db): array {
        $q = $db->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('usdt_vnd_rate','exchange_rate_multiplier','usdt_vnd_rate_updated')");
        $settings = [];
        foreach ($q->fetchAll() as $r) $settings[$r['setting_key']] = $r['setting_value'];
        return [
            'usdt_vnd_rate' => (float)($settings['usdt_vnd_rate'] ?? 25500),
            'multiplier' => (float)($settings['exchange_rate_multiplier'] ?? 1.095),
            'updated_at' => $settings['usdt_vnd_rate_updated'] ?? '',
        ];
    }

    public static function fetchBinanceRate(): ?float {
        // Binance P2P: lấy top 5 giá BUY USDT bằng VND, trung bình
        $ch = curl_init('https://p2p.binance.com/bapi/c2c/v2/friendly/c2c/adv/search');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'asset' => 'USDT',
                'fiat' => 'VND',
                'tradeType' => 'BUY',
                'page' => 1,
                'rows' => 10,
                'payTypes' => [],
            ]),
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($resp, true);
        if (!empty($data['data'])) {
            $prices = array_map(fn($ad) => (float)$ad['adv']['price'], $data['data']);
            if ($prices) return round(array_sum($prices) / count($prices));
        }

        // Fallback: Binance convert API
        $ch = curl_init('https://www.binance.com/bapi/asset/v1/public/asset-service/product/currency');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($resp, true);
        if (!empty($data['data'])) {
            foreach ($data['data'] as $item) {
                if (($item['pair'] ?? '') === 'USDT_VND') return (float)$item['rate'];
            }
        }
        return null;
    }

    public static function convertToVnd(float $amountUsd, PDO $db): float {
        $s = self::getSettings($db);
        return $amountUsd * $s['usdt_vnd_rate'] * $s['multiplier'];
    }

    public static function getRate(PDO $db): float {
        $s = self::getSettings($db);
        return $s['usdt_vnd_rate'] * $s['multiplier'];
    }

    public static function saveRate(PDO $db, float $rate): void {
        $up = $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('usdt_vnd_rate', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $up->execute([$rate, $rate]);
        $now = date('Y-m-d H:i:s');
        $up2 = $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('usdt_vnd_rate_updated', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $up2->execute([$now, $now]);
    }
}
