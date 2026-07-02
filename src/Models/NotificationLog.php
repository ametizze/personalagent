<?php

namespace App\Models;

use App\Database\Database;

/**
 * Audit trail of every notification sent. Also used to guarantee a given
 * notification (e.g. the daily summary for a date) is delivered only once.
 */
class NotificationLog
{
    public static function record(int $chatId, string $type, ?int $referenceId = null, ?string $firedFor = null): void
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'INSERT INTO notification_logs (chat_id, type, reference_id, fired_for) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$chatId, $type, $referenceId, $firedFor]);
    }

    public static function wasSent(int $chatId, string $type, ?int $referenceId = null, ?string $firedFor = null): bool
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT 1 FROM notification_logs
             WHERE chat_id = ? AND type = ?
               AND (reference_id IS ? OR reference_id = ?)
               AND (fired_for IS ? OR fired_for = ?)
             LIMIT 1'
        );
        $stmt->execute([$chatId, $type, $referenceId, $referenceId, $firedFor, $firedFor]);
        return (bool)$stmt->fetchColumn();
    }
}
