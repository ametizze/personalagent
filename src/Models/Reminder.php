<?php

namespace App\Models;

use App\Database\Database;

class Reminder
{
    public static function create(int $chatId, string $message, string $scheduledAt, ?string $recurrence = null, ?int $notifyChatId = null): int
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'INSERT INTO reminders (chat_id, message, scheduled_at, recurrence, notify_chat_id) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$chatId, $message, $scheduledAt, $recurrence, $notifyChatId]);
        return (int)$db->lastInsertId();
    }

    public static function list(int $chatId, int $limit = 10): array
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT * FROM reminders WHERE chat_id = ? AND active = 1 ORDER BY scheduled_at ASC LIMIT ?'
        );
        $stmt->execute([$chatId, $limit]);
        return $stmt->fetchAll();
    }

    public static function listRecurring(int $chatId, int $limit = 20): array
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT * FROM reminders WHERE chat_id = ? AND active = 1 AND recurrence IS NOT NULL ORDER BY scheduled_at ASC LIMIT ?'
        );
        $stmt->execute([$chatId, $limit]);
        return $stmt->fetchAll();
    }

    public static function listToday(int $chatId): array
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT * FROM reminders WHERE chat_id = ? AND active = 1 AND date(scheduled_at) = date("now") ORDER BY scheduled_at ASC'
        );
        $stmt->execute([$chatId]);
        return $stmt->fetchAll();
    }

    public static function pendingNotification(): array
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT * FROM reminders WHERE notified = 0 AND active = 1 AND scheduled_at <= datetime("now", "+1 minute")'
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function markNotified(int $id): void
    {
        $db = Database::get();
        $db->prepare('UPDATE reminders SET notified = 1 WHERE id = ?')->execute([$id]);
    }

    public static function reschedule(int $id, string $newScheduledAt): void
    {
        $db = Database::get();
        $db->prepare('UPDATE reminders SET scheduled_at = ?, notified = 0 WHERE id = ?')
           ->execute([$newScheduledAt, $id]);
    }

    public static function deactivate(int $id, int $chatId): bool
    {
        $db = Database::get();
        $stmt = $db->prepare('UPDATE reminders SET active = 0 WHERE id = ? AND chat_id = ?');
        $stmt->execute([$id, $chatId]);
        return $stmt->rowCount() > 0;
    }
}
