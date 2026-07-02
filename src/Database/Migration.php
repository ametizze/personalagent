<?php

namespace App\Database;

use PDO;

/**
 * Idempotent schema bootstrap. All tables use `chat_id` (the Telegram chat /
 * user identifier) as the per-user key. The architecture is single-user first
 * but the `users` table and per-row `chat_id` make a multi-user evolution
 * straightforward.
 */
class Migration
{
    public function __construct(private PDO $db) {}

    public function run(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id INTEGER NOT NULL UNIQUE,
                username TEXT DEFAULT NULL,
                first_name TEXT DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS configurations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id INTEGER NOT NULL UNIQUE,
                name TEXT DEFAULT NULL,
                timezone TEXT DEFAULT NULL,
                language TEXT DEFAULT NULL,
                notifications_enabled INTEGER DEFAULT 1,
                verbose_mode INTEGER DEFAULT 0,
                system_context TEXT DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS conversations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id INTEGER NOT NULL,
                role TEXT NOT NULL CHECK(role IN ('user','assistant','system')),
                content TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_conversations_chat_id ON conversations(chat_id);

            CREATE TABLE IF NOT EXISTS memories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id INTEGER NOT NULL,
                content TEXT NOT NULL,
                category TEXT DEFAULT 'other' CHECK(category IN ('personal','preference','work','family','health','project','finance','other')),
                importance INTEGER DEFAULT 3,
                active INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_memories_chat_id ON memories(chat_id);

            CREATE TABLE IF NOT EXISTS calendar_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                description TEXT,
                scheduled_at DATETIME NOT NULL,
                timezone TEXT DEFAULT NULL,
                recurrence TEXT DEFAULT NULL,
                notified INTEGER DEFAULT 0,
                notify_chat_id INTEGER DEFAULT NULL,
                status TEXT DEFAULT 'active' CHECK(status IN ('active','completed','cancelled')),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_calendar_chat_id ON calendar_events(chat_id);
            CREATE INDEX IF NOT EXISTS idx_calendar_scheduled_at ON calendar_events(scheduled_at);

            CREATE TABLE IF NOT EXISTS reminders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id INTEGER NOT NULL,
                message TEXT NOT NULL,
                scheduled_at DATETIME NOT NULL,
                recurrence TEXT DEFAULT NULL,
                notified INTEGER DEFAULT 0,
                active INTEGER DEFAULT 1,
                notify_chat_id INTEGER DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_reminders_chat_id ON reminders(chat_id);
            CREATE INDEX IF NOT EXISTS idx_reminders_scheduled_at ON reminders(scheduled_at);

            CREATE TABLE IF NOT EXISTS tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                description TEXT,
                priority TEXT DEFAULT 'medium' CHECK(priority IN ('low','medium','high','urgent')),
                deadline DATETIME DEFAULT NULL,
                status TEXT DEFAULT 'pending' CHECK(status IN ('pending','in_progress','completed','cancelled')),
                tags TEXT DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_tasks_chat_id ON tasks(chat_id);

            CREATE TABLE IF NOT EXISTS ideas (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id INTEGER NOT NULL,
                category TEXT DEFAULT 'general',
                title TEXT NOT NULL,
                content TEXT NOT NULL,
                tags TEXT DEFAULT NULL,
                status TEXT DEFAULT 'active' CHECK(status IN ('active','archived','completed')),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_ideas_chat_id ON ideas(chat_id);

            CREATE TABLE IF NOT EXISTS notes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                content TEXT NOT NULL,
                category TEXT DEFAULT 'general',
                tags TEXT DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_notes_chat_id ON notes(chat_id);

            CREATE TABLE IF NOT EXISTS accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                type TEXT DEFAULT 'checking' CHECK(type IN ('checking','savings','cash','credit_card','other')),
                currency TEXT DEFAULT 'USD',
                balance REAL DEFAULT 0,
                active INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_accounts_chat_id ON accounts(chat_id);

            CREATE TABLE IF NOT EXISTS transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id INTEGER NOT NULL,
                account_id INTEGER NOT NULL,
                type TEXT NOT NULL CHECK(type IN ('income','expense','transfer','adjustment')),
                amount REAL NOT NULL,
                currency TEXT DEFAULT 'USD',
                category TEXT DEFAULT NULL,
                description TEXT DEFAULT NULL,
                occurred_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_transactions_chat_id ON transactions(chat_id);
            CREATE INDEX IF NOT EXISTS idx_transactions_account_id ON transactions(account_id);

            CREATE TABLE IF NOT EXISTS notification_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                reference_id INTEGER DEFAULT NULL,
                fired_for DATETIME DEFAULT NULL,
                sent_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_notification_logs_lookup ON notification_logs(chat_id, type, reference_id, fired_for);
        ");

        // Upgrade path for databases created before group mode (notify_chat_id).
        $this->ensureColumn('calendar_events', 'notify_chat_id', 'INTEGER DEFAULT NULL');
        $this->ensureColumn('reminders', 'notify_chat_id', 'INTEGER DEFAULT NULL');
    }

    /** Add a column only if it does not already exist (SQLite has no ADD COLUMN IF NOT EXISTS). */
    private function ensureColumn(string $table, string $column, string $definition): void
    {
        $existing = $this->db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array($column, $existing, true)) {
            $this->db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }
}
