<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Database\{Database, Migration};
use App\Commands\CommandHandler;
use App\Services\{OpenAIService, IntentService};
use App\Models\Reminder;
use App\Support\DateParser;
use Tests\Support\{SpyTelegramService, FakeOpenAI};

class TimezoneTest extends TestCase
{
    protected function setUp(): void
    {
        Database::reset();
        Database::connect(':memory:');
        (new Migration(Database::get()))->run();
        $_ENV['APP_TIMEZONE'] = 'America/Sao_Paulo'; // UTC-3
        $_ENV['ALLOWED_USER_ID'] = '0';
        $_ENV['APP_DEFAULT_LANGUAGE'] = 'en';
        $_ENV['APP_SUPPORTED_LANGUAGES'] = 'en,pt-BR,es';
    }

    public function testToUtcShiftsWallClockToUtc(): void
    {
        // 15:00 in Sao Paulo (UTC-3) is 18:00 UTC.
        $this->assertEquals('2026-06-25 18:00:00', DateParser::toUtc('2026-06-25 15:00:00', 'America/Sao_Paulo'));
    }

    public function testRoundTripUtcToLocal(): void
    {
        $utc = DateParser::toUtc('2026-06-25 15:00:00', 'America/Sao_Paulo');
        $this->assertEquals('2026-06-25 15:00', DateParser::toLocal($utc, 'America/Sao_Paulo', 'Y-m-d H:i'));
    }

    /**
     * Regression for the "reminder fires immediately" bug: a reminder set ~30 min
     * in the future (wall clock, in a UTC-behind timezone) must be stored in UTC
     * and NOT be considered due against SQLite's UTC datetime('now').
     */
    public function testNearFutureReminderIsNotImmediatelyDue(): void
    {
        $telegram = new SpyTelegramService();
        // Intent resolves "in 30 minutes" to a local wall-clock datetime.
        $localFuture = (new \DateTime('+30 minutes', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
        $json = json_encode([
            'intent' => 'create_reminder', 'confidence' => 0.95, 'language' => 'en',
            'data' => ['message' => 'nap time', 'datetime' => $localFuture],
        ]);
        $openai = new OpenAIService(FakeOpenAI::client([$json]));
        $handler = new CommandHandler($telegram, $openai, new IntentService($openai));

        $handler->handle(['message' => [
            'chat' => ['id' => 555, 'type' => 'private'],
            'from' => ['id' => 555],
            'text' => 'remind me in 30 minutes: nap time',
        ]]);

        $reminders = Reminder::list(555);
        $this->assertCount(1, $reminders);

        // Stored value is in UTC (≈ local + 3h) and roughly 30 min ahead of UTC now.
        $storedUtc = new \DateTime($reminders[0]['scheduled_at'], new \DateTimeZone('UTC'));
        $nowUtc = new \DateTime('now', new \DateTimeZone('UTC'));
        $diffMinutes = ($storedUtc->getTimestamp() - $nowUtc->getTimestamp()) / 60;
        $this->assertGreaterThan(20, $diffMinutes, 'reminder should be ~30 min in the future, not in the past');

        // And it must not be picked up as due right now.
        $due = array_filter(Reminder::pendingNotification(), fn($r) => (int)$r['id'] === (int)$reminders[0]['id']);
        $this->assertEmpty($due, 'near-future reminder must not fire immediately');
    }
}
