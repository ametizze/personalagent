<?php

namespace App\Support;

/**
 * Normalizes and validates user/AI-provided values against the enums used by
 * the database CHECK constraints. Returns a safe default rather than throwing.
 */
class Validator
{
    public static function priority(?string $value): string
    {
        $value = strtolower(trim((string)$value));
        return in_array($value, ['low', 'medium', 'high', 'urgent'], true) ? $value : 'medium';
    }

    public static function taskStatus(?string $value): string
    {
        $value = strtolower(trim((string)$value));
        $value = str_replace([' ', '-'], '_', $value);
        return in_array($value, ['pending', 'in_progress', 'completed', 'cancelled', 'all'], true) ? $value : 'pending';
    }

    /** Returns a valid recurrence string or null (for one-off items). */
    public static function recurrence(?string $value): ?string
    {
        $value = strtolower(trim((string)$value));
        if ($value === '' || $value === 'none' || $value === 'null') {
            return null;
        }
        return in_array($value, ['daily', 'weekly', 'biweekly', 'monthly'], true) ? $value : null;
    }

    public static function accountType(?string $value): string
    {
        $value = strtolower(trim((string)$value));
        return in_array($value, ['checking', 'savings', 'cash', 'credit_card', 'other'], true) ? $value : 'checking';
    }

    public static function transactionType(?string $value): string
    {
        $value = strtolower(trim((string)$value));
        return in_array($value, ['income', 'expense', 'transfer', 'adjustment'], true) ? $value : 'expense';
    }

    public static function timezone(?string $value): ?string
    {
        $value = trim((string)$value);
        return in_array($value, timezone_identifiers_list(), true) ? $value : null;
    }

    /** Trim and collapse whitespace; cap length to protect the database. */
    public static function text(?string $value, int $maxLength = 2000): string
    {
        $value = trim((string)$value);
        $value = (string)preg_replace('/\s+/u', ' ', $value);
        return mb_substr($value, 0, $maxLength);
    }

    /** @param mixed $tags array or comma string */
    public static function tags($tags): array
    {
        if (is_string($tags)) {
            $tags = explode(',', $tags);
        }
        if (!is_array($tags)) {
            return [];
        }
        return array_values(array_filter(array_map(fn($t) => trim((string)$t), $tags)));
    }
}
