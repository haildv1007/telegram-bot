<?php
/**
 * LeadParser — nhận raw text + list rule, trả về channel_id khớp + fields đã extract.
 */
class LeadParser {

    public static function loadRulesFor(PDO $db, ?string $sourceChatId = null): array {
        if ($sourceChatId !== null) {
            $sql = '
              SELECT r.*, rc.channel_id, c.source_chat_id AS ch_chat
              FROM lead_rules r
              JOIN rule_channels rc ON rc.rule_id = r.id
              JOIN ad_channels c ON c.id = rc.channel_id
              WHERE r.active = 1 AND c.active = 1
                AND (c.source_chat_id IS NULL OR c.source_chat_id = ?)
              ORDER BY r.priority DESC, r.id
            ';
            $stmt = $db->prepare($sql);
            $stmt->execute([$sourceChatId]);
        } else {
            $sql = '
              SELECT r.*, rc.channel_id
              FROM lead_rules r
              JOIN rule_channels rc ON rc.rule_id = r.id
              JOIN ad_channels c ON c.id = rc.channel_id
              WHERE r.active = 1 AND c.active = 1
              ORDER BY r.priority DESC, r.id
            ';
            $stmt = $db->query($sql);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array{channel_id:?int, rule_id:?int, fields:array, platform_campaign_id:?string}
     */
    public static function parse(string $text, array $rules): array {
        $campId = self::extractCampaignId($text);
        foreach ($rules as $rule) {
            if (self::matches($text, $rule)) {
                $fields = self::extract($text, $rule);
                return [
                    'channel_id' => (int)$rule['channel_id'],
                    'rule_id' => (int)$rule['id'],
                    'fields' => $fields,
                    'platform_campaign_id' => $campId,
                ];
            }
        }
        return [
            'channel_id' => null,
            'rule_id' => null,
            'fields' => self::defaultExtract($text),
            'platform_campaign_id' => $campId,
        ];
    }

    /**
     * Extract Google/FB campaign id từ URL trong text.
     * Google Ads: gad_campaignid=XXX (mới) hoặc utm_campaign=XXX (số) hoặc gclid → campaign khó, skip
     * Facebook: fb_campaign_id, campaign_id (nếu ladipage đẩy) hoặc utm_campaign=XXX
     */
    public static function extractCampaignId(string $text): ?string {
        // Google Ads modern
        if (preg_match('/gad_campaignid=(\d+)/i', $text, $m)) return $m[1];
        // Facebook (rarely in URL, chỉ 1 số case)
        if (preg_match('/fb_campaign_id=(\d+)/i', $text, $m)) return $m[1];
        if (preg_match('/(?:^|[?&])campaign_id=(\d+)/i', $text, $m)) return $m[1];
        // UTM campaign — nếu là số dài → nhiều khả năng là id
        if (preg_match('/utm_campaign=(\d{6,})/i', $text, $m)) return $m[1];
        return null;
    }

    private static function matches(string $text, array $rule): bool {
        $pattern = $rule['pattern'];
        $type = $rule['match_type'];
        if ($type === 'regex') {
            $delim = '/';
            $safe = str_replace($delim, '\\' . $delim, $pattern);
            return @preg_match($delim . $safe . $delim . 'iu', $text) === 1;
        }
        // contains
        return mb_stripos($text, $pattern) !== false;
    }

    private static function extract(string $text, array $rule): array {
        $out = self::defaultExtract($text);

        foreach (['name'=>'extract_name_regex', 'phone'=>'extract_phone_regex', 'cccd'=>'extract_cccd_regex'] as $k => $col) {
            if (!empty($rule[$col])) {
                if (@preg_match('/' . str_replace('/', '\\/', $rule[$col]) . '/iu', $text, $m)) {
                    $out[$k] = trim($m[1] ?? $m[0]);
                }
            }
        }
        return $out;
    }

    private static function defaultExtract(string $text): array {
        return [
            'name'    => self::firstMatch($text, ['Họ tên', 'Ho ten', 'name', 'Tên khách hàng']),
            'phone'   => self::firstMatch($text, ['SĐT', 'SDT', 'phone', 'Số điện thoại chính chủ', 'Số điện thoại', 'Số Điện Thoại Chính Chủ']),
            'cccd'    => self::firstMatch($text, ['Số CCCD', 'CCCD', 'CMND']),
            'area'    => self::firstMatch($text, ['Khu Vực', 'Khu vuc', 'Tỉnh', 'Tinh', 'Địa chỉ']),
            'vehicle' => self::firstMatch($text, ['Phương Tiện', 'Phuong tien', 'Vehicle']),
            'source'  => self::firstMatch($text, ['Nguồn từ', 'Nguồn', 'Nguon']),
            'ip'      => self::firstMatch($text, ['Địa chỉ IP', 'DB ID', 'IP']),
        ];
    }

    private static function firstMatch(string $text, array $labels): string {
        foreach ($labels as $label) {
            $v = self::field($text, $label);
            if ($v !== '') return $v;
        }
        return '';
    }

    private static function field(string $text, string $label): string {
        // Match label rồi lấy giá trị đến hết dòng đó
        if (preg_match('/' . preg_quote($label, '/') . '\s*:\s*([^\r\n]+)/iu', $text, $m)) {
            return trim($m[1]);
        }
        return '';
    }
}
