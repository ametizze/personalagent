<?php

namespace App\Models;

use App\Database\Database;

class Account
{
    public static function create(int $chatId, string $name, string $type = 'checking', string $currency = 'USD', float $balance = 0.0): int
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'INSERT INTO accounts (chat_id, name, type, currency, balance) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$chatId, $name, $type, $currency, $balance]);
        return (int)$db->lastInsertId();
    }

    public static function list(int $chatId): array
    {
        $db = Database::get();
        $stmt = $db->prepare('SELECT * FROM accounts WHERE chat_id = ? AND active = 1 ORDER BY name ASC');
        $stmt->execute([$chatId]);
        return $stmt->fetchAll();
    }

    public static function find(int $id, int $chatId): ?array
    {
        $db = Database::get();
        $stmt = $db->prepare('SELECT * FROM accounts WHERE id = ? AND chat_id = ?');
        $stmt->execute([$id, $chatId]);
        return $stmt->fetch() ?: null;
    }

    /** Find an account by (case-insensitive) name, or the first account if name is null. */
    public static function findByName(int $chatId, ?string $name): ?array
    {
        $db = Database::get();
        if ($name === null || $name === '') {
            $stmt = $db->prepare('SELECT * FROM accounts WHERE chat_id = ? AND active = 1 ORDER BY id ASC LIMIT 1');
            $stmt->execute([$chatId]);
        } else {
            $stmt = $db->prepare('SELECT * FROM accounts WHERE chat_id = ? AND active = 1 AND LOWER(name) = LOWER(?) LIMIT 1');
            $stmt->execute([$chatId, $name]);
        }
        return $stmt->fetch() ?: null;
    }

    public static function setBalance(int $id, int $chatId, float $balance): bool
    {
        $db = Database::get();
        $stmt = $db->prepare('UPDATE accounts SET balance = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND chat_id = ?');
        $stmt->execute([$balance, $id, $chatId]);
        return $stmt->rowCount() > 0;
    }

    public static function adjustBalance(int $id, float $delta): void
    {
        $db = Database::get();
        $db->prepare('UPDATE accounts SET balance = balance + ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
           ->execute([$delta, $id]);
    }

    public static function totalBalance(int $chatId): float
    {
        $db = Database::get();
        $stmt = $db->prepare('SELECT COALESCE(SUM(balance), 0) AS total FROM accounts WHERE chat_id = ? AND active = 1');
        $stmt->execute([$chatId]);
        return (float)($stmt->fetch()['total'] ?? 0);
    }
}
