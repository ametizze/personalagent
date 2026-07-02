<?php

namespace App\Models;

use App\Database\Database;

/**
 * Short-term conversation memory: the last N turns per chat are stored and
 * replayed to the model as context.
 */
class Conversation
{
    public static function add(int $chatId, string $role, string $content): void
    {
        $db = Database::get();
        $stmt = $db->prepare('INSERT INTO conversations (chat_id, role, content) VALUES (?, ?, ?)');
        $stmt->execute([$chatId, $role, $content]);
    }

    /** Most recent turns in chronological (oldest-first) order. */
    public static function recent(int $chatId, int $limit = 20): array
    {
        $db = Database::get();
        $stmt = $db->prepare('SELECT role, content FROM conversations WHERE chat_id = ? ORDER BY id DESC LIMIT ?');
        $stmt->execute([$chatId, $limit]);
        return array_reverse($stmt->fetchAll());
    }

    public static function clear(int $chatId): void
    {
        Database::get()->prepare('DELETE FROM conversations WHERE chat_id = ?')->execute([$chatId]);
    }
}
