<?php
/**
 * FacebookAdsClient
 *
 * Yêu cầu credential:
 *   - refresh_token: dùng làm access_token (long-lived System User token)
 *   - account_id: dạng act_xxxxxxxxx (có prefix act_ hoặc chỉ số — sẽ tự chuẩn hoá)
 *
 * Endpoint: /act_{id}/insights?level=campaign
 * Docs: https://developers.facebook.com/docs/marketing-api/insights
 */
class FacebookAdsClient {

    const API_VERSION = 'v21.0';
    private array $cred;
    private PDO $db;

    public function __construct(PDO $db, array $cred) {
        $this->db = $db;
        $this->cred = $cred;
    }

    private function accountPath(): string {
        $id = ltrim($this->cred['account_id'], 'act_');
        $id = preg_replace('/\D/', '', $id);
        return "act_$id";
    }

    private function token(): string {
        // System User token đặt trong cột refresh_token
        return (string) $this->cred['refresh_token'];
    }

    /**
     * Danh sách ưu tiên loại "kết quả" — quét theo thứ tự, loại nào tìm thấy trước
     * (và có value > 0) sẽ được chọn làm "kết quả chính" của camp đó.
     * Đây đúng là cách Ads Manager tự chọn hiển thị "Results" theo mục tiêu camp.
     */
    const RESULT_PRIORITY = [
        'onsite_conversion.messaging_conversation_started_7d' => 'Tin nhắn',
        'onsite_conversion.messaging_first_reply'             => 'Tin nhắn',
        'onsite_conversion.total_messaging_connection'        => 'Tin nhắn',
        'lead'                                                 => 'Lead',
        'onsite_conversion.lead_grouped'                       => 'Lead',
        'offsite_conversion.fb_pixel_lead'                     => 'Lead',
        'omni_purchase'                                        => 'Lượt mua',
        'purchase'                                             => 'Lượt mua',
        'offsite_conversion.fb_pixel_purchase'                 => 'Lượt mua',
        'onsite_conversion.purchase'                           => 'Lượt mua',
        'landing_page_view'                                    => 'Xem trang đích',
        'link_click'                                           => 'Click liên kết',
    ];

    public function syncSpend(string $from, string $to, ?string $campaignId = null): array {
        $params = [
            'access_token' => $this->token(),
            'level' => 'campaign',
            'fields' => 'campaign_id,campaign_name,spend,impressions,clicks,reach,frequency,actions',
            'time_range' => json_encode(['since' => $from, 'until' => $to]),
            'time_increment' => 1,
            'limit' => 500,
        ];
        if ($campaignId) {
            $params['filtering'] = json_encode([[
                'field' => 'campaign.id',
                'operator' => 'IN',
                'value' => [(string)$campaignId],
            ]]);
        }
        $url = 'https://graph.facebook.com/' . self::API_VERSION . '/' . $this->accountPath() . '/insights?' . http_build_query($params);

        $rows = [];
        while ($url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
            $resp = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($status !== 200) throw new RuntimeException("Facebook API $status: $resp");
            $data = json_decode($resp, true);

            foreach ($data['data'] ?? [] as $r) {
                [$resultCount, $resultLabel] = self::pickResult($r['actions'] ?? []);
                $rows[] = [
                    'campaign_id' => $r['campaign_id'] ?? '',
                    'campaign_name' => $r['campaign_name'] ?? '',
                    'date' => $r['date_start'] ?? '',
                    'spend' => (float)($r['spend'] ?? 0),
                    'impressions' => (int)($r['impressions'] ?? 0),
                    'clicks' => (int)($r['clicks'] ?? 0),
                    'reach' => (int)($r['reach'] ?? 0),
                    'frequency' => (float)($r['frequency'] ?? 0),
                    'result_count' => $resultCount,
                    'result_label' => $resultLabel,
                ];
            }
            $url = $data['paging']['next'] ?? null;
        }

        // Upsert cache
        $up = $this->db->prepare('
          INSERT INTO ads_spend_cache (credential_id, campaign_id, spend_date, spend, impressions, clicks, conversions, reach, frequency, result_label)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE spend = VALUES(spend), impressions = VALUES(impressions), clicks = VALUES(clicks),
            conversions = VALUES(conversions), reach = VALUES(reach), frequency = VALUES(frequency), result_label = VALUES(result_label)
        ');
        foreach ($rows as $r) {
            if (empty($r['date']) || empty($r['campaign_id'])) continue;
            $up->execute([
                $this->cred['id'], $r['campaign_id'], $r['date'], $r['spend'], $r['impressions'], $r['clicks'],
                $r['result_count'], $r['reach'], $r['frequency'], $r['result_label'],
            ]);
        }
        return $rows;
    }

    /**
     * Quét mảng actions theo thứ tự ưu tiên RESULT_PRIORITY, trả về [count, label]
     * của loại đầu tiên có value > 0. Không tìm thấy gì → [0, null].
     */
    private static function pickResult(array $actions): array {
        $byType = [];
        foreach ($actions as $a) {
            $byType[$a['action_type'] ?? ''] = (int)($a['value'] ?? 0);
        }
        foreach (self::RESULT_PRIORITY as $type => $label) {
            if (!empty($byType[$type])) {
                return [$byType[$type], $label];
            }
        }
        return [0, null];
    }

    public function testConnection(): array {
        // Info account + list camp ENABLED/PAUSED
        $accUrl = 'https://graph.facebook.com/' . self::API_VERSION . '/' . $this->accountPath()
                . '?fields=name,account_status,currency&access_token=' . urlencode($this->token());
        $accData = self::get($accUrl);

        $campUrl = 'https://graph.facebook.com/' . self::API_VERSION . '/' . $this->accountPath()
                 . '/campaigns?fields=id,name,status&limit=100&effective_status=["ACTIVE","PAUSED"]&access_token=' . urlencode($this->token());
        $campData = self::get($campUrl);
        $camps = [];
        foreach ($campData['data'] ?? [] as $c) {
            $camps[] = ['id' => $c['id'], 'name' => $c['name'], 'status' => $c['status'] ?? ''];
        }
        return ['ok' => true, 'account' => $accData, 'campaigns' => $camps];
    }

    private static function get(string $url): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
        $resp = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status !== 200) throw new RuntimeException("Facebook API $status: $resp");
        return json_decode($resp, true) ?: [];
    }
}
