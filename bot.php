<?php
/**
 * Polling mode (long-polling) — use for development or without HTTPS.
 * php bot.php
 */

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database\{Database, Migration};
use App\Services\{TelegramService, OpenAIService, IntentService, LogService};
use App\Commands\CommandHandler;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'UTC');
LogService::init(__DIR__ . '/storage/logs/app.log');
$db = Database::connect(__DIR__ . '/' . ($_ENV['DB_PATH'] ?? 'storage/bot.sqlite'));
(new Migration($db))->run();

if ((int)($_ENV['ALLOWED_USER_ID'] ?? 0) === 0) {
    LogService::info('⚠️ ALLOWED_USER_ID=0 — access restriction disabled, the bot will reply to ANY user.');
}

$telegram = new TelegramService();
$openai   = new OpenAIService();
$handler  = new CommandHandler($telegram, $openai, new IntentService($openai));

LogService::info("Bot started in polling mode. Waiting for messages...");

$telegram->deleteWebhook();

$offset = 0;

while (true) {
    try {
        $updates = $telegram->getUpdates($offset);

        foreach ($updates as $update) {
            $offset = $update['update_id'] + 1;

            try {
                $handler->handle($update);
            } catch (\Throwable $e) {
                LogService::error("Error processing update", [
                    'update_id' => $update['update_id'],
                    'error'     => $e->getMessage(),
                ]);
            }
        }
    } catch (\Throwable $e) {
        LogService::error("Error in polling loop", ['error' => $e->getMessage()]);
        sleep(5);
    }
}
