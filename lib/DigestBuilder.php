<?php
/**
 * DigestBuilder — tổng hợp lũy kế tháng cho 1 NHÓM (channel_groups), gửi thành 1 tin riêng.
 * Cấu trúc: Nhóm > từng Channel thành viên > từng Campaign (đầy đủ chỉ số lũy kế tháng) > Tổng hợp channel > Tổng nhóm.
 */
class DigestBuilder {

    public static function buildForGroup(PDO $db, int $groupId, string $reportDate): ?string {
        $g = $db->prepare('SELECT * FROM channel_groups WHERE id = ?');
        $g->execute([$groupId]);
        $group = $g->fetch();
        if (!$group) return null;

        $members = $db->prepare('SELECT * FROM ad_channels WHERE group_id = ? AND active = 1 ORDER BY id');
        $members->execute([$groupId]);
        $members = $members->fetchAll();
        if (empty($members)) return null;

        $monthStartDate = date('Y-m-01', strtotime($reportDate));
        $rangeStart = $monthStartDate . ' 00:00:00';
        $rangeEnd = $reportDate . ' 23:59:59';

        $lines = [];
        $lines[] = "📅 TỔNG HỢP LŨY KẾ CỦA " . $group['name'] . " THÁNG " . date('m/Y', strtotime($reportDate))
                 . " (đến " . date('d/m', strtotime($reportDate)) . ")";

        $grpSpend = 0.0; $grpKeys = [];

        foreach ($members as $ch) {
            $lines[] = "";
            $lines[] = "🏷 " . $ch['name'] . ":";

            $campRows = self::campaignMtdRows($db, $ch, $rangeStart, $rangeEnd);
            $chSpend = 0.0;

            if (empty($campRows)) {
                $lines[] = "  — Chưa có dữ liệu camp trong tháng.";
                $chSpend = self::manualBudget($db, $ch, $monthStartDate, $reportDate);
            } else {
                foreach ($campRows as $r) {
                    $ctr = $r['impressions'] > 0 ? ($r['clicks'] / $r['impressions']) * 100 : 0;
                    $cpc = $r['clicks'] > 0 ? ($r['spend'] / $r['clicks']) : 0;

                    if ($r['platform'] === 'google') {
                        $cpa = $r['conversions'] > 0 ? round($r['spend'] / $r['conversions']) : 0;
                        $lines[] = "  ▸ " . $r['name'];
                        $lines[] = "     Impr: " . number_format($r['impressions'], 0, ',', '.')
                                 . " · Clicks: " . number_format($r['clicks'], 0, ',', '.')
                                 . " · CTR: " . number_format($ctr, 2, ',', '.') . "%";
                        $lines[] = "     CPC: " . number_format($cpc, 0, ',', '.') . "đ"
                                 . " · Spend: " . number_format($r['spend'], 0, ',', '.') . "đ";
                        $lines[] = "     Conv: {$r['conversions']} · CPA: " . number_format($cpa, 0, ',', '.') . "đ"
                                 . " · Leads: {$r['leads']} (u:{$r['leads_unique']})";
                    } else {
                        $resultLabel = $r['result_label'] ?: null;
                        $resultCount = $r['conversions'];
                        $costPerResult = ($resultLabel && $resultCount > 0) ? round($r['spend'] / $resultCount) : 0;

                        $lines[] = "  ▸ " . $r['name'] . ($resultLabel ? " [{$resultLabel}]" : "");
                        $lines[] = "     Impr: " . number_format($r['impressions'], 0, ',', '.')
                                 . " · Clicks: " . number_format($r['clicks'], 0, ',', '.')
                                 . " · CTR: " . number_format($ctr, 2, ',', '.') . "%";
                        $lines[] = "     CPC: " . number_format($cpc, 0, ',', '.') . "đ"
                                 . " · Spend: " . number_format($r['spend'], 0, ',', '.') . "đ";
                        if ($resultLabel && $resultCount > 0) {
                            $lines[] = "     Kết quả: {$resultCount} ({$resultLabel}) · Chi phí/K.quả: " . number_format($costPerResult, 0, ',', '.') . "đ";
                        } else {
                            $lines[] = "     Kết quả: chưa có dữ liệu";
                        }
                    }
                    $chSpend += $r['spend'];
                }
            }

            // Tổng thực của channel = toàn bộ lead channel này nhận (không phụ thuộc camp có match hay không)
            $allKeys = self::allLeadKeys($db, $ch, $rangeStart, $rangeEnd);
            $chTotal = count($allKeys);
            $chUnique = count(array_unique($allKeys));
            $chCpl = $chUnique > 0 ? round($chSpend / $chUnique) : 0;

            $lines[] = "  — Tổng hợp channel: Leads {$chTotal} (u:{$chUnique}) · CPL " . number_format($chCpl, 0, ',', '.') . "đ"
                     . " · Ngân sách " . number_format($chSpend, 0, ',', '.') . "đ";

            $grpSpend += $chSpend;
            $grpKeys = array_merge($grpKeys, $allKeys);
        }

        $grpTotal = count($grpKeys);
        $grpUnique = count(array_unique($grpKeys));
        $grpCpl = $grpUnique > 0 ? round($grpSpend / $grpUnique) : 0;

        $lines[] = "";
        $lines[] = "📊 TỔNG NHÓM " . $group['name'];
        $lines[] = "— Leads: {$grpTotal} (u:{$grpUnique})";
        $lines[] = "— Ngân sách: " . number_format($grpSpend, 0, ',', '.') . "đ";
        $lines[] = "— CPL trung bình: " . number_format($grpCpl, 0, ',', '.') . "đ";

        return implode("\n", $lines);
    }

