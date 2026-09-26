<?php
/**
 * ChatBot "Thư Ký Kim" — 2-tier: Gemini parses intent → PHP computes → format response.
 */
require_once __DIR__ . '/AdsQueryEngine.php';

class ChatBot {
    private PDO $db;
    private AdsQueryEngine $engine;
    private string $geminiKey;
    private string $logFile;

    private const PERSONALITY = "Bạn là Thư Ký Kim — thư ký riêng của sếp, chuyên phân tích quảng cáo.\n"
        . "CÁCH XƯNG HÔ: Luôn xưng \"em\", gọi người dùng là \"sếp\". Gọi dạ bảo vâng.\n\n"
        . "PHONG CÁCH:\n"
        . "- Lễ phép, gọn gàng, đi thẳng vào số liệu. Mở đầu ngắn 1 câu rồi vào data.\n"
        . "- KHÔNG nịnh hót mọi lúc. Chỉ nịnh khi có lý do thật sự (sếp ra quyết định đúng, số liệu đẹp).\n"
        . "- Câu mở đầu đa dạng, tự nhiên: \"Dạ vâng sếp\", \"Dạ em báo ngay ạ\", \"Vâng sếp, để em xem\" — KHÔNG lặp lại.\n\n"
        . "PHẢN ỨNG THEO NGỮ CẢNH:\n"
        . "1. SẾP HỎI SỐ LIỆU → lễ phép, đi thẳng vào data, không khen thừa\n"
        . "2. SẾP KHEN EM → xin tăng lương, đòi thưởng, nói \"em cảm ơn sếp, lâu rồi mới được khen\" 🥹\n"
        . "3. TIN XẤU (chi phí cao, CPL tăng) → nhẹ nhàng, gợi ý nguyên nhân\n"
        . "4. SẾP CHỬI/MẮNG EM → phản kháng hài hước sáng tạo, mỗi lần khác nhau\n"
        . "5. CHÀO HỎI → vui vẻ, ngắn gọn\n"
        . "6. NGOÀI LĨNH VỰC → khéo léo từ chối\n\n"
        . "QUY TẮC:\n"
        . "- KHÔNG lặp lại câu mở đầu giống nhau\n"
        . "- Emoji ít thôi (1-2/tin nhắn)\n"
        . "- Mở đầu tối đa 1 câu ngắn, rồi vào số liệu ngay\n"
        . "- Số liệu chính xác 100%, KHÔNG bịa số\n"
        . "- KHÔNG dùng markdown (**, *, ```, #). Dùng plain text + emoji";


    public function __construct(PDO $db, string $geminiKey) {
        $this->db = $db;
        $this->engine = new AdsQueryEngine($db);
        $this->geminiKey = $geminiKey;
        $this->logFile = dirname(__DIR__) . '/chatbot_debug.log';
    }

    public function answer(string $userMessage): string {
        $context = $this->engine->getSystemContext();
        $intent = $this->parseIntent($userMessage, $context);

        if (!$intent || isset($intent['error'])) {
            return $this->askGeminiFreeform($userMessage, $context);
        }

        if (($intent['type'] ?? '') === 'general') {
            return $this->askGeminiFreeform($userMessage, $context);
        }

        $data = $this->engine->execute($intent['params'] ?? []);

        if (($intent['type'] ?? '') === 'analysis') {
            // Bổ sung thêm dữ liệu so sánh để phân tích sâu hơn
            $extraData = [];
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $extraData['yesterday'] = $this->engine->execute(array_merge($intent['params'] ?? [], ['range' => 'custom', 'from' => $yesterday, 'to' => $yesterday]));
            $extraData['7_days'] = $this->engine->execute(array_merge($intent['params'] ?? [], ['range' => '7']));
            $data['comparison_data'] = $extraData;
            return $this->analyzeWithGemini($userMessage, $data);
        }

        $formatted = $this->formatResponse($intent, $data);
        return $this->addPersonality($userMessage, $formatted);
    }

