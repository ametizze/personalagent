<?php

namespace Tests\Support;

use App\Services\TelegramService;

/**
 * Records outgoing messages instead of hitting the Telegram API, so command
 * routing can be asserted without any network access.
 */
class SpyTelegramService extends TelegramService
{
    /** @var array<int, array{chat_id:int, text:string}> */
    public array $messages = [];
    /** @var array<int, array{chat_id:int, text:string, buttons:array}> */
    public array $buttonMessages = [];

    public function __construct()
    {
        // Intentionally skip parent constructor (no token / HTTP client needed).
    }

    public function sendMessage(int $chatId, string $text, string $parseMode = 'Markdown'): array
    {
        $this->messages[] = ['chat_id' => $chatId, 'text' => $text];
        return ['ok' => true];
    }

    public function sendWithButtons(int $chatId, string $text, array $buttons): array
    {
        $this->buttonMessages[] = ['chat_id' => $chatId, 'text' => $text, 'buttons' => $buttons];
        return ['ok' => true];
    }

    public function answerCallback(string $callbackQueryId, string $text = ''): array
    {
        return ['ok' => true];
    }

    public function sendTypingAction(int $chatId): void {}

    public function lastText(): string
    {
        return end($this->messages)['text'] ?? '';
    }

    public function allText(): string
    {
        return implode("\n", array_column($this->messages, 'text'));
    }
}
