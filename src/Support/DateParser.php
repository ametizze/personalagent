<?php

namespace App\Support;

/**
 * Normalizes datetime strings (typically already resolved by the intent model)
 * into the canonical "Y-m-d H:i:s" storage format. Falls back to PHP's relative
 * parsing for simple inputs so deterministic paths don't require an AI call.
 */
class DateParser
{
    /**
     * Interpret a wall-clock datetime (in the user's timezone) and return it in
     * UTC "Y-m-d H:i:s". All datetimes are stored in UTC so cron comparisons
     * against SQLite's UTC datetime('now') are correct regardless of timezone.
     *
     * @return string|null canonical UTC "Y-m-d H:i:s" or null if unparseable
     */
    public static function toUtc(?string $value, ?string $timezone = null): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        try {
            $local = new \DateTimeZone($timezone ?? ($_ENV['APP_TIMEZONE'] ?? 'UTC'));
            $dt = new \DateTime($value, $local); // an explicit offset in $value wins over $local
            $dt->setTimezone(new \DateTimeZone('UTC'));
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    /** Format a stored UTC datetime in the user's timezone for display. */
    public static function toLocal(string $utcValue, ?string $timezone = null, string $pattern = 'Y-m-d H:i'): string
    {
        try {
            $dt = new \DateTime($utcValue, new \DateTimeZone('UTC'));
            $dt->setTimezone(new \DateTimeZone($timezone ?? ($_ENV['APP_TIMEZONE'] ?? 'UTC')));
            return $dt->format($pattern);
        } catch (\Throwable) {
            return $utcValue;
        }
    }

    /** @deprecated use toUtc(). Kept for backward compatibility. */
    public static function normalize(?string $value, ?string $timezone = null): ?string
    {
        return self::toUtc($value, $timezone);
    }

    /** Format a datetime as-is (no timezone conversion). */
    public static function format(string $value, string $pattern = 'Y-m-d H:i'): string
    {
        try {
            return (new \DateTime($value))->format($pattern);
        } catch (\Throwable) {
            return $value;
        }
    }

    /** Start of the current week (Monday 00:00:00) in storage format. */
    public static function startOfWeek(?string $timezone = null): string
    {
        $tz = new \DateTimeZone($timezone ?? ($_ENV['APP_TIMEZONE'] ?? 'UTC'));
        $dt = new \DateTime('monday this week', $tz);
        return $dt->format('Y-m-d 00:00:00');
    }
}
