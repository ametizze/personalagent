<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Database\{Database, Migration};
use App\Models\{CalendarEvent, Reminder, Task, Idea, Note, Account, Transaction, Configuration};

class DatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        Database::reset();
        Database::connect(':memory:');
        (new Migration(Database::get()))->run();
    }

    public function testCalendarEventCreateAndList(): void
    {
        $id = CalendarEvent::create(123, 'Meeting', '2026-06-01 14:00:00', 'Important meeting');
        $this->assertGreaterThan(0, $id);

        $list = CalendarEvent::list(123);
        $this->assertCount(1, $list);
        $this->assertEquals('Meeting', $list[0]['title']);
    }

    public function testCalendarEventCancel(): void
    {
        $id = CalendarEvent::create(123, 'Dentist', '2026-06-02 10:30:00');
        $this->assertTrue(CalendarEvent::cancel($id, 123));
        $this->assertCount(0, CalendarEvent::list(123, 'active'));
        $this->assertCount(1, CalendarEvent::list(123, 'cancelled'));
    }

    public function testReminderCreateAndList(): void
    {
        $id = Reminder::create(123, 'Call the doctor', '2026-06-01 09:00:00');
        $this->assertGreaterThan(0, $id);

        $list = Reminder::list(123);
        $this->assertCount(1, $list);
        $this->assertEquals('Call the doctor', $list[0]['message']);
    }

    public function testReminderRecurringFilter(): void
    {
        Reminder::create(123, 'One-off', '2026-06-01 09:00:00');
        Reminder::create(123, 'Daily water', '2026-06-01 08:00:00', 'daily');
        $this->assertCount(1, Reminder::listRecurring(123));
    }

    public function testTaskCreateCompleteAndTags(): void
    {
        $id = Task::create(123, 'Review report', 'high', null, null, ['work', 'q3']);
        $this->assertGreaterThan(0, $id);

        $task = Task::list(123, 'pending')[0];
        $this->assertEquals('work,q3', $task['tags']);

        $this->assertTrue(Task::complete($id, 123));
        $this->assertCount(0, Task::list(123, 'pending'));
    }

    public function testTaskImportantOnlyHighAndUrgent(): void
    {
        Task::create(123, 'Low one', 'low');
        Task::create(123, 'Urgent one', 'urgent');
        $important = Task::listImportant(123);
        $this->assertCount(1, $important);
        $this->assertEquals('Urgent one', $important[0]['title']);
    }

    public function testIdeaCreateAndSearch(): void
    {
        Idea::create(123, 'Finance app', 'Build an app for expense tracking', 'tech', ['php', 'fintech']);
        $results = Idea::search(123, 'expense');
        $this->assertCount(1, $results);
        $this->assertEquals('Finance app', $results[0]['title']);
    }

    public function testNoteCreateListAndDelete(): void
    {
        $id = Note::create(123, 'Deploy note', 'Restart queue workers after deploy', 'devops', ['laravel']);
        $this->assertCount(1, Note::list(123, 'laravel'));
        $this->assertCount(0, Note::list(123, 'nonexistent-term'));
        $this->assertTrue(Note::delete($id, 123));
        $this->assertCount(0, Note::list(123));
    }

    public function testAccountAndTransactionKeepBalanceInSync(): void
    {
        $accountId = Account::create(123, 'Checking', 'checking', 'USD', 1000.0);
        Transaction::create(123, $accountId, 'income', 500.0, 'USD', 'salary');
        Transaction::create(123, $accountId, 'expense', 75.0, 'USD', 'gas');

        $account = Account::find($accountId, 123);
        $this->assertEqualsWithDelta(1425.0, (float)$account['balance'], 0.001);
        $this->assertEqualsWithDelta(1425.0, Account::totalBalance(123), 0.001);
    }

    public function testTransactionSumSince(): void
    {
        $accountId = Account::create(123, 'Cash', 'cash', 'USD', 0.0);
        Transaction::create(123, $accountId, 'expense', 20.0, 'USD', null, null, date('Y-m-d H:i:s'));
        $this->assertEqualsWithDelta(20.0, Transaction::sumSince(123, 'expense', '2000-01-01 00:00:00'), 0.001);
        $this->assertEqualsWithDelta(0.0, Transaction::sumSince(123, 'income', '2000-01-01 00:00:00'), 0.001);
    }

    public function testConfigurationUpsertAndLanguage(): void
    {
        Configuration::upsert(123, ['name' => 'Julio', 'timezone' => 'America/New_York', 'language' => 'pt-BR']);
        $config = Configuration::findByChatId(123);
        $this->assertEquals('Julio', $config['name']);
        $this->assertEquals('pt-BR', Configuration::language(123));

        Configuration::upsert(123, ['name' => 'Julio Updated']);
        $this->assertEquals('Julio Updated', Configuration::findByChatId(123)['name']);
        $this->assertEquals('pt-BR', Configuration::language(123)); // unchanged
    }

    public function testCalendarMarkNotified(): void
    {
        $id = CalendarEvent::create(123, 'Event', date('Y-m-d H:i:s', strtotime('+5 minutes')));
        CalendarEvent::markNotified($id);

        $stmt = Database::get()->prepare('SELECT notified FROM calendar_events WHERE id = ?');
        $stmt->execute([$id]);
        $this->assertEquals(1, $stmt->fetch()['notified']);
    }
}
