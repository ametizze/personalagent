<?php

namespace App\Models;

use App\Database\Database;

class Idea
{
    public static function create(int $chatId, string $title, string $content, string $category = 'general', array $tags = []): int
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'INSERT INTO ideas (chat_id, title, content, category, tags) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$chatId, $title, $content, $category, implode(',', $tags)]);
        return (int)$db->lastInsertId();
    }

    public static function list(int $chatId, ?string $category = null, string $status = 'active', int $limit = 10): array
    {
        $db = Database::get();
        if ($category) {
            $stmt = $db->prepare(
                'SELECT * FROM ideas WHERE chat_id = ? AND category = ? AND status = ? ORDER BY created_at DESC LIMIT ?'
            );
            $stmt->execute([$chatId, $category, $status, $limit]);
        } else {
            $stmt = $db->prepare(
                'SELECT * FROM ideas WHERE chat_id = ? AND status = ? ORDER BY created_at DESC LIMIT ?'
            );
            $stmt->execute([$chatId, $status, $limit]);
        }
        return $stmt->fetchAll();
    }

    public static function search(int $chatId, string $term): array
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT * FROM ideas WHERE chat_id = ? AND (title LIKE ? OR content LIKE ? OR tags LIKE ?) AND status = "active" ORDER BY created_at DESC'
        );
        $like = "%{$term}%";
        $stmt->execute([$chatId, $like, $like, $like]);
        return $stmt->fetchAll();
    }

    public static function archive(int $id, int $chatId): bool
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'UPDATE ideas SET status = "archived", updated_at = CURRENT_TIMESTAMP WHERE id = ? AND chat_id = ?'
        );
        $stmt->execute([$id, $chatId]);
        return $stmt->rowCount() > 0;
    }

    public static function categories(int $chatId): array
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT category, COUNT(*) as total FROM ideas WHERE chat_id = ? AND status = "active" GROUP BY category ORDER BY total DESC'
        );
        $stmt->execute([$chatId]);
        return $stmt->fetchAll();
    }
}
