<?php
/**
 * ReportBuilder — build nội dung báo cáo cho 1 channel + 1 mốc thời gian.
 *
 * Layout:
 *   1. Header (loại + ngày + channel name)
 *   2. Breakdown theo từng TKQC (tài khoản Ads) — mỗi TKQC 1 khối, bên trong list từng camp
 *      - Google: Impr/Clicks/CTR, CPC/Spend, Conv/CPA (Google conversion), Leads/CPL (track qua Telegram)
 *      - Facebook: Impr/Clicks/CTR/Reach/Freq, CPC/Spend, Kết quả theo đúng loại (không cộng lẫn loại)
 *   3. Tổng kết
 *      - Có lead tracking (Google hoặc có leads>0) → Tổng lead + CPL
 *      - FB-only, không có lead → chỉ Tổng ngân sách + Kết quả theo từng loại (group riêng, không cộng lẫn)
 */
class ReportBuilder {

    public static function build(PDO $db, array $channel, string $reportType, string $reportDate): string {
        $isProgress = ($reportType === 'progress');
        $dayStart = $reportDate . ' 00:00:00';
        $dayEnd = $isProgress ? date('Y-m-d H:i:s') : $reportDate . ' 23:59:59';
        if ($isProgress && strtotime($dayEnd) < strtotime($dayStart)) $dayEnd = $reportDate . ' 23:59:59';

        // ---- Leads (theo channel) ----
        $s = $db->prepare('SELECT dup_key FROM leads WHERE channel_id = ? AND created_at BETWEEN ? AND ?');
        $s->execute([$channel['id'], $dayStart, $dayEnd]);
        $keys = $s->fetchAll(PDO::FETCH_COLUMN);
        $total = count($keys);
        $unique = count(array_unique($keys));

        $dupHistory = 0;
        if (!$isProgress && $unique > 0) {
            $historyStart = date('Y-m-d 00:00:00', strtotime("-21 days", strtotime($dayStart)));
            $uniqKeys = array_values(array_unique($keys));
            $ph = implode(',', array_fill(0, count($uniqKeys), '?'));
            $q = "SELECT COUNT(DISTINCT dup_key) FROM leads
                  WHERE channel_id = ? AND created_at >= ? AND created_at < ? AND dup_key IN ($ph)";
            $stmt = $db->prepare($q);
            $stmt->execute(array_merge([$channel['id'], $historyStart, $dayStart], $uniqKeys));
            $dupHistory = (int) $stmt->fetchColumn();
        }
        $newLeads = $unique - $dupHistory;

        // ---- Load credentials linked (channel_credentials, fallback credential_id cũ) ----
        $credQ = $db->prepare('SELECT cr.* FROM channel_credentials cc JOIN ads_credentials cr ON cr.id = cc.credential_id WHERE cc.channel_id = ? ORDER BY cr.id');
        $credQ->execute([$channel['id']]);
        $linkedCreds = $credQ->fetchAll();
        if (empty($linkedCreds) && !empty($channel['credential_id'])) {
            $c = $db->prepare('SELECT * FROM ads_credentials WHERE id = ?');
            $c->execute([$channel['credential_id']]);
            $fallback = $c->fetch();
            if ($fallback) $linkedCreds = [$fallback];
        }

        $campFilter = [];
        if (!empty($channel['platform_campaign_id'])) {
            $campFilter = array_values(array_filter(array_map('trim', explode(',', $channel['platform_campaign_id']))));
        }

        // ---- Load ads rows theo từng credential ----
        require_once __DIR__ . '/CurrencyHelper.php';
        $exRate = CurrencyHelper::getRate($db);

        $spendTotal = 0.0;
        $spendSource = 'manual';
        $groups = []; // credential_id => ['cred'=>row, 'rows'=>[...]]

        foreach ($linkedCreds as $cred) {
            $params = [$cred['id'], $reportDate];
            $extra = '';
            if (!empty($campFilter)) {
                $ph = implode(',', array_fill(0, count($campFilter), '?'));
                $extra = " AND s.campaign_id IN ($ph)";
                $params = array_merge($params, $campFilter);
            }
            $q = "SELECT s.*, COALESCE(c.name, s.campaign_id) AS camp_name, c.status AS camp_status
                  FROM ads_spend_cache s
                  LEFT JOIN campaigns c ON c.credential_id = s.credential_id AND c.platform_campaign_id = s.campaign_id
                  WHERE s.credential_id = ? AND s.spend_date = ?$extra
                  ORDER BY s.spend DESC";
            $stmt = $db->prepare($q);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            // Enrich lead count theo camp (chỉ có ý nghĩa nếu channel có source đọc lead)
            foreach ($rows as &$row) {
                $lp = $db->prepare('SELECT COUNT(*) t, COUNT(DISTINCT dup_key) u FROM leads
                                    WHERE channel_id = ? AND platform_campaign_id = ? AND created_at BETWEEN ? AND ?');
                $lp->execute([$channel['id'], $row['campaign_id'], $dayStart, $dayEnd]);
                $ld = $lp->fetch();
                $row['leads'] = (int) $ld['t'];
                $row['leads_unique'] = (int) $ld['u'];
                $rawSpend = (float) $row['spend'];
                if (($cred['currency'] ?? 'VND') === 'USD') {
                    $rawSpend = $rawSpend * $exRate;
                    $row['spend'] = $rawSpend;
                }
                $spendTotal += $rawSpend;
            }
            unset($row);

            if (!empty($rows)) {
                $groups[] = ['cred' => $cred, 'rows' => $rows];
            }
        }
        if ($spendTotal > 0) $spendSource = 'ads_api';

        // Leads chưa gán camp
        $lp = $db->prepare('SELECT COUNT(*) t, COUNT(DISTINCT dup_key) u FROM leads
                            WHERE channel_id = ? AND (platform_campaign_id IS NULL OR platform_campaign_id = "")
                              AND created_at BETWEEN ? AND ?');
        $lp->execute([$channel['id'], $dayStart, $dayEnd]);
        $orphan = $lp->fetch();
        $orphanLeads = (int) $orphan['t'];
        $orphanUnique = (int) $orphan['u'];

        // Fallback manual budget nếu không có ads data
        if ($spendTotal <= 0) {
            $stmt = $db->prepare('SELECT COALESCE(SUM(budget),0) FROM ad_budget WHERE budget_date = ? AND channel_id = ?');
            $stmt->execute([$reportDate, $channel['id']]);
            $spendTotal = (float) $stmt->fetchColumn();
            $spendSource = 'manual';
        }

        // Platform mix
        $platforms = array_unique(array_map(fn($g) => $g['cred']['platform'], $groups));
        $platformMix = count($platforms) === 0 ? null : (count($platforms) === 1 ? $platforms[0] : 'mixed');

        $cpl = $total > 0 ? round($spendTotal / $total) : 0;
        $cplu = $unique > 0 ? round($spendTotal / $unique) : 0;

        // ==================== COMPOSE ====================
        $header = $isProgress ? "📊 TIẾN ĐỘ" : "📊 TỔNG KẾT";
        $timeInfo = $isProgress ? " đến " . date('H:i') : "";
        $dateLabel = date('d/m/Y', strtotime($reportDate));

        $lines = [];
        $lines[] = "{$header} {$dateLabel}{$timeInfo}";
        $lines[] = "🏷 Channel: " . $channel['name'];

        // ---- Breakdown theo TKQC ----
        foreach ($groups as $g) {
            $cred = $g['cred'];
            $icon = $cred['platform'] === 'google' ? '🟢' : '🔵';
            $lines[] = "";
            $lines[] = "{$icon} TKQC: " . ($cred['account_label'] ?: $cred['account_id']);

            foreach ($g['rows'] as $r) {
                $ctr = ((int)$r['impressions']) > 0 ? ((float)$r['clicks'] / (float)$r['impressions']) * 100 : 0;
                $cpc = ((int)$r['clicks']) > 0 ? ((float)$r['spend'] / (float)$r['clicks']) : 0;

                if ($cred['platform'] === 'google') {
                    $lines[] = "▸ " . $r['camp_name'];
                    $lines[] = "   Impr: " . number_format((int)$r['impressions'], 0, ',', '.')
                             . " · Clicks: " . number_format((int)$r['clicks'], 0, ',', '.')
                             . " · CTR: " . number_format($ctr, 2, ',', '.') . "%";
                    $lines[] = "   CPC: " . number_format($cpc, 0, ',', '.') . "đ"
                             . " · Spend: " . number_format((float)$r['spend'], 0, ',', '.') . "đ";

                    $conv = (int)$r['conversions'];
                    $cpa = $conv > 0 ? round((float)$r['spend'] / $conv) : 0;
                    $lines[] = "   Conv: {$conv} · CPA: " . number_format($cpa, 0, ',', '.') . "đ";

                    $leads = (int)($r['leads'] ?? 0);
                    $leadsU = (int)($r['leads_unique'] ?? 0);
                    $campCpl = $leads > 0 ? round((float)$r['spend'] / $leads) : 0;
                    $lines[] = "   Leads: {$leads} (u: {$leadsU}) · CPL: " . number_format($campCpl, 0, ',', '.') . "đ";
                } else {
                    // Facebook
                    $resultLabel = $r['result_label'] ?: null;
                    $resultCount = (int)$r['conversions'];

                    $lines[] = "▸ " . $r['camp_name'] . ($resultLabel ? " [{$resultLabel}]" : "");
                    $lines[] = "   Impr: " . number_format((int)$r['impressions'], 0, ',', '.')
                             . " · Clicks: " . number_format((int)$r['clicks'], 0, ',', '.')
                             . " · CTR: " . number_format($ctr, 2, ',', '.') . "%"
                             . " · Reach: " . number_format((int)($r['reach'] ?? 0), 0, ',', '.')
                             . " · Tần suất: " . number_format((float)($r['frequency'] ?? 0), 2, ',', '.');
                    $lines[] = "   CPC: " . number_format($cpc, 0, ',', '.') . "đ"
                             . " · Spend: " . number_format((float)$r['spend'], 0, ',', '.') . "đ";

                    if ($resultLabel && $resultCount > 0) {
                        $costPerResult = round((float)$r['spend'] / $resultCount);
                        $lines[] = "   Kết quả: {$resultCount} ({$resultLabel}) · Chi phí/K.quả: " . number_format($costPerResult, 0, ',', '.') . "đ";
                    } else {
                        $lines[] = "   Kết quả: chưa có dữ liệu";
                    }

                    // Chỉ hiện Leads nếu thực sự có lead track được (hiếm với FB, nhưng không ẩn nếu có)
                    $leads = (int)($r['leads'] ?? 0);
                    if ($leads > 0) {
                        $leadsU = (int)($r['leads_unique'] ?? 0);
                        $campCpl = round((float)$r['spend'] / $leads);
                        $lines[] = "   Leads: {$leads} (u: {$leadsU}) · CPL: " . number_format($campCpl, 0, ',', '.') . "đ";
                    }
                }
            }
        }

        if (!empty($orphanLeads)) {
            $lines[] = "";
            $lines[] = "▸ (lead chưa gán camp)";
            $lines[] = "   Leads: {$orphanLeads} (u: {$orphanUnique})";
        }

        // ---- Tổng kết ----
        $lines[] = "";
        $lines[] = "📊 Tổng Channel: " . $channel['name'];

        $showLeadBlock = ($total > 0) || $platformMix !== 'facebook';

        if ($showLeadBlock) {
            $lines[] = "— Tổng lead: {$total}";
            $lines[] = "— Lead unique: {$unique}";
            if (!$isProgress) {
                $lines[] = "— Trùng 21 ngày trước: {$dupHistory}";
                $lines[] = "— Lead hoàn toàn mới: {$newLeads}";
            }
            $lines[] = "— Ngân sách" . ($isProgress ? " (tạm)" : "") . ": " . number_format($spendTotal, 0, ',', '.') . "đ"
                     . ($spendSource === 'ads_api' ? ' [Ads]' : ' [manual]');
            $lines[] = "— Chi phí/lead: " . number_format($cpl, 0, ',', '.') . "đ";
            $lines[] = "— Chi phí/lead unique: " . number_format($cplu, 0, ',', '.') . "đ";
        } else {
            // FB-only, không có lead → chỉ báo ngân sách + kết quả theo từng loại (không cộng lẫn loại)
            $lines[] = "— Tổng ngân sách" . ($isProgress ? " (tạm)" : "") . ": " . number_format($spendTotal, 0, ',', '.') . "đ"
                     . ($spendSource === 'ads_api' ? ' [Ads]' : ' [manual]');

            $resultGroups = []; // label => ['spend'=>, 'count'=>]
            foreach ($groups as $g) {
                foreach ($g['rows'] as $r) {
                    $label = $r['result_label'] ?? null;
                    $cnt = (int)$r['conversions'];
                    if (!$label || $cnt <= 0) continue;
                    if (!isset($resultGroups[$label])) $resultGroups[$label] = ['spend' => 0.0, 'count' => 0];
                    $resultGroups[$label]['spend'] += (float)$r['spend'];
                    $resultGroups[$label]['count'] += $cnt;
                }
            }
            foreach ($resultGroups as $label => $d) {
                $avgCost = $d['count'] > 0 ? round($d['spend'] / $d['count']) : 0;
                $lines[] = "— Kết quả ({$label}): {$d['count']} · Chi phí/K.quả TB: " . number_format($avgCost, 0, ',', '.') . "đ";
            }
            if (empty($resultGroups)) {
                $lines[] = "— Chưa có dữ liệu kết quả (camp chưa có action nào được ghi nhận).";
            }
        }

        if ($isProgress) {
            $lines[] = "";
            $lines[] = "⏳ Số liệu tạm tính, spend có độ trễ 1-3h.";
        } else {
            // ---- Lũy kế tháng (gộp nhóm cha-con nếu có) ----
            $mtd = self::monthToDate($db, $channel, $reportDate);
            if ($mtd) {
                $lines[] = "";
                $lines[] = "📅 Lũy kế tháng " . date('m/Y', strtotime($reportDate))
                         . ($mtd['groupLabel'] ? " (nhóm: {$mtd['groupLabel']})" : "")
                         . " — từ 01 đến " . date('d/m', strtotime($reportDate));
                $lines[] = "— Tổng lead: {$mtd['total']} (unique: {$mtd['unique']})";
                $lines[] = "— Tổng ngân sách: " . number_format($mtd['spend'], 0, ',', '.') . "đ";
                $lines[] = "— Chi phí/lead TB: " . number_format($mtd['cpl'], 0, ',', '.') . "đ";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Tính lũy kế từ đầu tháng đến reportDate cho channel — nếu channel thuộc 1 channel_group,
     * gộp tất cả channel cùng nhóm đó lại.
     */
    private static function monthToDate(PDO $db, array $channel, string $reportDate): ?array {
        $groupId = $channel['group_id'] ?? null;

        if ($groupId) {
            $q = $db->prepare('SELECT id, name FROM ad_channels WHERE group_id = ?');
            $q->execute([$groupId]);
            $members = $q->fetchAll();
            $g = $db->prepare('SELECT name FROM channel_groups WHERE id = ?');
            $g->execute([$groupId]);
            $groupLabel = $g->fetchColumn();
        } else {
            $members = [['id' => $channel['id'], 'name' => $channel['name']]];
            $groupLabel = null;
        }
        if (empty($members)) return null;

        $monthStart = date('Y-m-01 00:00:00', strtotime($reportDate));
        $monthEnd = $reportDate . ' 23:59:59';

        $totalSpend = 0.0; $totalLeads = 0; $allDupKeys = [];

        foreach ($members as $m) {
            $mStmt = $db->prepare('SELECT * FROM ad_channels WHERE id = ?');
            $mStmt->execute([$m['id']]);
            $mChannel = $mStmt->fetch();
            if (!$mChannel) continue;

            [$spend, $keys] = self::spendAndLeadKeysForRange($db, $mChannel, $monthStart, $monthEnd);
            $totalSpend += $spend;
            $allDupKeys = array_merge($allDupKeys, $keys);
        }

        $total = count($allDupKeys);
        $unique = count(array_unique($allDupKeys));
        $cpl = $unique > 0 ? round($totalSpend / $unique) : 0;

        return [
            'total' => $total,
            'unique' => $unique,
            'spend' => $totalSpend,
            'cpl' => $cpl,
            'groupLabel' => $groupLabel,
        ];
    }

    /**
     * Trả về [spend, dupKeys[]] cho 1 channel trong khoảng ngày cho trước.
     * Dùng chung logic filter credential+campaign như trong build().
     */
    private static function spendAndLeadKeysForRange(PDO $db, array $channel, string $start, string $end): array {
        $s = $db->prepare('SELECT dup_key FROM leads WHERE channel_id = ? AND created_at BETWEEN ? AND ?');
        $s->execute([$channel['id'], $start, $end]);
        $keys = $s->fetchAll(PDO::FETCH_COLUMN);

        $credQ = $db->prepare('SELECT credential_id FROM channel_credentials WHERE channel_id = ?');
        $credQ->execute([$channel['id']]);
        $credIds = array_map('intval', $credQ->fetchAll(PDO::FETCH_COLUMN));
        if (empty($credIds) && !empty($channel['credential_id'])) $credIds = [(int)$channel['credential_id']];

        $spend = 0.0;
        if (!empty($credIds)) {
            $ph = implode(',', array_fill(0, count($credIds), '?'));
            $params = array_merge($credIds, [substr($start, 0, 10), substr($end, 0, 10)]);
            $extra = '';
            if (!empty($channel['platform_campaign_id'])) {
                $campIds = array_values(array_filter(array_map('trim', explode(',', $channel['platform_campaign_id']))));
                if ($campIds) {
                    $phC = implode(',', array_fill(0, count($campIds), '?'));
                    $extra = " AND campaign_id IN ($phC)";
                    $params = array_merge($params, $campIds);
                }
            }
            $q = $db->prepare("SELECT COALESCE(SUM(spend),0) FROM ads_spend_cache WHERE credential_id IN ($ph) AND spend_date BETWEEN ? AND ?$extra");
            $q->execute($params);
            $spend = (float) $q->fetchColumn();
        }

        if ($spend <= 0) {
            $bq = $db->prepare('SELECT COALESCE(SUM(budget),0) FROM ad_budget WHERE budget_date BETWEEN ? AND ? AND channel_id = ?');
            $bq->execute([substr($start, 0, 10), substr($end, 0, 10), $channel['id']]);
            $spend = (float) $bq->fetchColumn();
        }

        return [$spend, $keys];
    }
}
