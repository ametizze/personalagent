<?php

namespace App\Models;

use App\Database\Database;

class Task
{
    private const PRIORITY_ORDER =
        'CASE priority WHEN "urgent" THEN 1 WHEN "high" THEN 2 WHEN "medium" THEN 3 ELSE 4 END';

    public static function create(
        int $chatId,
        string $title,
        string $priority = 'medium',
        ?string $description = null,
        ?string $deadline = null,
        array $tags = []
    ): int {
        $db = Database::get();
        $stmt = $db->prepare(
            'INSERT INTO tasks (chat_id, title, description, priority, deadline, tags) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$chatId, $title, $description, $priority, $deadline, implode(',', $tags)]);
        return (int)$db->lastInsertId();
    }

    public static function list(int $chatId, string $status = 'pending', int $limit = 10): array
    {
        $db = Database::get();
        $order = self::PRIORITY_ORDER;

        if ($status === 'all') {
            $stmt = $db->prepare("SELECT * FROM tasks WHERE chat_id = ? ORDER BY {$order}, created_at ASC LIMIT ?");
            $stmt->execute([$chatId, $limit]);
        } else {
            $stmt = $db->prepare("SELECT * FROM tasks WHERE chat_id = ? AND status = ? ORDER BY {$order}, created_at ASC LIMIT ?");
            $stmt->execute([$chatId, $status, $limit]);
        }
        return $stmt->fetchAll();
    }

    public static function listByPriority(int $chatId, string $priority, int $limit = 10): array
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT * FROM tasks WHERE chat_id = ? AND priority = ? AND status NOT IN ("completed","cancelled") ORDER BY created_at ASC LIMIT ?'
        );
        $stmt->execute([$chatId, $priority, $limit]);
        return $stmt->fetchAll();
    }

    /** High and urgent open tasks, used by the daily summary. */
    public static function listImportant(int $chatId, int $limit = 10): array
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT * FROM tasks WHERE chat_id = ? AND priority IN ("high","urgent") AND status NOT IN ("completed","cancelled") ORDER BY ' . self::PRIORITY_ORDER . ' LIMIT ?'
        );
        $stmt->execute([$chatId, $limit]);
        return $stmt->fetchAll();
    }

    public static function complete(int $id, int $chatId): bool
    {
        $db = Database::get();
        $stmt = $db->prepare('UPDATE tasks SET status = "completed", updated_at = CURRENT_TIMESTAMP WHERE id = ? AND chat_id = ?');
        $stmt->execute([$id, $chatId]);
        return $stmt->rowCount() > 0;
    }

    public static function cancel(int $id, int $chatId): bool
    {
        $db = Database::get();
        $stmt = $db->prepare('UPDATE tasks SET status = "cancelled", updated_at = CURRENT_TIMESTAMP WHERE id = ? AND chat_id = ?');
        $stmt->execute([$id, $chatId]);
        return $stmt->rowCount() > 0;
    }

    public static function update(int $id, int $chatId, array $fields): bool
    {
        $db = Database::get();
        $sets = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($fields)));
        $values = array_values($fields);
        $values[] = $id;
        $values[] = $chatId;

        $stmt = $db->prepare("UPDATE tasks SET {$sets}, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND chat_id = ?");
        $stmt->execute($values);
        return $stmt->rowCount() > 0;
    }

    public static function dueToday(int $chatId): array
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT * FROM tasks WHERE chat_id = ? AND status NOT IN ("completed","cancelled") AND date(deadline) = date("now")'
        );
        $stmt->execute([$chatId]);
        return $stmt->fetchAll();
    }
}
