<?php

namespace App\Models;

use App\Database\Database;

class CalendarEvent
{
    public static function create(
        int $chatId,
        string $title,
        string $scheduledAt,
        ?string $description = null,
        ?string $recurrence = null,
        ?string $timezone = null,
        ?int $notifyChatId = null
    ): int {
        $db = Database::get();
        $stmt = $db->prepare(
            'INSERT INTO calendar_events (chat_id, title, description, scheduled_at, recurrence, timezone, notify_chat_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$chatId, $title, $description, $scheduledAt, $recurrence, $timezone, $notifyChatId]);
        return (int)$db->lastInsertId();
    }

    public static function list(int $chatId, string $status = 'active', int $limit = 10): array
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT * FROM calendar_events WHERE chat_id = ? AND status = ? ORDER BY scheduled_at ASC LIMIT ?'
        );
        $stmt->execute([$chatId, $status, $limit]);
        return $stmt->fetchAll();
    }

    public static function listUpcoming(int $chatId, int $days = 7): array
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT * FROM calendar_events
             WHERE chat_id = ? AND status = ?
               AND scheduled_at BETWEEN datetime("now") AND datetime("now", ? || " days")
             ORDER BY scheduled_at ASC'
        );
        $stmt->execute([$chatId, 'active', $days]);
        return $stmt->fetchAll();
    }

    public static function listToday(int $chatId): array
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT * FROM calendar_events
             WHERE chat_id = ? AND status = "active" AND date(scheduled_at) = date("now")
             ORDER BY scheduled_at ASC'
        );
        $stmt->execute([$chatId]);
        return $stmt->fetchAll();
    }

    /** Events starting within the next 15 minutes that have not been notified yet. */
    public static function pendingNotification(): array
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT * FROM calendar_events
             WHERE notified = 0 AND status = "active"
               AND scheduled_at <= datetime("now", "+15 minutes")
               AND scheduled_at >= datetime("now")'
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function markNotified(int $id): void
    {
        Database::get()->prepare('UPDATE calendar_events SET notified = 1 WHERE id = ?')->execute([$id]);
    }

    public static function complete(int $id, int $chatId): bool
    {
        $db = Database::get();
        $stmt = $db->prepare('UPDATE calendar_events SET status = "completed", updated_at = CURRENT_TIMESTAMP WHERE id = ? AND chat_id = ?');
        $stmt->execute([$id, $chatId]);
        return $stmt->rowCount() > 0;
    }

    public static function cancel(int $id, int $chatId): bool
    {
        $db = Database::get();
        $stmt = $db->prepare('UPDATE calendar_events SET status = "cancelled", updated_at = CURRENT_TIMESTAMP WHERE id = ? AND chat_id = ?');
        $stmt->execute([$id, $chatId]);
        return $stmt->rowCount() > 0;
    }
}
