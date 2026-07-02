<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Database\{Database, Migration};
use App\Commands\CommandHandler;
use App\Services\{OpenAIService, IntentService};
use App\Models\{Task, CalendarEvent, Reminder};
use Tests\Support\{SpyTelegramService, FakeOpenAI};

class GroupModeTest extends TestCase
{
    private SpyTelegramService $telegram;

    private const GROUP_ID = -1001234567890;
    private const ALICE = 111;
    private const BOB = 222;

    protected function setUp(): void
    {
        Database::reset();
        Database::connect(':memory:');
        (new Migration(Database::get()))->run();
        $this->telegram = new SpyTelegramService();

        $_ENV['ALLOWED_USER_ID'] = '0';
        $_ENV['ALLOWED_USER_IDS'] = '';
        $_ENV['ALLOWED_GROUP_IDS'] = (string)self::GROUP_ID;
        $_ENV['BOT_USERNAME'] = 'PersonalAgentBot';
        $_ENV['APP_DEFAULT_LANGUAGE'] = 'en';
        $_ENV['APP_SUPPORTED_LANGUAGES'] = 'en,pt-BR,es';
    }

    protected function tearDown(): void
    {
        $_ENV['ALLOWED_GROUP_IDS'] = '';
        $_ENV['BOT_USERNAME'] = '';
    }

    private function handler(array $aiReplies = []): CommandHandler
    {
        $openai = new OpenAIService(FakeOpenAI::client($aiReplies));
        return new CommandHandler($this->telegram, $openai, new IntentService($openai));
    }

    private function groupMessage(int $userId, string $text): array
    {
        return ['message' => [
            'chat' => ['id' => self::GROUP_ID, 'type' => 'supergroup'],
            'from' => ['id' => $userId, 'first_name' => 'User' . $userId],
            'text' => $text,
        ]];
    }

    public function testCommandWithBotSuffixRoutesInGroup(): void
    {
        $this->handler()->handle($this->groupMessage(self::ALICE, '/help@PersonalAgentBot'));
        $this->assertStringContainsString('Commands', $this->telegram->lastText());
        // Reply goes to the group, not the user's DM.
        $this->assertEquals(self::GROUP_ID, end($this->telegram->messages)['chat_id']);
    }

    public function testDataIsScopedPerPersonNotPerGroup(): void
    {
        $aliceJson = json_encode(['intent' => 'create_task', 'confidence' => 0.95, 'language' => 'en',
            'data' => ['title' => "Alice's task", 'priority' => 'high']]);
        $bobJson = json_encode(['intent' => 'create_task', 'confidence' => 0.95, 'language' => 'en',
            'data' => ['title' => "Bob's task", 'priority' => 'low']]);

        // Both address the bot in the same group.
        $this->handler([$aliceJson])->handle($this->groupMessage(self::ALICE, '@PersonalAgentBot add a high task'));
        $this->handler([$bobJson])->handle($this->groupMessage(self::BOB, '@PersonalAgentBot add a low task'));

        $aliceTasks = Task::list(self::ALICE, 'all');
        $bobTasks = Task::list(self::BOB, 'all');

        $this->assertCount(1, $aliceTasks);
        $this->assertCount(1, $bobTasks);
        $this->assertEquals("Alice's task", $aliceTasks[0]['title']);
        $this->assertEquals("Bob's task", $bobTasks[0]['title']);
        // Nothing is stored under the group id.
        $this->assertCount(0, Task::list(self::GROUP_ID, 'all'));
    }

    public function testReminderAndEventDeliverToGroupChat(): void
    {
        $reminderJson = json_encode(['intent' => 'create_reminder', 'confidence' => 0.95, 'language' => 'en',
            'data' => ['message' => 'standup', 'datetime' => '2030-01-01 09:00:00']]);
        $this->handler([$reminderJson])->handle($this->groupMessage(self::ALICE, '@PersonalAgentBot remind us about standup'));

        $reminders = Reminder::list(self::ALICE);
        $this->assertCount(1, $reminders);
        // Owner is the person, but scheduled delivery targets the group chat.
        $this->assertEquals(self::ALICE, (int)$reminders[0]['chat_id']);
        $this->assertEquals(self::GROUP_ID, (int)$reminders[0]['notify_chat_id']);
    }

    public function testUnlistedGroupIsIgnoredSilently(): void
    {
        $_ENV['ALLOWED_GROUP_IDS'] = '-1009999999999'; // not our group
        $this->handler()->handle($this->groupMessage(self::ALICE, '/help@PersonalAgentBot'));
        $this->assertEmpty($this->telegram->messages);
    }

    public function testFreeTextWithoutMentionIsIgnoredInGroup(): void
    {
        // No @mention and not a reply to the bot → ignored, no AI call attempted.
        $this->handler()->handle($this->groupMessage(self::ALICE, 'just chatting with friends'));
        $this->assertEmpty($this->telegram->messages);
    }

    public function testUserWhitelistBlocksOtherMembers(): void
    {
        $_ENV['ALLOWED_USER_IDS'] = (string)self::ALICE; // only Alice allowed
        $this->handler()->handle($this->groupMessage(self::BOB, '/help@PersonalAgentBot'));
        $this->assertEmpty($this->telegram->messages); // Bob silently ignored

        $this->handler()->handle($this->groupMessage(self::ALICE, '/help@PersonalAgentBot'));
        $this->assertNotEmpty($this->telegram->messages); // Alice gets a reply
    }

    public function testCallbackIsScopedToThePresser(): void
    {
        // An event owned by Alice.
        $eventId = CalendarEvent::create(self::ALICE, 'Alice meeting', '2030-01-01 10:00:00');

        // Bob presses the cancel button — he must not be able to cancel Alice's event.
        $bobCallback = ['callback_query' => [
            'id' => 'cb1',
            'from' => ['id' => self::BOB],
            'message' => ['chat' => ['id' => self::GROUP_ID]],
            'data' => "event_cancel:{$eventId}",
        ]];
        $this->handler()->handle($bobCallback);
        $this->assertCount(1, CalendarEvent::list(self::ALICE, 'active'), 'Bob should not cancel Alice event');

        // Alice presses it — succeeds.
        $aliceCallback = $bobCallback;
        $aliceCallback['callback_query']['from']['id'] = self::ALICE;
        $this->handler()->handle($aliceCallback);
        $this->assertCount(0, CalendarEvent::list(self::ALICE, 'active'));
    }
}
