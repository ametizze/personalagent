# PersonalAgent (Archived Study)

> [!NOTE]
> **Project Status: Archived / Study Only**
> 
> This project was developed solely as a practical and experimental study related to autonomous agent models (**Agentic**) and integrations of AI assistant frameworks like **Open Claw**. Currently, this repository is archived and not actively maintained.

> Your AI-powered personal assistant, running on Telegram.

PersonalAgent connects Telegram to OpenAI so you can manage your calendar, tasks, reminders, ideas, notes, and personal finance through natural language — no forms, no apps, just a conversation. It remembers what matters, replies in your language, and sends automatic notifications via cron even when you're not chatting.

---

## Features

- **Natural language** — just talk; the AI extracts intent and acts. Simple commands run without an AI call.
- **Multilingual** — English (default), Brazilian Portuguese, and Spanish. The bot detects your language and replies in it; stored content keeps its original language.
- **Calendar** — schedule events, get 15-minute advance alerts.
- **Tasks** — priorities (low / medium / high / urgent) and statuses, with deadlines and tags.
- **Reminders** — one-time or recurring (daily / weekly / biweekly / monthly).
- **Ideas & notes** — capture future concepts (ideas) and factual references (notes).
- **Personal finance** — accounts and transactions, weekly income/expense summaries.
- **Memory** — short-term (last 20 conversation turns) and long-term ("remember that…").
- **Daily summary** — morning briefing at 7 AM in *your* timezone, with an AI suggestion.
- **Confirmations** — destructive actions (cancel/delete) ask before executing.
- **Two modes** — polling for development, webhook for production.

---

## Requirements

