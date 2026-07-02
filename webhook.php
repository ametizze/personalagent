<?php
/**
 * Webhook mode — expose via HTTPS and register with Telegram.
 * Telegram sends a POST to this file for each incoming message.
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

$input = file_get_contents('php://input');
if (empty($input)) {
    http_response_code(200);
    exit;
}

$update = json_decode($input, true);
if (!$update) {
    http_response_code(200);
    exit;
}

LogService::debug("Update received via webhook", [
    'update_id' => $update['update_id'] ?? null,
    'type'      => implode(',', array_keys(array_diff_key($update, ['update_id' => 1]))),
    'chat'      => $update['message']['chat'] ?? $update['callback_query']['message']['chat'] ?? null,
    'has_text'  => isset($update['message']['text']),
]);

try {
    $telegram = new TelegramService();
    $openai   = new OpenAIService();
    $handler  = new CommandHandler($telegram, $openai, new IntentService($openai));
    $handler->handle($update);
} catch (\Throwable $e) {
    LogService::error("Webhook error", ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
}

http_response_code(200);
