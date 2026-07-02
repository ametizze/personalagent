<?php

namespace App\Services;

use OpenAI;
use OpenAI\Contracts\ClientContract;
use App\Models\Configuration;
use App\Models\Conversation;

class OpenAIService
{
    private ClientContract $client;
    private string $model;
    private int $maxTokens;
    private float $temperature;

    public function __construct(?ClientContract $client = null)
    {
        $this->client = $client ?? OpenAI::client($_ENV['OPENAI_API_KEY']);
        $this->model = $_ENV['OPENAI_MODEL'] ?? 'gpt-4o';
        $this->maxTokens = (int)($_ENV['OPENAI_MAX_TOKENS'] ?? 2000);
        $this->temperature = (float)($_ENV['OPENAI_TEMPERATURE'] ?? 0.3);
    }

    /**
     * Free-conversation reply with short-term history and long-term memory.
     *
     * @param array $memories list of memory rows (['content' => ...])
     */
    public function chat(int $chatId, string $userMessage, string $lang, array $memories = []): string
    {
        Conversation::add($chatId, 'user', $userMessage);

        $messages = [['role' => 'system', 'content' => $this->buildSystemPrompt($chatId, $lang, $memories)]];
        foreach (Conversation::recent($chatId) as $msg) {
            $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }

        $response = $this->client->chat()->create([
            'model'       => $this->model,
            'messages'    => $messages,
            'max_tokens'  => $this->maxTokens,
            'temperature' => 0.7,
        ]);

        $reply = $response->choices[0]->message->content ?? '';
        Conversation::add($chatId, 'assistant', $reply);

        return $reply;
    }

    /** Strict-JSON completion (temperature 0.1). Returns [] on parse failure. */
    public function json(string $systemPrompt, string $userMessage, int $maxTokens = 600): array
    {
        $response = $this->client->chat()->create([
            'model'       => $this->model,
            'messages'    => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage],
            ],
            'max_tokens'  => $maxTokens,
            'temperature' => 0.1,
        ]);

        $content = $response->choices[0]->message->content ?? '';
        $content = trim((string)preg_replace('/^```(?:json)?|```$/m', '', $content));
        $data = json_decode($content, true);

        return is_array($data) ? $data : [];
    }

    /** Generic single-shot text generation (summaries, suggestions). */
    public function generateText(string $systemPrompt, string $userMessage, int $maxTokens = 800): string
    {
        $response = $this->client->chat()->create([
            'model'       => $this->model,
            'messages'    => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage],
            ],
            'max_tokens'  => $maxTokens,
            'temperature' => $this->temperature,
        ]);

        return $response->choices[0]->message->content ?? '';
    }

    private function buildSystemPrompt(int $chatId, string $lang, array $memories): string
    {
        $config = Configuration::findByChatId($chatId);
        $name = $config['name'] ?? null;
        $tz = $config['timezone'] ?? ($_ENV['APP_TIMEZONE'] ?? 'UTC');
        $now = (new \DateTime('now', new \DateTimeZone($tz)))->format('Y-m-d H:i');

        $prompt = "You are PersonalAgent, a private personal assistant"
            . ($name ? " for {$name}" : '')
            . ". Current date and time: {$now} (timezone: {$tz}).\n"
            . "Reply in the same language as the user. The user's current language is '{$lang}'. "
            . "Be clear, concise and helpful. You help with calendar, tasks, reminders, ideas, notes and personal finance.";

        if (!empty($memories)) {
            $facts = implode("\n", array_map(fn($m) => '- ' . $m['content'], $memories));
            $prompt .= "\n\nThings you know about the user (long-term memory):\n{$facts}";
        }

        if (!empty($config['system_context'])) {
            $prompt .= "\n\nAdditional context: {$config['system_context']}";
        }

        return $prompt;
    }
}