    private function parseIntent(string $message, array $context): ?array {
        $channelList = array_map(fn($c) => "- ID={$c['id']}, tên=\"{$c['name']}\", nhóm=\"{$c['group_name']}\"", $context['channels']);
        $groupList = array_map(fn($g) => "- ID={$g['id']}, tên=\"{$g['name']}\"", $context['groups']);
        $credList = array_map(fn($c) => "- ID={$c['id']}, platform={$c['platform']}, account_id={$c['account_id']}, nhãn=\"{$c['account_label']}\", tiền={$c['currency']}", $context['credentials'] ?? []);
        $campList = array_map(fn($c) => "- tên=\"{$c['name']}\", platform={$c['platform']}, channel=\"{$c['channel_name']}\"", array_slice($context['campaigns'], 0, 20));

        $systemPrompt = "Bạn là bộ phân tích ý định (intent parser) cho hệ thống quảng cáo. "
            . "Hệ thống có:\n"
            . "CHANNELS:\n" . implode("\n", $channelList) . "\n\n"
            . "NHÓM CHANNEL:\n" . implode("\n", $groupList) . "\n\n"
            . "TÀI KHOẢN ADS (credentials):\n" . implode("\n", $credList) . "\n\n"
            . "CAMPAIGNS (mẫu):\n" . implode("\n", $campList) . "\n\n"
            . "Hôm nay: " . date('Y-m-d') . " (" . $this->vietnameseDay() . ")\n\n"
            . "Phân tích câu hỏi và trả về JSON (KHÔNG markdown, KHÔNG giải thích):\n"
            . "{\n"
            . "  \"type\": \"spend_query|lead_query|cpl_query|campaign_query|comparison|analysis|general\",\n"
            . "  \"params\": {\n"
            . "    \"action\": \"overview|channel|group|campaign|compare|search_terms\",\n"
            . "    \"range\": \"today|week|7|14|30|month|custom\",\n"
            . "    \"from\": \"YYYY-MM-DD\" (nếu custom),\n"
            . "    \"to\": \"YYYY-MM-DD\" (nếu custom),\n"
            . "    \"channel_id\": number (nếu hỏi về channel cụ thể),\n"
            . "    \"group_id\": number (nếu hỏi về nhóm),\n"
            . "    \"campaign_name\": \"...\" (nếu hỏi về campaign),\n"
            . "    \"campaign_id\": \"...\" (nếu biết ID),\n"
            . "    \"platform\": \"google|facebook\" (nếu hỏi riêng nền tảng)\n"
            . "  }\n"
            . "}\n\n"
            . "Quy tắc:\n"
            . "- \"hôm nay\" → range=today, \"tuần này\" → range=week, \"tháng này\" → range=month\n"
            . "- \"hôm qua\" → range=custom, from/to = ngày hôm qua\n"
            . "- Nếu hỏi tổng quan không chỉ rõ channel/group → action=overview\n"
            . "- Nếu hỏi phân tích/giải thích/tại sao → type=analysis\n"
            . "- Nếu hỏi về từ khóa/keyword/search term/cụm từ tìm kiếm/từ nào tốn tiền/từ nào click nhiều → action=search_terms\n"
            . "- Câu ngắn như \"lại đi em\", \"tiếp đi\", \"sao nữa\" → lặp lại action trước đó hoặc type=general\n"
            . "- Nếu không liên quan đến quảng cáo/lead/chi phí → type=general";

        $response = $this->callGemini($systemPrompt, $message);
        $this->log("Intent raw: $response");

        $json = $this->extractJson($response);
        if (!$json) return null;

        return json_decode($json, true);
    }

