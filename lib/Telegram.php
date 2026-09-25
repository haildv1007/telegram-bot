<?php
/**
 * Telegram API wrapper — gọn cho send message + callback.
 */
class Telegram {
    private string $token;

    public function __construct(string $token) {
        $this->token = $token;
    }

    public function sendMessage(string $chatId, string $text, ?array $keyboard = null, ?int $replyToId = null): array {
        $payload = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
        if ($keyboard) $payload['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
        if ($replyToId) $payload['reply_to_message_id'] = $replyToId;
        return $this->call('sendMessage', $payload);
    }

    public function answerCallback(string $callbackId, string $text = ''): array {
        return $this->call('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => $text,
        ]);
    }

    public function getChat(string $chatId): array {
        return $this->call('getChat', ['chat_id' => $chatId]);
    }

    public function getMe(): array {
        return $this->call('getMe', []);
    }

    private function call(string $method, array $payload): array {
        $url = 'https://api.telegram.org/bot' . $this->token . '/' . $method;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) return ['ok' => false, 'error' => $err];
        $data = json_decode($resp, true);
        return $data ?: ['ok' => false, 'error' => 'invalid_response'];
    }
}
