<?php
/**
 * GoogleAdsClient
 *
 * Yêu cầu credential:
 *   - developer_token
 *   - client_id, client_secret (OAuth desktop hoặc web)
 *   - refresh_token (của user có quyền đọc customer)
 *   - account_id (customer_id, dạng 10 chữ số, không dấu gạch)
 *   - (optional) login_customer_id nếu tài khoản là MCC - hiện tại lấy từ config sau
 *
 * Endpoint: v17 search
 * Docs: https://developers.google.com/google-ads/api/rest/reference/rest/v17/customers.googleAds/search
 */
class GoogleAdsClient {

    const API_VERSION = 'v25';
    private array $cred;
    private PDO $db;

    public function __construct(PDO $db, array $cred) {
        $this->db = $db;
        $this->cred = $cred;
    }

    /** Trả về access_token (dùng cache nếu còn hạn) */
    public function getAccessToken(): string {
        if (!empty($this->cred['access_token']) &&
            !empty($this->cred['token_expires_at']) &&
            strtotime($this->cred['token_expires_at']) > time() + 60) {
            return $this->cred['access_token'];
        }
        // Refresh
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $this->cred['client_id'],
                'client_secret' => $this->cred['client_secret'],
                'refresh_token' => $this->cred['refresh_token'],
                'grant_type' => 'refresh_token',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $resp = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($resp, true);
        if ($status !== 200 || empty($data['access_token'])) {
            throw new RuntimeException('OAuth refresh failed: ' . ($data['error_description'] ?? $resp));
        }
        $expiresAt = date('Y-m-d H:i:s', time() + ($data['expires_in'] ?? 3600));
        $this->cred['access_token'] = $data['access_token'];
        $this->cred['token_expires_at'] = $expiresAt;
        // Persist
        $up = $this->db->prepare('UPDATE ads_credentials SET access_token = ?, token_expires_at = ? WHERE id = ?');
        $up->execute([$data['access_token'], $expiresAt, $this->cred['id']]);
        return $data['access_token'];
    }