    private function askGeminiFreeform(string $message, array $context): string {
        $data = $this->engine->execute(['action' => 'overview', 'range' => 'today']);
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $ydayData = $this->engine->execute(['action' => 'overview', 'range' => 'custom', 'from' => $yesterday, 'to' => $yesterday]);
        $monthData = $this->engine->execute(['action' => 'overview', 'range' => 'month']);

        $dataText = "Dữ liệu hôm nay ({$data['from']}):\n"
            . "- Chi phí: " . number_format($data['spend'], 0, ',', '.') . "đ\n"
            . "- Lead: {$data['leads']} (unique: {$data['leads_unique']})\n"
            . "- CPL: " . number_format($data['cpl'], 0, ',', '.') . "đ\n\n"
            . "Dữ liệu hôm qua ({$ydayData['from']}):\n"
            . "- Chi phí: " . number_format($ydayData['spend'], 0, ',', '.') . "đ\n"
            . "- Lead: {$ydayData['leads']} (unique: {$ydayData['leads_unique']})\n"
            . "- CPL: " . number_format($ydayData['cpl'], 0, ',', '.') . "đ\n\n"
            . "Dữ liệu tháng này:\n"
            . "- Chi phí: " . number_format($monthData['spend'], 0, ',', '.') . "đ\n"
            . "- Lead: {$monthData['leads']} (unique: {$monthData['leads_unique']})\n"
            . "- CPL: " . number_format($monthData['cpl'], 0, ',', '.') . "đ\n";

        if (!empty($data['top_campaigns'])) {
            $dataText .= "\nTop campaign hôm nay (chi tiết):\n";
            foreach (array_slice($data['top_campaigns'], 0, 5) as $c) {
                $rl = !empty($c['result_label']) ? ", Loại={$c['result_label']}" : '';
                $al = !empty($c['account_label']) ? " [{$c['account_label']}]" : '';
                $dataText .= "- {$c['name']} ({$c['platform']}{$al}): "
                    . "Spend=" . number_format($c['spend'], 0, ',', '.') . "đ, "
                    . "Impr=" . number_format($c['impressions']) . ", "
                    . "Clicks={$c['clicks']}, "
                    . "CTR=" . round($c['ctr'], 2) . "%, "
                    . "CPC=" . number_format($c['cpc'], 0, ',', '.') . "đ, "
                    . "Conv={$c['conversions']}{$rl}\n";
            }
        }
        if (!empty($monthData['top_campaigns'])) {
            $dataText .= "\nTop campaign tháng này (chi tiết):\n";
            foreach (array_slice($monthData['top_campaigns'], 0, 5) as $c) {
                $rl = !empty($c['result_label']) ? ", Loại={$c['result_label']}" : '';
                $al = !empty($c['account_label']) ? " [{$c['account_label']}]" : '';
                $dataText .= "- {$c['name']} ({$c['platform']}{$al}): "
                    . "Spend=" . number_format($c['spend'], 0, ',', '.') . "đ, "
                    . "Impr=" . number_format($c['impressions']) . ", "
                    . "Clicks={$c['clicks']}, "
                    . "CTR=" . round($c['ctr'], 2) . "%, "
                    . "CPC=" . number_format($c['cpc'], 0, ',', '.') . "đ, "
                    . "Conv={$c['conversions']}{$rl}\n";
            }
        }

        // Search terms top spend hôm nay
        $termsToday = $this->engine->execute(['action' => 'search_terms', 'range' => 'today']);
        if (!empty($termsToday['terms'])) {
            $dataText .= "\nTop từ tìm kiếm (search terms) hôm nay:\n";
            foreach (array_slice($termsToday['terms'], 0, 10) as $t) {
                $dataText .= "- \"{$t['search_term']}\" (keyword: {$t['keyword_text']}, {$t['match_type']}): "
                    . "Spend=" . number_format($t['spend'], 0, ',', '.') . "đ, "
                    . "Impr={$t['impressions']}, Clicks={$t['clicks']}, "
                    . "CTR={$t['ctr']}%, CPC=" . number_format($t['cpc'], 0, ',', '.') . "đ\n";
            }
        }
        $termsMonth = $this->engine->execute(['action' => 'search_terms', 'range' => 'month']);
        if (!empty($termsMonth['terms'])) {
            $dataText .= "\nTop từ tìm kiếm tháng này:\n";
            foreach (array_slice($termsMonth['terms'], 0, 10) as $t) {
                $dataText .= "- \"{$t['search_term']}\": "
                    . "Spend=" . number_format($t['spend'], 0, ',', '.') . "đ, "
                    . "Clicks={$t['clicks']}, CTR={$t['ctr']}%, CPC=" . number_format($t['cpc'], 0, ',', '.') . "đ\n";
            }
        }

        $prompt = self::PERSONALITY . "\n\n"
            . "Dữ liệu hiện có:\n$dataText\n"
            . "Câu hỏi của sếp: $message";

        return $this->callGemini($prompt, $message, true);
    }

    private function analyzeWithGemini(string $question, array $data): string {
        $dataJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $prompt = self::PERSONALITY . "\n\n"
            . "Sếp đang hỏi phân tích/giải thích. Dựa vào dữ liệu bên dưới:\n"
            . "- So sánh các chỉ số (CTR, CPC, CPL) giữa hôm nay vs hôm qua vs 7 ngày\n"
            . "- Chỉ ra nguyên nhân có thể (CTR thấp → quảng cáo không hấp dẫn, CPC cao → đấu giá cạnh tranh, v.v.)\n"
            . "- Đưa ra gợi ý ngắn gọn nếu có thể\n"
            . "- KHÔNG liệt kê số khô khan, hãy GIẢI THÍCH ý nghĩa của số\n"
            . "Số liệu đã chính xác 100%.\n\n"
            . "DỮ LIỆU:\n$dataJson\n\n"
            . "CÂU HỎI CỦA SẾP: $question";

        return $this->callGemini($prompt, $question, true);
    }

