<?php
/**
 * CRON Scheduler — run every minute:
 * * * * * php /path/to/personalagent/cron/scheduler.php >> storage/logs/cron.log 2>&1
 *
 * - NotificationsJob always runs: 15-minute calendar alerts + due reminders.
 * - DailySummaryJob also runs every minute but only fires at 07:00 in each
 *   user's own timezone (deduplicated per day via notification_logs).
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database\{Database, Migration};
use App\Services\{TelegramService, OpenAIService, LogService};
use App\Jobs\{NotificationsJob, DailySummaryJob};

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'UTC');

LogService::init(__DIR__ . '/../storage/logs/cron.log');
$db = Database::connect(__DIR__ . '/../' . ($_ENV['DB_PATH'] ?? 'storage/bot.sqlite'));
(new Migration($db))->run();

$telegram = new TelegramService();
$openai   = new OpenAIService();

(new NotificationsJob($telegram))->run();
(new DailySummaryJob($telegram, $openai))->run();
