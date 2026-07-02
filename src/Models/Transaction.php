<?php

namespace App\Models;

use App\Database\Database;

class Transaction
{
    /**
     * Record a transaction and keep the owning account balance in sync.
     * income/adjustment(+) increases the balance, expense decreases it.
     */
    public static function create(
        int $chatId,
        int $accountId,
        string $type,
        float $amount,
        string $currency = 'USD',
        ?string $category = null,
        ?string $description = null,
        ?string $occurredAt = null
    ): int {
        $db = Database::get();
        $occurredAt ??= date('Y-m-d H:i:s');
        $stmt = $db->prepare(
            'INSERT INTO transactions (chat_id, account_id, type, amount, currency, category, description, occurred_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$chatId, $accountId, $type, $amount, $currency, $category, $description, $occurredAt]);
        $id = (int)$db->lastInsertId();

        $delta = match ($type) {
            'income', 'adjustment' => $amount,
            'expense'              => -$amount,
            default                => 0.0, // transfers handled by two explicit entries
        };
        if ($delta !== 0.0) {
            Account::adjustBalance($accountId, $delta);
        }

        return $id;
    }

    public static function list(int $chatId, int $limit = 20): array
    {
        $db = Database::get();
        $stmt = $db->prepare('SELECT * FROM transactions WHERE chat_id = ? ORDER BY occurred_at DESC LIMIT ?');
        $stmt->execute([$chatId, $limit]);
        return $stmt->fetchAll();
    }

    /** Sum of a transaction type since a given datetime (defaults to start of current week). */
    public static function sumSince(int $chatId, string $type, string $since): float
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT COALESCE(SUM(amount), 0) AS total FROM transactions WHERE chat_id = ? AND type = ? AND occurred_at >= ?'
        );
        $stmt->execute([$chatId, $type, $since]);
        return (float)($stmt->fetch()['total'] ?? 0);
    }

    public static function listSince(int $chatId, string $since): array
    {
        $db = Database::get();
        $stmt = $db->prepare('SELECT * FROM transactions WHERE chat_id = ? AND occurred_at >= ? ORDER BY occurred_at DESC');
        $stmt->execute([$chatId, $since]);
        return $stmt->fetchAll();
    }
}
