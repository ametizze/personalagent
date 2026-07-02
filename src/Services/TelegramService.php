<?php

namespace App\Services;

use GuzzleHttp\Client;

class TelegramService
{
    private Client $http;
    private string $baseUrl;

    public function __construct()
    {
        $token = $_ENV['TELEGRAM_BOT_TOKEN'];
        $this->baseUrl = "https://api.telegram.org/bot{$token}";
        $this->http = new Client(['timeout' => 30]);
    }

    public function sendMessage(int $chatId, string $text, string $parseMode = 'Markdown'): array
    {
        $chunks = $this->splitMessage($text);
        $last = [];

        foreach ($chunks as $chunk) {
            $last = $this->post('sendMessage', [
                'chat_id'    => $chatId,
                'text'       => $chunk,
                'parse_mode' => $parseMode,
            ]);
        }

        return $last;
    }

    public function sendWithButtons(int $chatId, string $text, array $buttons): array
    {
        $keyboard = ['inline_keyboard' => $buttons];

        return $this->post('sendMessage', [
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    public function answerCallback(string $callbackQueryId, string $text = ''): array
    {
        return $this->post('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
            'text'              => $text,
        ]);
    }

    public function setWebhook(string $url): array
    {
        return $this->post('setWebhook', ['url' => $url]);
    }

    public function deleteWebhook(): array
    {
        return $this->post('deleteWebhook');
    }

    public function getUpdates(int $offset = 0, int $limit = 100): array
    {
        $data = $this->post('getUpdates', [
            'offset'  => $offset,
            'limit'   => $limit,
            'timeout' => 30,
        ]);

        return $data['result'] ?? [];
    }

    public function getWebhookInfo(): array
    {
        return $this->post('getWebhookInfo');
    }

    public function sendTypingAction(int $chatId): void
    {
        $this->post('sendChatAction', [
            'chat_id' => $chatId,
            'action'  => 'typing',
        ]);
    }

    private function post(string $method, array $params = []): array
    {
        $response = $this->http->post("{$this->baseUrl}/{$method}", [
            'json' => $params,
        ]);

        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    private function splitMessage(string $text, int $maxLength = 4096): array
    {
        if (mb_strlen($text) <= $maxLength) {
            return [$text];
        }

        $parts = [];
        while (mb_strlen($text) > 0) {
            $parts[] = mb_substr($text, 0, $maxLength);
            $text = mb_substr($text, $maxLength);
        }

        return $parts;
    }
}
