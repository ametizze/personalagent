<?php

namespace App\Database;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private static ?PDO $instance = null;
    private static string $path = '';

    public static function connect(string $path): PDO
    {
        if (self::$instance === null || self::$path !== $path) {
            self::$path = $path;
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            try {
                self::$instance = new PDO("sqlite:{$path}");
                self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                self::$instance->exec('PRAGMA journal_mode=WAL');
                self::$instance->exec('PRAGMA foreign_keys=ON');
            } catch (PDOException $e) {
                throw new RuntimeException("Failed to connect to database: " . $e->getMessage());
            }
        }

        return self::$instance;
    }

    public static function get(): PDO
    {
        if (self::$instance === null) {
            throw new RuntimeException("Database not initialized. Call connect() first.");
        }
        return self::$instance;
    }

    /** Drop the current connection. Primarily used to isolate tests. */
    public static function reset(): void
    {
        self::$instance = null;
        self::$path = '';
    }
}
