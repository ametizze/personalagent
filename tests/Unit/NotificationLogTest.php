<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Database\{Database, Migration};
use App\Models\NotificationLog;

class NotificationLogTest extends TestCase
{
    protected function setUp(): void
    {
        Database::reset();
        Database::connect(':memory:');
        (new Migration(Database::get()))->run();
    }

    public function testDuplicateProtectionByReferenceId(): void
    {
        $this->assertFalse(NotificationLog::wasSent(123, 'calendar', 7));
        NotificationLog::record(123, 'calendar', 7);
        $this->assertTrue(NotificationLog::wasSent(123, 'calendar', 7));
        // A different event id is independent.
        $this->assertFalse(NotificationLog::wasSent(123, 'calendar', 8));
    }

    public function testDailySummaryDedupeByDate(): void
    {
        $today = date('Y-m-d');
        $this->assertFalse(NotificationLog::wasSent(123, 'daily_summary', null, $today));
        NotificationLog::record(123, 'daily_summary', null, $today);
        $this->assertTrue(NotificationLog::wasSent(123, 'daily_summary', null, $today));
        // Tomorrow is not yet sent.
        $this->assertFalse(NotificationLog::wasSent(123, 'daily_summary', null, date('Y-m-d', strtotime('+1 day'))));
    }

    public function testRecurringReminderDedupeByFireTime(): void
    {
        $this->assertFalse(NotificationLog::wasSent(123, 'reminder', 3, '2026-06-25 08:00:00'));
        NotificationLog::record(123, 'reminder', 3, '2026-06-25 08:00:00');
        $this->assertTrue(NotificationLog::wasSent(123, 'reminder', 3, '2026-06-25 08:00:00'));
        // Next occurrence of the same recurring reminder is a new fire time.
        $this->assertFalse(NotificationLog::wasSent(123, 'reminder', 3, '2026-06-26 08:00:00'));
    }
}