    /**
     * Kéo spend theo ngày cho khoảng $from..$to (YYYY-MM-DD).
     * Nếu $campaignId truyền vào → chỉ lấy 1 camp. Nếu null → tất cả camp.
     * Ghi vào ads_spend_cache và return danh sách rows.
     */
    public function syncSpend(string $from, string $to, ?string $campaignId = null): array {
        $token = $this->getAccessToken();
        $customerId = preg_replace('/\D/', '', $this->cred['account_id']);
        if (strlen($customerId) !== 10) {
            throw new RuntimeException('account_id phải là customer_id 10 số (không dấu gạch)');
        }

        $loginCid = !empty($this->cred['login_customer_id']) ? preg_replace('/\D/', '', $this->cred['login_customer_id']) : null;

        $where = "segments.date BETWEEN '$from' AND '$to'";
        if ($campaignId) $where .= " AND campaign.id = " . (int)$campaignId;

        $query = "
          SELECT
            campaign.id,
            campaign.name,
            segments.date,
            metrics.cost_micros,
            metrics.impressions,
            metrics.clicks,
            metrics.conversions
          FROM campaign
          WHERE $where
        ";

        $headers = [
            'Authorization: Bearer ' . $token,
            'developer-token: ' . $this->cred['developer_token'],
            'Content-Type: application/json',
        ];
        if ($loginCid) $headers[] = 'login-customer-id: ' . $loginCid;

        $ch = curl_init("https://googleads.googleapis.com/" . self::API_VERSION . "/customers/$customerId/googleAds:search");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode(['query' => $query]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $resp = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status !== 200) throw new RuntimeException("Google Ads API $status: $resp");
        $data = json_decode($resp, true);
        $results = $data['results'] ?? [];

        // Aggregate theo (campaign_id, date)
        $bucket = [];
        foreach ($results as $r) {
            $cid = (string)($r['campaign']['id'] ?? '');
            $date = $r['segments']['date'] ?? '';
            if (!$cid || !$date) continue;
            $key = "$cid|$date";
            if (!isset($bucket[$key])) {
                $bucket[$key] = ['cid'=>$cid, 'date'=>$date, 'name'=>$r['campaign']['name'] ?? '', 'cost'=>0, 'impr'=>0, 'clk'=>0, 'conv'=>0];
            }
            $bucket[$key]['cost'] += (int)($r['metrics']['costMicros'] ?? 0);
            $bucket[$key]['impr'] += (int)($r['metrics']['impressions'] ?? 0);
            $bucket[$key]['clk'] += (int)($r['metrics']['clicks'] ?? 0);
            $bucket[$key]['conv'] += (float)($r['metrics']['conversions'] ?? 0);
        }

        // Upsert cache
        $up = $this->db->prepare('
          INSERT INTO ads_spend_cache (credential_id, campaign_id, spend_date, spend, impressions, clicks, conversions)
          VALUES (?, ?, ?, ?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE spend = VALUES(spend), impressions = VALUES(impressions), clicks = VALUES(clicks), conversions = VALUES(conversions)
        ');
        $rows = [];
        foreach ($bucket as $b) {
            $spend = $b['cost'] / 1_000_000;
            $up->execute([$this->cred['id'], $b['cid'], $b['date'], $spend, $b['impr'], $b['clk'], (int)round($b['conv'])]);
            $rows[] = [
                'campaign_id' => $b['cid'],
                'campaign_name' => $b['name'],
                'date' => $b['date'],
                'spend' => $spend,
                'impressions' => $b['impr'],
                'clicks' => $b['clk'],
            ];
        }
        return $rows;
    }

    /**
     * Sync search terms cho khoảng ngày. Lưu vào ads_search_terms_cache.
     */
    public function syncSearchTerms(string $from, string $to, ?string $campaignId = null): array {
        $token = $this->getAccessToken();
        $customerId = preg_replace('/\D/', '', $this->cred['account_id']);
        $loginCid = !empty($this->cred['login_customer_id']) ? preg_replace('/\D/', '', $this->cred['login_customer_id']) : null;

        $where = "segments.date BETWEEN '$from' AND '$to'";
        if ($campaignId) $where .= " AND campaign.id = " . (int)$campaignId;

        $query = "
          SELECT
            campaign.id,
            search_term_view.search_term,
            segments.keyword.info.text,
            segments.keyword.info.match_type,
            segments.date,
            metrics.cost_micros,
            metrics.impressions,
            metrics.clicks,
            metrics.conversions
          FROM search_term_view
          WHERE $where AND metrics.impressions > 0
          ORDER BY metrics.cost_micros DESC
          LIMIT 1000
        ";

        $headers = [
            'Authorization: Bearer ' . $token,
            'developer-token: ' . $this->cred['developer_token'],
            'Content-Type: application/json',
        ];
        if ($loginCid) $headers[] = 'login-customer-id: ' . $loginCid;

        $ch = curl_init("https://googleads.googleapis.com/" . self::API_VERSION . "/customers/$customerId/googleAds:search");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode(['query' => $query]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $resp = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status !== 200) throw new RuntimeException("Google Ads search_term API $status: $resp");
        $data = json_decode($resp, true);
        $results = $data['results'] ?? [];

        $up = $this->db->prepare('
          INSERT INTO ads_search_terms_cache (credential_id, campaign_id, search_term, keyword_text, match_type, spend_date, spend, impressions, clicks, conversions)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE spend = VALUES(spend), impressions = VALUES(impressions), clicks = VALUES(clicks), conversions = VALUES(conversions), keyword_text = VALUES(keyword_text), match_type = VALUES(match_type)
        ');

        $rows = [];
        foreach ($results as $r) {
            $cid = (string)($r['campaign']['id'] ?? '');
            $term = $r['searchTermView']['searchTerm'] ?? '';
            $kwText = $r['segments']['keyword']['info']['text'] ?? '';
            $matchType = $r['segments']['keyword']['info']['matchType'] ?? '';
            $date = $r['segments']['date'] ?? '';
            $spend = ((int)($r['metrics']['costMicros'] ?? 0)) / 1_000_000;
            $impr = (int)($r['metrics']['impressions'] ?? 0);
            $clk = (int)($r['metrics']['clicks'] ?? 0);
            $conv = (int)round((float)($r['metrics']['conversions'] ?? 0));

            if (!$cid || !$term || !$date) continue;

            $up->execute([$this->cred['id'], $cid, $term, $kwText, $matchType, $date, $spend, $impr, $clk, $conv]);
            $rows[] = ['campaign_id' => $cid, 'search_term' => $term, 'keyword' => $kwText, 'date' => $date, 'spend' => $spend, 'clicks' => $clk];
        }
        return $rows;
    }

    /** Test connect: gọi getMe kiểu — list campaign */
    public function testConnection(): array {
        $token = $this->getAccessToken();
        $customerId = preg_replace('/\D/', '', $this->cred['account_id']);
        $loginCid = !empty($this->cred['login_customer_id']) ? preg_replace('/\D/', '', $this->cred['login_customer_id']) : null;

        $headers = [
            'Authorization: Bearer ' . $token,
            'developer-token: ' . $this->cred['developer_token'],
            'Content-Type: application/json',
        ];
        if ($loginCid) $headers[] = 'login-customer-id: ' . $loginCid;

        $ch = curl_init("https://googleads.googleapis.com/" . self::API_VERSION . "/customers/$customerId/googleAds:search");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode(['query' => "SELECT campaign.id, campaign.name, campaign.status FROM campaign WHERE campaign.status IN ('ENABLED','PAUSED') ORDER BY campaign.status, campaign.id DESC LIMIT 50"]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $resp = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status !== 200) throw new RuntimeException("Google Ads test $status: $resp");
        $data = json_decode($resp, true);
        $camps = [];
        foreach ($data['results'] ?? [] as $r) {
            $camps[] = [
                'id' => $r['campaign']['id'],
                'name' => $r['campaign']['name'],
                'status' => $r['campaign']['status'] ?? '',
            ];
        }
        return ['ok' => true, 'campaigns' => $camps];
    }
}
