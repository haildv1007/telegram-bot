<?php
/**
 * RangeHelper — chuẩn hoá khoảng ngày cho bộ lọc Dashboard (Hôm nay/7/14/30/Tùy chỉnh).
 */
class RangeHelper {
    public static function resolve(string $type, ?string $from = null, ?string $to = null): array {
        $today = date('Y-m-d');
        switch ($type) {
            case 'today': return [$today, $today, 'Hôm nay'];
            case 'yesterday': $y = date('Y-m-d', strtotime('-1 day')); return [$y, $y, 'Hôm qua'];
            case 'week':  return [date('Y-m-d', strtotime('monday this week')), $today, 'Tuần này'];
            case '7':     return [date('Y-m-d', strtotime('-6 days')), $today, '7 ngày gần nhất'];
            case '30':    return [date('Y-m-d', strtotime('-29 days')), $today, '30 ngày gần nhất'];
            case 'month': return [date('Y-m-01'), $today, 'Tháng này'];
            case 'custom':
                $f = ($from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) ? $from : date('Y-m-01');
                $t = ($to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) ? $to : $today;
                if ($f > $t) { [$f, $t] = [$t, $f]; }
                return [$f, $t, "$f → $t"];
            case '14':
            default:      return [date('Y-m-d', strtotime('-13 days')), $today, '14 ngày gần nhất'];
        }
    }
}
