<?php

namespace App\Models;

use App\Database\Database;

class Note
{
    public static function create(int $chatId, string $title, string $content, string $category = 'general', array $tags = []): int
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'INSERT INTO notes (chat_id, title, content, category, tags) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$chatId, $title, $content, $category, implode(',', $tags)]);
        return (int)$db->lastInsertId();
    }

    public static function list(int $chatId, ?string $term = null, int $limit = 10): array
    {
        $db = Database::get();
        if ($term !== null && $term !== '') {
            $like = "%{$term}%";
            $stmt = $db->prepare(
                'SELECT * FROM notes WHERE chat_id = ? AND (title LIKE ? OR content LIKE ? OR tags LIKE ? OR category LIKE ?)
                 ORDER BY created_at DESC LIMIT ?'
            );
            $stmt->execute([$chatId, $like, $like, $like, $like, $limit]);
        } else {
            $stmt = $db->prepare('SELECT * FROM notes WHERE chat_id = ? ORDER BY created_at DESC LIMIT ?');
            $stmt->execute([$chatId, $limit]);
        }
        return $stmt->fetchAll();
    }

    public static function delete(int $id, int $chatId): bool
    {
        $db = Database::get();
        $stmt = $db->prepare('DELETE FROM notes WHERE id = ? AND chat_id = ?');
        $stmt->execute([$id, $chatId]);
        return $stmt->rowCount() > 0;
    }
}
