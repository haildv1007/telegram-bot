<?php
/**
 * AdsQueryEngine — truy vấn DB trả về số liệu quảng cáo chính xác.
 */
require_once __DIR__ . '/RangeHelper.php';

class AdsQueryEngine {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function execute(array $params): array {
        $action = $params['action'] ?? 'overview';
        switch ($action) {
            case 'overview':    return $this->overview($params);
            case 'channel':     return $this->channelDetail($params);
            case 'group':       return $this->groupDetail($params);
            case 'campaign':    return $this->campaignDetail($params);
            case 'compare':     return $this->compare($params);
            case 'search_terms': return $this->searchTerms($params);
            default:            return $this->overview($params);
        }
    }

    /**
     * Danh sách channels + groups để Gemini biết có gì trong hệ thống.
     */
    public function getSystemContext(): array {
        $channels = $this->db->query('SELECT c.id, c.name, c.group_id, g.name AS group_name FROM ad_channels c LEFT JOIN channel_groups g ON g.id = c.group_id WHERE c.active = 1 ORDER BY c.id')->fetchAll();
        $groups = $this->db->query('SELECT id, name FROM channel_groups ORDER BY id')->fetchAll();

        $campaigns = $this->db->query("
            SELECT c.id, c.name, c.platform_campaign_id, cr.platform, cr.account_label,
                   ac.name AS channel_name
            FROM campaigns c
            LEFT JOIN ads_credentials cr ON cr.id = c.credential_id
            LEFT JOIN channel_credentials cc ON cc.credential_id = c.credential_id
            LEFT JOIN ad_channels ac ON ac.id = cc.channel_id
            ORDER BY c.id
        ")->fetchAll();

        return ['channels' => $channels, 'groups' => $groups, 'campaigns' => $campaigns];
    }

    private function resolveRange(array $params): array {
        $range = $params['range'] ?? 'today';
        $from = $params['from'] ?? null;
        $to = $params['to'] ?? null;
        return RangeHelper::resolve($range, $from, $to);
    }

    private function overview(array $params): array {
        [$startDate, $endDate, $label] = $this->resolveRange($params);
        $platform = $params['platform'] ?? null;

        $startDt = "$startDate 00:00:00";
        $endDt = "$endDate 23:59:59";

        // Leads
        $lq = $this->db->prepare('SELECT dup_key FROM leads WHERE created_at BETWEEN ? AND ?');
        $lq->execute([$startDt, $endDt]);
        $keys = $lq->fetchAll(PDO::FETCH_COLUMN);

        // Spend
        $spendSql = 'SELECT COALESCE(SUM(s.spend),0) FROM ads_spend_cache s';
        $spendParams = [$startDate, $endDate];
        if ($platform) {
            $spendSql .= ' JOIN ads_credentials cr ON cr.id = s.credential_id WHERE cr.platform = ? AND s.spend_date BETWEEN ? AND ?';
            array_unshift($spendParams, $platform);
        } else {
            $spendSql .= ' WHERE s.spend_date BETWEEN ? AND ?';
        }
        $sq = $this->db->prepare($spendSql);
        $sq->execute($spendParams);
        $spend = (float)$sq->fetchColumn();

        $total = count($keys);
        $unique = count(array_unique($keys));

        // Top campaigns
        $topSql = "SELECT COALESCE(camp.name, s.campaign_id) AS name, cr.platform,
                   SUM(s.spend) AS spend, SUM(s.impressions) AS impressions,
                   SUM(s.clicks) AS clicks, SUM(s.conversions) AS conversions
                   FROM ads_spend_cache s
                   LEFT JOIN campaigns camp ON camp.credential_id = s.credential_id AND camp.platform_campaign_id = s.campaign_id
                   LEFT JOIN ads_credentials cr ON cr.id = s.credential_id
                   WHERE s.spend_date BETWEEN ? AND ?";
        $topParams = [$startDate, $endDate];
        if ($platform) {
            $topSql .= ' AND cr.platform = ?';
            $topParams[] = $platform;
        }
        $topSql .= ' GROUP BY s.campaign_id, name, cr.platform ORDER BY spend DESC LIMIT 10';
        $tq = $this->db->prepare($topSql);
        $tq->execute($topParams);
        $topCamps = $tq->fetchAll();

        foreach ($topCamps as &$c) {
            $c['spend'] = (float)$c['spend'];
            $c['impressions'] = (int)$c['impressions'];
            $c['clicks'] = (int)$c['clicks'];
            $c['conversions'] = (int)$c['conversions'];
            $c['ctr'] = $c['impressions'] > 0 ? round(($c['clicks'] / $c['impressions']) * 100, 2) : 0;
            $c['cpc'] = $c['clicks'] > 0 ? round($c['spend'] / $c['clicks']) : 0;
        }
        unset($c);

        return [
            'range' => $label,
            'from' => $startDate,
            'to' => $endDate,
            'leads' => $total,
            'leads_unique' => $unique,
            'spend' => $spend,
            'cpl' => $unique > 0 ? round($spend / $unique) : 0,
            'top_campaigns' => $topCamps,
        ];
    }

    private function channelDetail(array $params): array {
        $channelId = $params['channel_id'] ?? 0;
        $ch = $this->db->prepare('SELECT * FROM ad_channels WHERE id = ?');
        $ch->execute([$channelId]);
        $channel = $ch->fetch();
        if (!$channel) return ['error' => "Không tìm thấy channel #$channelId"];

        [$startDate, $endDate, $label] = $this->resolveRange($params);
        $startDt = "$startDate 00:00:00";
        $endDt = "$endDate 23:59:59";

        $lq = $this->db->prepare('SELECT dup_key FROM leads WHERE channel_id = ? AND created_at BETWEEN ? AND ?');
        $lq->execute([$channelId, $startDt, $endDt]);
        $keys = $lq->fetchAll(PDO::FETCH_COLUMN);

        $spend = $this->channelSpend($channel, $startDate, $endDate);
        $total = count($keys);
        $unique = count(array_unique($keys));

        return [
            'channel' => $channel['name'],
            'range' => $label,
            'leads' => $total,
            'leads_unique' => $unique,
            'spend' => $spend,
            'cpl' => $unique > 0 ? round($spend / $unique) : 0,
        ];
    }

    private function groupDetail(array $params): array {
        $groupId = $params['group_id'] ?? 0;
        $g = $this->db->prepare('SELECT * FROM channel_groups WHERE id = ?');
        $g->execute([$groupId]);
        $group = $g->fetch();
        if (!$group) return ['error' => "Không tìm thấy nhóm #$groupId"];

        [$startDate, $endDate, $label] = $this->resolveRange($params);
        $startDt = "$startDate 00:00:00";
        $endDt = "$endDate 23:59:59";

        $members = $this->db->prepare('SELECT * FROM ad_channels WHERE group_id = ?');
        $members->execute([$groupId]);
        $members = $members->fetchAll();

        $allKeys = [];
        $totalSpend = 0.0;
        $channelStats = [];

        foreach ($members as $ch) {
            $lq = $this->db->prepare('SELECT dup_key FROM leads WHERE channel_id = ? AND created_at BETWEEN ? AND ?');
            $lq->execute([$ch['id'], $startDt, $endDt]);
            $keys = $lq->fetchAll(PDO::FETCH_COLUMN);
            $spend = $this->channelSpend($ch, $startDate, $endDate);

            $t = count($keys);
            $u = count(array_unique($keys));
            $channelStats[] = [
                'name' => $ch['name'],
                'leads' => $t,
                'leads_unique' => $u,
                'spend' => $spend,
                'cpl' => $u > 0 ? round($spend / $u) : 0,
            ];
            $allKeys = array_merge($allKeys, $keys);
            $totalSpend += $spend;
        }

        $total = count($allKeys);
        $unique = count(array_unique($allKeys));

        return [
            'group' => $group['name'],
            'range' => $label,
            'leads' => $total,
            'leads_unique' => $unique,
            'spend' => $totalSpend,
            'cpl' => $unique > 0 ? round($totalSpend / $unique) : 0,
            'channels' => $channelStats,
        ];
    }

    private function campaignDetail(array $params): array {
        $campName = $params['campaign_name'] ?? '';
        $campId = $params['campaign_id'] ?? '';

        $camp = null;
        if ($campId) {
            $q = $this->db->prepare("SELECT c.*, cr.platform FROM campaigns c LEFT JOIN ads_credentials cr ON cr.id = c.credential_id WHERE c.platform_campaign_id = ?");
            $q->execute([$campId]);
            $camp = $q->fetch();
        }
        if (!$camp && $campName) {
            $q = $this->db->prepare("SELECT c.*, cr.platform FROM campaigns c LEFT JOIN ads_credentials cr ON cr.id = c.credential_id WHERE c.name LIKE ?");
            $q->execute(["%$campName%"]);
            $camp = $q->fetch();
        }
        if (!$camp) return ['error' => "Không tìm thấy campaign '$campName'"];

        [$startDate, $endDate, $label] = $this->resolveRange($params);

        $sq = $this->db->prepare("SELECT SUM(spend) AS spend, SUM(impressions) AS impressions, SUM(clicks) AS clicks, SUM(conversions) AS conversions FROM ads_spend_cache WHERE credential_id = ? AND campaign_id = ? AND spend_date BETWEEN ? AND ?");
        $sq->execute([$camp['credential_id'], $camp['platform_campaign_id'], $startDate, $endDate]);
        $stats = $sq->fetch();

        $spend = (float)($stats['spend'] ?? 0);
        $impr = (int)($stats['impressions'] ?? 0);
        $clicks = (int)($stats['clicks'] ?? 0);
        $conv = (int)($stats['conversions'] ?? 0);

        return [
            'campaign' => $camp['name'],
            'platform' => $camp['platform'],
            'range' => $label,
            'spend' => $spend,
            'impressions' => $impr,
            'clicks' => $clicks,
            'conversions' => $conv,
            'ctr' => $impr > 0 ? round(($clicks / $impr) * 100, 2) : 0,
            'cpc' => $clicks > 0 ? round($spend / $clicks) : 0,
            'cpa' => $conv > 0 ? round($spend / $conv) : 0,
        ];
    }

    private function compare(array $params): array {
        $items = $params['items'] ?? [];
        $results = [];
        foreach ($items as $item) {
            $item['range'] = $params['range'] ?? 'today';
            $item['from'] = $params['from'] ?? null;
            $item['to'] = $params['to'] ?? null;
            $results[] = $this->execute($item);
        }
        return ['comparison' => $results];
    }

    private function searchTerms(array $params): array {
        [$startDate, $endDate, $label] = $this->resolveRange($params);
        $campaignId = $params['campaign_id'] ?? null;
        $sort = $params['sort'] ?? 'spend';

        $sql = "SELECT search_term, keyword_text, match_type,
                SUM(spend) AS spend, SUM(impressions) AS impressions,
                SUM(clicks) AS clicks, SUM(conversions) AS conversions
                FROM ads_search_terms_cache
                WHERE spend_date BETWEEN ? AND ?";
        $p = [$startDate, $endDate];
        if ($campaignId) {
            $sql .= " AND campaign_id = ?";
            $p[] = $campaignId;
        }
        $sql .= " GROUP BY search_term, keyword_text, match_type ORDER BY $sort DESC LIMIT 20";

        $q = $this->db->prepare($sql);
        $q->execute($p);
        $rows = $q->fetchAll();

        foreach ($rows as &$r) {
            $r['spend'] = (float)$r['spend'];
            $r['impressions'] = (int)$r['impressions'];
            $r['clicks'] = (int)$r['clicks'];
            $r['conversions'] = (int)$r['conversions'];
            $r['ctr'] = $r['impressions'] > 0 ? round(($r['clicks'] / $r['impressions']) * 100, 2) : 0;
            $r['cpc'] = $r['clicks'] > 0 ? round($r['spend'] / $r['clicks']) : 0;
        }
        unset($r);

        return [
            'range' => $label,
            'from' => $startDate,
            'to' => $endDate,
            'terms' => $rows,
        ];
    }

    private function channelSpend(array $channel, string $startDate, string $endDate): float {
        $credQ = $this->db->prepare('SELECT credential_id FROM channel_credentials WHERE channel_id = ?');
        $credQ->execute([$channel['id']]);
        $credIds = array_map('intval', $credQ->fetchAll(PDO::FETCH_COLUMN));
        if (empty($credIds) && !empty($channel['credential_id'])) $credIds = [(int)$channel['credential_id']];
        if (empty($credIds)) return 0.0;

        $ph = implode(',', array_fill(0, count($credIds), '?'));
        $params = array_merge($credIds, [$startDate, $endDate]);
        $extra = '';
        if (!empty($channel['platform_campaign_id'])) {
            $campIds = array_values(array_filter(array_map('trim', explode(',', $channel['platform_campaign_id']))));
            if ($campIds) {
                $phC = implode(',', array_fill(0, count($campIds), '?'));
                $extra = " AND campaign_id IN ($phC)";
                $params = array_merge($params, $campIds);
            }
        }
        $q = $this->db->prepare("SELECT COALESCE(SUM(spend),0) FROM ads_spend_cache WHERE credential_id IN ($ph) AND spend_date BETWEEN ? AND ?$extra");
        $q->execute($params);
        return (float)$q->fetchColumn();
    }
}