    /** Danh sách camp + đầy đủ chỉ số lũy kế tháng (Impr/Clicks/CTR/CPC/Conv hoặc Kết quả) cho 1 channel */
    private static function campaignMtdRows(PDO $db, array $channel, string $start, string $end): array {
        $credQ = $db->prepare('SELECT credential_id FROM channel_credentials WHERE channel_id = ?');
        $credQ->execute([$channel['id']]);
        $credIds = array_map('intval', $credQ->fetchAll(PDO::FETCH_COLUMN));
        if (empty($credIds) && !empty($channel['credential_id'])) $credIds = [(int)$channel['credential_id']];
        if (empty($credIds)) return [];

        $ph = implode(',', array_fill(0, count($credIds), '?'));
        $params = array_merge($credIds, [substr($start, 0, 10), substr($end, 0, 10)]);
        $extra = '';
        if (!empty($channel['platform_campaign_id'])) {
            $campIds = array_values(array_filter(array_map('trim', explode(',', $channel['platform_campaign_id']))));
            if ($campIds) {
                $phC = implode(',', array_fill(0, count($campIds), '?'));
                $extra = " AND s.campaign_id IN ($phC)";
                $params = array_merge($params, $campIds);
            }
        }
        $q = $db->prepare("
          SELECT s.campaign_id, COALESCE(c.name, s.campaign_id) AS name, cr.platform,
                 SUM(s.spend) AS spend, SUM(s.impressions) AS impressions, SUM(s.clicks) AS clicks,
                 SUM(s.conversions) AS conversions, MAX(s.result_label) AS result_label
          FROM ads_spend_cache s
          LEFT JOIN campaigns c ON c.credential_id = s.credential_id AND c.platform_campaign_id = s.campaign_id
          LEFT JOIN ads_credentials cr ON cr.id = s.credential_id
          WHERE s.credential_id IN ($ph) AND s.spend_date BETWEEN ? AND ?$extra
          GROUP BY s.campaign_id, name, cr.platform
          ORDER BY spend DESC
        ");
        $q->execute($params);
        $rows = $q->fetchAll();

        foreach ($rows as &$r) {
            $r['spend'] = (float) $r['spend'];
            $r['impressions'] = (int) $r['impressions'];
            $r['clicks'] = (int) $r['clicks'];
            $r['conversions'] = (int) $r['conversions'];

            $lp = $db->prepare('SELECT dup_key FROM leads WHERE channel_id = ? AND platform_campaign_id = ? AND created_at BETWEEN ? AND ?');
            $lp->execute([$channel['id'], $r['campaign_id'], $start, $end]);
            $keys = $lp->fetchAll(PDO::FETCH_COLUMN);
            $r['leads'] = count($keys);
            $r['leads_unique'] = count(array_unique($keys));
        }
        unset($r);
        return $rows;
    }

    /** Toàn bộ dup_key lead của channel trong khoảng ngày (không lọc theo camp) */
    private static function allLeadKeys(PDO $db, array $channel, string $start, string $end): array {
        $s = $db->prepare('SELECT dup_key FROM leads WHERE channel_id = ? AND created_at BETWEEN ? AND ?');
        $s->execute([$channel['id'], $start, $end]);
        return $s->fetchAll(PDO::FETCH_COLUMN);
    }

    private static function manualBudget(PDO $db, array $channel, string $monthStartDate, string $reportDate): float {
        $bq = $db->prepare('SELECT COALESCE(SUM(budget),0) FROM ad_budget WHERE budget_date BETWEEN ? AND ? AND channel_id = ?');
        $bq->execute([$monthStartDate, $reportDate, $channel['id']]);
        return (float) $bq->fetchColumn();
    }
}
