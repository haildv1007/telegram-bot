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
        'offsite_conversion.fb_pixel_custom'                   => 'Đăng ký',
        'offsite_conversion.fb_pixel_subscribe'                => 'Đăng ký',
        'omni_subscribe'                                       => 'Đăng ký',
        'subscribe'                                            => 'Đăng ký',
        'omni_purchase'                                        => 'Lượt mua',
        'purchase'                                             => 'Lượt mua',
        'offsite_conversion.fb_pixel_purchase'                 => 'Lượt mua',
        'onsite_conversion.purchase'                           => 'Lượt mua',
        'landing_page_view'                                    => 'Xem trang đích',
        'link_click'                                           => 'Click liên kết',
    ];

    public function syncSpend(string $from, string $to, ?string $campaignId = null): array {
        $customActions = $this->loadCustomConversionActions();
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
                $messages = self::pickActionCount($r['actions'] ?? [], [
                    'onsite_conversion.messaging_conversation_started_7d',
                    'onsite_conversion.messaging_first_reply',
                    'onsite_conversion.total_messaging_connection',
                ], ['messaging_conversation', 'messaging_first_reply']);
                $purchases = self::pickActionCount($r['actions'] ?? [], [
                    'omni_purchase', 'purchase', 'offsite_conversion.fb_pixel_purchase', 'onsite_conversion.purchase',
                    ...$customActions['purchase'],
                ], ['purchase']);
                $subscribes = self::pickActionCount($r['actions'] ?? [], [
                    'offsite_conversion.fb_pixel_custom',
                    'offsite_conversion.fb_pixel_subscribe', 'onsite_conversion.subscribe', 'omni_subscribe', 'subscribe',
                    'offsite_conversion.fb_pixel_subscription', 'subscription',
                    ...$customActions['subscribe'],
                ], ['subscribe', 'subscription']);
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
                    'purchases' => $purchases,
                    'subscribes' => $subscribes,
                    'messages' => $messages,
                    // Chỉ trả về trong preview sync để chẩn đoán tên action mới từ Meta; không lưu DB.
                    'action_types' => array_values(array_map(fn($a) => [
                        'type' => (string)($a['action_type'] ?? ''),
                        'value' => (int)($a['value'] ?? 0),
                    ], $r['actions'] ?? [])),
                    'mapped_custom_actions' => $customActions,
                ];
            }
            $url = $data['paging']['next'] ?? null;
        }

        // Upsert cache
        $up = $this->db->prepare('
          INSERT INTO ads_spend_cache (credential_id, campaign_id, spend_date, spend, impressions, clicks, conversions, reach, frequency, result_label, purchases, subscribes, messages)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE spend = VALUES(spend), impressions = VALUES(impressions), clicks = VALUES(clicks),
            conversions = VALUES(conversions), reach = VALUES(reach), frequency = VALUES(frequency), result_label = VALUES(result_label),
            purchases = VALUES(purchases), subscribes = VALUES(subscribes), messages = VALUES(messages)
        ');
        foreach ($rows as $r) {
            if (empty($r['date']) || empty($r['campaign_id'])) continue;
            $up->execute([
                $this->cred['id'], $r['campaign_id'], $r['date'], $r['spend'], $r['impressions'], $r['clicks'],
                $r['result_count'], $r['reach'], $r['frequency'], $r['result_label'], $r['purchases'], $r['subscribes'],
                $r['messages'],
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

    /** Lấy một action theo thứ tự ưu tiên; không cộng các alias để tránh đếm trùng cùng một event. */
    private static function pickActionCount(array $actions, array $types, array $keywords = []): int {
        $byType = [];
        foreach ($actions as $a) {
            $byType[$a['action_type'] ?? ''] = (int)($a['value'] ?? 0);
        }
        foreach ($types as $type) {
            if (!empty($byType[$type])) return $byType[$type];
        }
        // Meta có thể bổ sung prefix/namespace mới. Fallback theo tên event nhưng vẫn chỉ
        // lấy một action đầu tiên để không cộng trùng các alias của cùng conversion.
        foreach ($byType as $type => $value) {
            foreach ($keywords as $keyword) {
                if ($value > 0 && stripos($type, $keyword) !== false) return $value;
            }
        }
        return 0;
    }

    /**
     * Meta trả custom conversion trong insights dưới dạng offsite_conversion.custom.<id>.
     * Đọc tên + custom_event_type để biết ID nào là Subscribe/Purchase.
     */
    private function loadCustomConversionActions(): array {
        $mapped = ['subscribe' => [], 'purchase' => []];
        $url = 'https://graph.facebook.com/' . self::API_VERSION . '/' . $this->accountPath()
             . '/customconversions?fields=id,name,custom_event_type&limit=500&access_token=' . urlencode($this->token());

        try {
            while ($url) {
                $data = self::get($url);
                foreach ($data['data'] ?? [] as $conversion) {
                    $id = preg_replace('/\D/', '', (string)($conversion['id'] ?? ''));
                    if ($id === '') continue;

                    $name = mb_strtolower((string)($conversion['name'] ?? ''));
                    $eventType = strtoupper((string)($conversion['custom_event_type'] ?? ''));
                    $actionType = 'offsite_conversion.custom.' . $id;

                    if ($eventType === 'SUBSCRIBE'
                        || str_contains($name, 'subscribe')
                        || str_contains($name, 'subscription')
                        || str_contains($name, 'đăng ký')
                        || str_contains($name, 'dang ky')) {
                        $mapped['subscribe'][] = $actionType;
                    }
                    if ($eventType === 'PURCHASE'
                        || str_contains($name, 'purchase')
                        || str_contains($name, 'lượt mua')
                        || str_contains($name, 'luot mua')) {
                        $mapped['purchase'][] = $actionType;
                    }
                }
                $url = $data['paging']['next'] ?? null;
            }
        } catch (Throwable $e) {
            // Không chặn đồng bộ spend nếu token không có quyền đọc custom conversions.
        }

        $mapped['subscribe'] = array_values(array_unique($mapped['subscribe']));
        $mapped['purchase'] = array_values(array_unique($mapped['purchase']));
        return $mapped;
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
