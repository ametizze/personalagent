<?php

namespace App\Models;

use App\Database\Database;

/**
 * Registry of known Telegram users. Single-user first, but recording every
 * chat that interacts with the bot keeps the door open for multi-user mode.
 */
class User
{
    public static function touch(int $chatId, ?string $username = null, ?string $firstName = null): void
    {
        $db = Database::get();
        $stmt = $db->prepare('SELECT id FROM users WHERE chat_id = ?');
        $stmt->execute([$chatId]);

        if ($stmt->fetch()) {
            $db->prepare('UPDATE users SET username = COALESCE(?, username), first_name = COALESCE(?, first_name), updated_at = CURRENT_TIMESTAMP WHERE chat_id = ?')
               ->execute([$username, $firstName, $chatId]);
        } else {
            $db->prepare('INSERT INTO users (chat_id, username, first_name) VALUES (?, ?, ?)')
               ->execute([$chatId, $username, $firstName]);
        }
    }
}
