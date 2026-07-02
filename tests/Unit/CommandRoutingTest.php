<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Database\{Database, Migration};
use App\Commands\CommandHandler;
use App\Services\{OpenAIService, IntentService};
use App\Models\{Task, Memory, CalendarEvent, Configuration};
use Tests\Support\{SpyTelegramService, FakeOpenAI};

class CommandRoutingTest extends TestCase
{
    private SpyTelegramService $telegram;

    protected function setUp(): void
    {
        Database::reset();
        Database::connect(':memory:');
        (new Migration(Database::get()))->run();
        $this->telegram = new SpyTelegramService();
        $_ENV['ALLOWED_USER_ID'] = '0';
        $_ENV['APP_DEFAULT_LANGUAGE'] = 'en';
        $_ENV['APP_SUPPORTED_LANGUAGES'] = 'en,pt-BR,es';
    }

    private function handler(array $aiReplies = []): CommandHandler
    {
        $openai = new OpenAIService(FakeOpenAI::client($aiReplies));
        return new CommandHandler($this->telegram, $openai, new IntentService($openai));
    }

    private function message(int $chatId, string $text): array
    {
        return ['message' => ['chat' => ['id' => $chatId], 'from' => ['id' => $chatId], 'text' => $text]];
    }

    public function testHelpCommandSendsHelpText(): void
    {
        $this->handler()->handle($this->message(123, '/help'));
        $this->assertStringContainsString('Commands', $this->telegram->lastText());
    }

    public function testMultilingualAliasRoutesToHelp(): void
    {
        // A user whose stored language is Portuguese using the /ajuda alias.
        Configuration::upsert(123, ['language' => 'pt-BR']);
        $this->handler()->handle($this->message(123, '/ajuda'));
        $this->assertStringContainsString('Comandos', $this->telegram->lastText());
    }

    public function testUnauthorizedUserIsBlocked(): void
    {
        $_ENV['ALLOWED_USER_ID'] = '999';
        $this->handler()->handle($this->message(123, '/help'));
        $this->assertStringContainsString('Unauthorized', $this->telegram->lastText());
        $_ENV['ALLOWED_USER_ID'] = '0';
    }

    public function testNaturalLanguageCreatesTask(): void
    {
        $json = json_encode([
            'intent' => 'create_task', 'confidence' => 0.95, 'language' => 'en',
            'data' => ['title' => 'Review the contract', 'priority' => 'urgent'],
        ]);
        $this->handler([$json])->handle($this->message(123, 'create an urgent task to review the contract'));

        $tasks = Task::list(123, 'pending');
        $this->assertCount(1, $tasks);
        $this->assertEquals('Review the contract', $tasks[0]['title']);
        $this->assertEquals('urgent', $tasks[0]['priority']);
        $this->assertStringContainsString('Task created', $this->telegram->lastText());
    }

    public function testNaturalLanguageStoresMemory(): void
    {
        $json = json_encode([
            'intent' => 'create_memory', 'confidence' => 0.9, 'language' => 'en',
            'data' => ['content' => 'I prefer short answers', 'category' => 'preference', 'importance' => 4],
        ]);
        $this->handler([$json])->handle($this->message(123, 'remember that I prefer short answers'));

        $this->assertCount(1, Memory::list(123));
        $this->assertEquals('I prefer short answers', Memory::list(123)[0]['content']);
    }

    public function testDestructiveCancelAsksForConfirmation(): void
    {
        $eventId = CalendarEvent::create(123, 'Old meeting', '2030-01-01 10:00:00');
        $json = json_encode([
            'intent' => 'cancel_calendar_event', 'confidence' => 0.9, 'language' => 'en',
            'data' => ['id' => $eventId],
        ]);
        $this->handler([$json])->handle($this->message(123, "cancel event #{$eventId}"));

        // No deletion yet — a confirmation with buttons is sent instead.
        $this->assertNotEmpty($this->telegram->buttonMessages);
        $this->assertCount(1, CalendarEvent::list(123, 'active'));
    }

    public function testConfirmationCallbackCancelsEvent(): void
    {
        $eventId = CalendarEvent::create(123, 'Old meeting', '2030-01-01 10:00:00');
        $callback = ['callback_query' => [
            'id' => 'cb1',
            'message' => ['chat' => ['id' => 123]],
            'data' => "event_cancel:{$eventId}",
        ]];
        $this->handler()->handle($callback);

        $this->assertCount(0, CalendarEvent::list(123, 'active'));
        $this->assertCount(1, CalendarEvent::list(123, 'cancelled'));
    }

    public function testFreeChatFallsBackToConversation(): void
    {
        $intentJson = json_encode(['intent' => 'free_chat', 'confidence' => 0.2, 'language' => 'en', 'data' => []]);
        // First AI call = intent parse, second = the chat reply.
        $this->handler([$intentJson, 'Hi! How can I help?'])->handle($this->message(123, 'hello there friend'));
        $this->assertStringContainsString('How can I help', $this->telegram->lastText());
    }
}