- PHP 8.4+ (with `pdo_sqlite` and `curl`)
- Composer
- A [Telegram](https://telegram.org) bot token from [@BotFather](https://t.me/BotFather)
- An [OpenAI](https://platform.openai.com) API key
- A server with HTTPS (for production webhook mode)

---

## Quick Start

```bash
git clone https://github.com/your-username/personalagent.git
cd personalagent
composer install
cp .env.example .env   # then fill in your keys
php cron/migrate.php
php bot.php
```

---

## Configuration

Copy `.env.example` to `.env` and fill in the values.

### Required

| Variable | Description | How to get |
|---|---|---|
| `TELEGRAM_BOT_TOKEN` | Your bot's token | [@BotFather](https://t.me/BotFather) → `/newbot` |
| `OPENAI_API_KEY` | Your OpenAI API key | [platform.openai.com/api-keys](https://platform.openai.com/api-keys) |
| `ALLOWED_USER_ID` | Your Telegram user ID | [@userinfobot](https://t.me/userinfobot) |

> Set `ALLOWED_USER_ID=0` to disable the restriction — a warning is logged at startup. **Not recommended in production.**

### Group mode (optional)

The bot can run inside Telegram groups with **per-person data**: each member's tasks, reminders, calendar, notes, finance, and memory are scoped to their own Telegram user id, while replies are posted in the group. In a private chat nothing changes.

| Variable | Default | Description |
|---|---|---|
| `ALLOWED_USER_IDS` | — | Extra allowed user ids (comma-separated), combined with `ALLOWED_USER_ID` |
| `ALLOWED_GROUP_IDS` | — | Group chat ids the bot may operate in (comma-separated, negative numbers). Empty = groups disabled (DM only) |
| `BOT_USERNAME` | — | Your bot's `@username` (without `@`). Needed for free-text mentions/replies in groups |

How it behaves in a group:

- **Commands** always work (e.g. `/tasks`, `/tasks@YourBot`). The `@botname` suffix is handled.
- **Free text** is processed only when the bot is addressed — an `@BOT_USERNAME` mention or a reply to one of its messages — so it stays quiet otherwise. This requires `BOT_USERNAME` to be set **and** privacy mode disabled in [@BotFather](https://t.me/BotFather) (`/setprivacy` → *Disable*).
- **Reminders and calendar alerts** fire in the group where they were created; the **daily summary** is personal and goes to the user's DM (so they must have started the bot privately once).
- Inline confirmation buttons (cancel/delete) only act on the **presser's** own items.

Get a group's id by adding the bot and checking the logs, or via [@userinfobot](https://t.me/userinfobot) in the group.

### Optional

| Variable | Default | Description |
|---|---|---|
| `APP_DEFAULT_LANGUAGE` | `en` | Onboarding/fallback language (`en`, `pt-BR`, `es`) |
| `APP_SUPPORTED_LANGUAGES` | `en,pt-BR,es` | Comma-separated supported languages |
| `APP_TIMEZONE` | `UTC` | Default timezone for dates and notifications |
| `OPENAI_MODEL` | `gpt-4o` | OpenAI model to use |
| `OPENAI_MAX_TOKENS` | `2000` | Max tokens per response |
| `OPENAI_TEMPERATURE` | `0.3` | Temperature for summaries/suggestions |
| `DB_PATH` | `storage/bot.sqlite` | Path to the SQLite file |
| `APP_ENV` | `production` | Environment (`production` / `development` / `testing`) |
| `LOG_LEVEL` | `info` | Log verbosity (`info` or `debug`) |
| `TELEGRAM_WEBHOOK_URL` | — | Public HTTPS URL (webhook mode only) |

---

## Operation Modes

### Polling — development

No HTTPS required. The bot loops and polls Telegram for new messages.

```bash
php bot.php
```

### Webhook — production

Requires HTTPS. Telegram pushes each message to your URL.

```bash
# 1. Set TELEGRAM_WEBHOOK_URL in .env
php setup-webhook.php          # register the webhook
# 2. Point your web server at webhook.php
php setup-webhook.php remove   # unregister
```

---

## Bot Commands

Canonical commands are English; common aliases in Portuguese and Spanish are accepted.

| Command | Aliases | Description |
|---|---|---|
| `/start` | `/iniciar`, `/comenzar` | Initialize the bot and enable notifications |
| `/help` | `/ajuda`, `/ayuda` | List commands with examples |
| `/today` | `/hoje`, `/hoy` | Today's briefing |
| `/summary` | `/resumo`, `/resumen` | Full daily briefing |
| `/agenda [days]` | `/calendario` | Upcoming events (default 7 days) |
| `/tasks [status\|priority]` | `/tarefas`, `/tareas` | Tasks by status (`pending`…) or priority (`urgent`…) |
| `/reminders [recurring]` | `/lembretes`, `/recordatorios` | Active reminders |
| `/ideas [category]` | `/ideias` | Ideas, optionally filtered |
| `/notes [term]` | `/notas` | Notes, optionally searched |
| `/balance` | `/saldo`, `/financas`, `/finanzas` | Finance summary |
| `/memory [forget <id>]` | `/memoria` | Long-term memory |
| `/config [key value]` | `/configuracao`, `/configuracion` | View or update settings |
| `/clear` | `/limpar`, `/limpiar` | Reset conversation context |

### `/config` options

```
/config                              → show current settings
/config name John                    → set your name
/config timezone America/New_York    → change timezone
/config language pt-BR               → set reply language (en|pt-BR|es)
/config notifications on|off         → toggle automatic notifications
/config context I'm a PHP freelancer → inject custom context into the AI prompt
```

---

## Natural Language

Any message that isn't a `/command` is parsed by the AI into a structured intent (`{intent, confidence, language, data}`). Clear, high-confidence actions execute immediately; low-confidence or general messages fall back to free conversation with full history and long-term memory.

```
# Calendar
Schedule a client meeting tomorrow at 2 PM
Book lunch with Ana on June 15 at noon

# Reminders
Remind me to call the bank on Friday at 9 AM
Remind me every day at 8 AM to drink water

# Tasks
Create an urgent task to review the contract before tomorrow
I need to finish the report by Friday

# Ideas & notes
I have an idea: build an app to track personal expenses
Take a note: restart Laravel queue workers after deploy

# Finance
Set my checking balance to 1200 dollars
Add expense: 75 dollars for gas
How much money do I have available?

# Memory
Remember that my timezone is America/New_York
Forget that I work with Laravel
```

Task priorities: `low` · `medium` · `high` · `urgent`.

---

## CRON Notifications

`cron/scheduler.php` runs every minute and drives all automated actions.

```bash
crontab -e
```

```
* * * * * php /full/path/to/personalagent/cron/scheduler.php >> /full/path/to/personalagent/storage/logs/cron.log 2>&1
```

| Schedule | Action |
|---|---|
| Every minute | Send due reminders (1-minute tolerance) |
| Every minute | Send 15-minute advance alerts for calendar events |
| 07:00 in each user's timezone | Morning summary for users with notifications enabled |

Notifications are deduplicated via the `notification_logs` table. Recurring reminders auto-reschedule after firing: `daily` (+1 day), `weekly` (+7 days), `biweekly` (+15 days), `monthly` (+1 month).

---

## Architecture

```
personalagent/
├── bot.php              # Entry point — polling mode (development)
├── webhook.php          # Entry point — webhook mode (production)
├── setup-webhook.php    # Register/unregister the Telegram webhook
├── cron/
│   ├── scheduler.php    # Crontab entry point (runs every minute)
│   └── migrate.php      # Create or update database tables
└── src/
    ├── Commands/CommandHandler.php   # Central router: commands + intents
    ├── Database/                     # PDO singleton + schema
    ├── Jobs/                         # NotificationsJob, DailySummaryJob
    ├── Models/                       # CalendarEvent, Reminder, Task, Idea, Note,
    │                                 # Account, Transaction, Memory, Conversation,
    │                                 # Configuration, User, NotificationLog
    ├── Services/                     # OpenAI, Telegram, Intent, Language,
    │                                 # Memory, Summary, Log
    └── Support/                      # DateParser, ResponseFormatter, Validator
```

### Message flow

```
Telegram → bot.php / webhook.php → CommandHandler::handle()
   ├── Verify ALLOWED_USER_ID, record user, resolve language
   ├── /command (+ aliases) → cmd*()  → direct response (no AI)
   └── Free text → IntentService::extract() → {intent, confidence, language, data}
           ├── create_* / list_* / cancel_* → calendar, task, reminder, idea, note,
           │   finance, memory  (+ confirmation for destructive actions)
           └── free_chat / low confidence → OpenAIService::chat()
                 (last 20 turns + long-term memory in the system prompt)
```

---

## Database

SQLite at `storage/bot.sqlite`, created on first run. Every table keys on `chat_id`.

| Table | Purpose |
|---|---|
| `users` | Known Telegram users |
| `configurations` | Per-user settings (name, timezone, language, notifications, context) |
| `conversations` | Short-term memory (last 20 turns per chat) |
| `memories` | Long-term memory (explicitly remembered facts) |
| `calendar_events` | Events with recurrence, status, notification flag |
| `reminders` | One-time and recurring reminders |
| `tasks` | Tasks with priority, status, deadline, tags |
| `ideas` | Future concepts with category and tags |
| `notes` | Factual references with category and tags |
| `accounts` | Finance accounts with balance |
| `transactions` | Income/expense/adjustment entries (keep account balance in sync) |
| `notification_logs` | Audit trail + duplicate protection |

---

## Testing

```bash
vendor/bin/phpunit            # all tests
vendor/bin/phpunit --testdox  # human-readable
```

Tests use an in-memory SQLite database and fakes for OpenAI (`OpenAI\Testing\ClientFake`) and Telegram (`Tests\Support\SpyTelegramService`). No test touches the real OpenAI or Telegram APIs.

---

## Security

- Access is restricted to `ALLOWED_USER_ID` (single-user by default).
- All database writes use PDO prepared statements; AI/user input is normalized via `Support\Validator`.
- Long-term memory is written only when the user explicitly asks.
- Never commit `.env`. Deny web access to `.env`, `storage/`, `vendor/`, and `.git/`.
- Secrets are never echoed back to the user or into Telegram messages.
