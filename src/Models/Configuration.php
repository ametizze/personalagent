<?php

namespace App\Models;

use App\Database\Database;

class Configuration
{
    public static function findByChatId(int $chatId): ?array
    {
        $db = Database::get();
        $stmt = $db->prepare('SELECT * FROM configurations WHERE chat_id = ?');
        $stmt->execute([$chatId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function upsert(int $chatId, array $data): void
    {
        $db = Database::get();
        $existing = self::findByChatId($chatId);

        if ($existing) {
            $sets = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($data)));
            $values = array_values($data);
            $values[] = $chatId;
            $db->prepare("UPDATE configurations SET {$sets}, updated_at = CURRENT_TIMESTAMP WHERE chat_id = ?")
               ->execute($values);
        } else {
            $data['chat_id'] = $chatId;
            $cols = implode(', ', array_keys($data));
            $placeholders = implode(', ', array_fill(0, count($data), '?'));
            $db->prepare("INSERT INTO configurations ({$cols}) VALUES ({$placeholders})")
               ->execute(array_values($data));
        }
    }

    /** Stored language preference for a chat, or null if not set. */
    public static function language(int $chatId): ?string
    {
        $config = self::findByChatId($chatId);
        return $config['language'] ?? null;
    }

    public static function timezone(int $chatId): ?string
    {
        $config = self::findByChatId($chatId);
        return $config['timezone'] ?? null;
    }

    /** Chat IDs that opted in to notifications (used by scheduled jobs). */
    public static function listAllActive(): array
    {
        $db = Database::get();
        $stmt = $db->prepare('SELECT * FROM configurations WHERE notifications_enabled = 1');
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
