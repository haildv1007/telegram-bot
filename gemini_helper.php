<?php
/**
 * gemini_helper.php
 * Tổng hợp dữ liệu lead từ database + gọi Gemini API để trả lời câu hỏi.
 */

require_once __DIR__ . '/config.php';

/**
 * Lấy số liệu tổng hợp từ bảng leads, đóng gói thành đoạn text ngắn gọn
 * để đưa cho Gemini làm "ngữ cảnh" trả lời câu hỏi (không gửi từng dòng lead
 * để tránh lộ dữ liệu cá nhân chi tiết và tránh quá dài).
 */
function build_data_context($pdo) {
    $lines = [];

    $total = $pdo->query('SELECT COUNT(*) FROM leads')->fetchColumn();
    $lines[] = "Tổng số lead từ trước tới nay: {$total}";

    $today = date('Y-m-d');
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM leads WHERE DATE(created_at) = ?');
    $stmt->execute([$today]);
    $lines[] = "Lead hôm nay ({$today}): " . $stmt->fetchColumn();

    // Số lead theo từng ngày, 14 ngày gần nhất
    $stmt = $pdo->query(
        'SELECT DATE(created_at) d, COUNT(*) c FROM leads
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
         GROUP BY DATE(created_at) ORDER BY d DESC'
    );
    $byDay = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $lines[] = "Số lead theo ngày (14 ngày gần nhất):";
    foreach ($byDay as $row) {
        $lines[] = "  {$row['d']}: {$row['c']} lead";
    }

    // Top khu vực
    $stmt = $pdo->query('SELECT area, COUNT(*) c FROM leads GROUP BY area ORDER BY c DESC LIMIT 10');
    $lines[] = "Top khu vực nhiều lead nhất:";
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $areaName = $row['area'] ?: '(không rõ)';
        $lines[] = "  {$areaName}: {$row['c']} lead";
    }

    // Tình trạng xe
    $stmt = $pdo->query('SELECT vehicle, COUNT(*) c FROM leads GROUP BY vehicle ORDER BY c DESC LIMIT 10');
    $lines[] = "Phân bố theo phương tiện:";
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $v = $row['vehicle'] ?: '(không rõ)';
        $lines[] = "  {$v}: {$row['c']} lead";
    }

    // Số lead trùng (theo dup_key xuất hiện > 1 lần)
    $stmt = $pdo->query(
        'SELECT COUNT(*) FROM (
            SELECT dup_key FROM leads GROUP BY dup_key HAVING COUNT(*) > 1
         ) t'
    );
    $lines[] = "Số lead bị trùng (cùng SĐT+CCCD, xuất hiện >1 lần): " . $stmt->fetchColumn();

    // Ngân sách quảng cáo đã nhập (14 ngày gần nhất), kèm chi phí/lead
    $stmt = $pdo->query(
        'SELECT b.budget_date, b.budget, COUNT(l.id) leads_count
         FROM ad_budget b
         LEFT JOIN leads l ON DATE(l.created_at) = b.budget_date
         WHERE b.budget_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
         GROUP BY b.budget_date, b.budget
         ORDER BY b.budget_date DESC'
    );
    $lines[] = "Ngân sách quảng cáo & chi phí/lead (14 ngày gần nhất):";
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cpl = $row['leads_count'] > 0 ? round($row['budget'] / $row['leads_count']) : 0;
        $lines[] = "  {$row['budget_date']}: ngân sách " . number_format($row['budget'], 0, ',', '.')
            . "đ, {$row['leads_count']} lead, chi phí/lead ~" . number_format($cpl, 0, ',', '.') . "đ";
    }

    return implode("\n", $lines);
}

/**
 * Gửi câu hỏi + dữ liệu ngữ cảnh cho Gemini, trả về câu trả lời dạng text
 */
function ask_gemini($question, $dataContext) {
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . GEMINI_API_KEY;

    $prompt = "Bạn là trợ lý phân tích dữ liệu leads bán hàng. "
        . "Dưới đây là số liệu tổng hợp hiện có:\n\n{$dataContext}\n\n"
        . "Dựa vào số liệu trên, trả lời ngắn gọn, rõ ràng câu hỏi sau bằng tiếng Việt. "
        . "Nếu số liệu không đủ để trả lời chính xác, hãy nói rõ là không đủ dữ liệu:\n\n"
        . "Câu hỏi: {$question}";

    $payload = [
        'contents' => [
            ['parts' => [['text' => $prompt]]]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Ghi log để debug nếu có lỗi
    @file_put_contents(__DIR__ . '/gemini_debug.log',
        date('Y-m-d H:i:s') . " - HTTP {$httpCode} - CURL_ERR: {$curlError} - RESPONSE: {$response}\n",
        FILE_APPEND
    );

    $data = json_decode($response, true);
    return $data['candidates'][0]['content']['parts'][0]['text']
        ?? 'Xin lỗi, mình chưa lấy được câu trả lời từ Gemini lúc này.';
}