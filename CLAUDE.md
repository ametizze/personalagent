# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Development (no HTTPS required, long-polling)
php bot.php

# Production (webhook — requires HTTPS)
php webhook.php                  # exposed via web server
php setup-webhook.php            # register the URL with Telegram
php setup-webhook.php remove     # unregister the webhook

# Database
php cron/migrate.php             # create/update SQLite tables

# CRON (configure in crontab to run every minute)
* * * * * php /path/to/personalagent/cron/scheduler.php >> storage/logs/cron.log 2>&1

# Tests
vendor/bin/phpunit               # all tests
vendor/bin/phpunit --testdox     # human-readable output
vendor/bin/phpunit --filter TestName   # single test

# Composer shortcuts
composer run bot                 # php bot.php
composer run migrate             # php cron/migrate.php
composer run test                # phpunit
```

## Architecture

**Two operation modes:**
- `bot.php` — long-polling: loops calling `getUpdates`. Best for local development.
- `webhook.php` — receives POST from Telegram via HTTPS. Required for production.

**Message flow:**
1. `bot.php` / `webhook.php` builds `CommandHandler(TelegramService, OpenAIService, IntentService)`.
2. `CommandHandler::handle()` checks authentication via `ALLOWED_USER_ID`, records the user (`User::touch`), and resolves the reply language via `LanguageService::resolve()` (stored preference → detected from message → `APP_DEFAULT_LANGUAGE`).
3. Slash commands (English + multilingual aliases via `CommandHandler::ALIASES`) route directly to `cmd*` methods. No AI call.
4. Free-text messages go through `IntentService::extractIntent()` → strict JSON `{intent, confidence, language, data}`.
5. The handler routes the intent to create/list/cancel methods for calendar, tasks, reminders, ideas, notes, finance, and memory. Low confidence (`< 0.4`) or `free_chat`/`unknown` falls back to `OpenAIService::chat()`.

**Language support:** Default `APP_DEFAULT_LANGUAGE` (English). `LanguageService` does deterministic detection for `en`, `pt-BR`, `es` (no API call) and holds the translation table. Free-chat replies are steered to the user's language via the system prompt. Stored content keeps the user's original language.

**Memory (two layers):**
- Short-term: `conversations` table keeps the last 20 turns per `chat_id`, replayed to every `chat()` call. Reset via `/clear`.
- Long-term: `memories` table, written **only** when the user explicitly asks ("remember that…"). `MemoryService::forContext()` injects active memories into the chat system prompt. Forget via `/memory forget <id>` or natural language.

**CRON (`cron/scheduler.php`, every minute):**
- `NotificationsJob` — 15-minute calendar alerts + due reminders, deduped via `notification_logs`, recurring reminders auto-rescheduled.
- `DailySummaryJob` — fires at 07:00 in **each user's own timezone** (deduped per day via `notification_logs`), building the briefing with `SummaryService` (deterministic data + one optional AI suggestion).

## Database (SQLite)

File at `storage/bot.sqlite`. All tables key on `chat_id` (single-user first; `users` table + per-row `chat_id` allow multi-user evolution). Tables: `users`, `configurations`, `conversations`, `memories`, `calendar_events`, `reminders`, `tasks`, `ideas`, `notes`, `accounts`, `transactions`, `notification_logs`.

Models use the `Database::get()` singleton; they never open their own connection. `Transaction::create()` keeps the owning `Account` balance in sync.

## Intent extraction (OpenAI)

`IntentService::extract()` (temperature 0.1, JSON only) returns `{intent, confidence, language, data}`. Supported intents include `create_*/list_*/update_*` for calendar, task, reminder, idea, note; finance (`set_account_balance`, `create_transaction`, `get_balance_summary`); memory (`create_memory`, `list_memories`, `forget_memory`); plus `daily_summary`, `free_chat`, `unknown`. Destructive actions (cancel event/task, delete note) ask for confirmation via inline buttons before executing.

## Group mode (per-person data)

`ChatContext` (`Support/ChatContext`) separates two identities: `ownerId` (`message.from.id`, the per-person data key used for every model/query/config) and `replyChatId` (`message.chat.id`, where answers go). In a private chat they're equal, so single-user behaviour is unchanged; in a group, each member's data is scoped to their own user id while replies post to the group.

- Auth: `userAllowed()` checks `ALLOWED_USER_ID` + `ALLOWED_USER_IDS` (empty = no restriction); groups must be whitelisted in `ALLOWED_GROUP_IDS` (empty = DM only). Unlisted groups/members are ignored silently; DM rejections still send the "unauthorized" message.
- Commands strip a `@botname` suffix; group free-text is processed only when the bot is addressed (`@BOT_USERNAME` mention or reply), gated by `addressedInGroup()`.
- `reminders` and `calendar_events` carry `notify_chat_id` (origin chat) so scheduled alerts fire where created; the daily summary goes to the owner's DM. Callbacks scope to `callback.from.id`.

## Security

`ALLOWED_USER_ID`/`ALLOWED_USER_IDS` restrict access by Telegram user ID; `ALLOWED_GROUP_IDS` whitelists groups. `0`/empty disables the user restriction and logs a warning at startup. All writes use PDO prepared statements; AI/user input is normalized via `Support\Validator`.

## Configuration (.env required)

Copy `.env.example` to `.env`. Critical fields: `TELEGRAM_BOT_TOKEN`, `OPENAI_API_KEY`, `OPENAI_MODEL`, `ALLOWED_USER_ID`, `APP_DEFAULT_LANGUAGE`, `APP_SUPPORTED_LANGUAGES`, `APP_TIMEZONE`, and `TELEGRAM_WEBHOOK_URL` (webhook mode only).
