<?php

namespace App\Models;

use App\Database\Database;

class Memory
{
    public static function create(int $chatId, string $content, string $category = 'other', int $importance = 3): int
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'INSERT INTO memories (chat_id, content, category, importance) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$chatId, $content, $category, $importance]);
        return (int)$db->lastInsertId();
    }

    public static function list(int $chatId, int $limit = 50): array
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT * FROM memories WHERE chat_id = ? AND active = 1 ORDER BY importance DESC, created_at DESC LIMIT ?'
        );
        $stmt->execute([$chatId, $limit]);
        return $stmt->fetchAll();
    }

    /** Soft-deactivate a memory (forget). */
    public static function forget(int $id, int $chatId): bool
    {
        $db = Database::get();
        $stmt = $db->prepare('UPDATE memories SET active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND chat_id = ?');
        $stmt->execute([$id, $chatId]);
        return $stmt->rowCount() > 0;
    }

    /** Find active memories whose content matches a term (used by "forget that ...."). */
    public static function search(int $chatId, string $term): array
    {
        $db = Database::get();
        $stmt = $db->prepare('SELECT * FROM memories WHERE chat_id = ? AND active = 1 AND content LIKE ? ORDER BY created_at DESC');
        $stmt->execute([$chatId, "%{$term}%"]);
        return $stmt->fetchAll();
    }
}