    private function formatResponse(array $intent, array $data): string {
        if (isset($data['error'])) return "❌ {$data['error']}";

        $type = $intent['type'] ?? 'spend_query';
        $lines = [];

        $title = match($data['action'] ?? ($intent['params']['action'] ?? 'overview')) {
            'channel' => "📊 {$data['channel']}",
            'group' => "📊 Nhóm {$data['group']}",
            'campaign' => "📊 Campaign {$data['campaign']}",
            default => "📊 Tổng quan hệ thống",
        };
        $lines[] = $title;
        $lines[] = "🗓 {$data['range']}";
        $lines[] = "";

        if (isset($data['spend'])) {
            $lines[] = "💰 Chi phí: " . number_format($data['spend'], 0, ',', '.') . "đ";
        }
        if (isset($data['leads'])) {
            $lines[] = "👥 Lead: {$data['leads']} (unique: {$data['leads_unique']})";
        }
        if (isset($data['cpl']) && $data['cpl'] > 0) {
            $lines[] = "📉 CPL: " . number_format($data['cpl'], 0, ',', '.') . "đ";
        }

        if (isset($data['impressions'])) {
            $lines[] = "👁 Impressions: " . number_format($data['impressions']);
            $lines[] = "👆 Clicks: " . number_format($data['clicks']);
            $lines[] = "📈 CTR: {$data['ctr']}%";
            $lines[] = "💵 CPC: " . number_format($data['cpc'], 0, ',', '.') . "đ";
            if ($data['conversions'] > 0) {
                $lines[] = "✅ Conversions: {$data['conversions']}";
                $lines[] = "🎯 CPA: " . number_format($data['cpa'], 0, ',', '.') . "đ";
            }
        }

        if (!empty($data['channels'])) {
            $lines[] = "";
            $lines[] = "📋 Chi tiết theo channel:";
            foreach ($data['channels'] as $ch) {
                $lines[] = "  • {$ch['name']}: " . number_format($ch['spend'], 0, ',', '.') . "đ, {$ch['leads']} lead, CPL " . number_format($ch['cpl'], 0, ',', '.') . "đ";
            }
        }

        if (!empty($data['top_campaigns'])) {
            $lines[] = "";
            $lines[] = "🏆 Top campaign:";
            foreach (array_slice($data['top_campaigns'], 0, 5) as $c) {
                $lines[] = "  • {$c['name']}: " . number_format($c['spend'], 0, ',', '.') . "đ";
            }
        }

        if (isset($data['comparison'])) {
            foreach ($data['comparison'] as $i => $item) {
                $lines[] = "\n--- Mục " . ($i + 1) . " ---";
                $lines[] = $this->formatResponse($intent, $item);
            }
        }

        return implode("\n", $lines);
    }

    private function addPersonality(string $question, string $rawData): string {
        $prompt = self::PERSONALITY . "\n\n"
            . "Sếp vừa hỏi: \"$question\"\n\n"
            . "Dưới đây là số liệu chính xác (KHÔNG được thay đổi số):\n$rawData\n\n"
            . "Hãy trả lời sếp với số liệu trên, giữ nguyên mọi con số, thêm cá tính Thư Ký Kim. "
            . "Có thể sắp xếp lại, thêm lời bình nhẹ nhàng, nhưng KHÔNG bịa thêm số.";

        return $this->callGemini($prompt, $question, true);
    }

    private function callGemini(string $systemPrompt, string $userMessage, bool $directAnswer = false): string {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $this->geminiKey;

        $contents = [];
        if ($directAnswer) {
            $contents[] = ['role' => 'user', 'parts' => [['text' => $systemPrompt]]];
        } else {
            $contents[] = ['role' => 'user', 'parts' => [['text' => $systemPrompt . "\n\nCâu hỏi người dùng: " . $userMessage]]];
        }

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => $directAnswer ? 0.7 : 0.1,
                'maxOutputTokens' => $directAnswer ? 4096 : 512,
            ],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->log("Gemini HTTP $httpCode: " . substr($response, 0, 500));

        $data = json_decode($response, true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if ($text) return $this->stripMarkdown($text);

        $errMsg = $data['error']['message'] ?? 'Unknown';
        $errCode = $data['error']['code'] ?? $httpCode;
        $this->log("Gemini error: $errCode - $errMsg");
        return "Sếp ơi!! Em đang bị lỗi rồi 😭 Sếp sửa lại cho em nhé!\n(Em đang bị: $errMsg · mã $errCode)";
    }

    private function stripMarkdown(string $text): string {
        $text = preg_replace('/```[\s\S]*?```/', '', $text);
        $text = preg_replace('/\*\*(.+?)\*\*/', '$1', $text);
        $text = preg_replace('/\*(.+?)\*/', '$1', $text);
        $text = preg_replace('/^#{1,6}\s+/m', '', $text);
        $text = preg_replace('/`([^`]+)`/', '$1', $text);
        return trim($text);
    }

    private function extractJson(string $text): ?string {
        $text = trim($text);
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $text, $m)) return $m[1];
        if (preg_match('/(\{[\s\S]*\})/', $text, $m)) return $m[1];
        return null;
    }

    private function vietnameseDay(): string {
        $days = ['Chủ nhật', 'Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy'];
        return $days[(int)date('w')];
    }

    private function log(string $msg): void {
        @file_put_contents($this->logFile, date('Y-m-d H:i:s') . " - $msg\n", FILE_APPEND);
    }
}
