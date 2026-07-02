<?php
/**
 * Register or remove the webhook on Telegram.
 * php setup-webhook.php
 * php setup-webhook.php remove
 */

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Services\TelegramService;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$telegram = new TelegramService();
$action   = $argv[1] ?? 'set';

if ($action === 'remove') {
    $result = $telegram->deleteWebhook();
    echo "Webhook removed: " . json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    exit;
}

$url = $_ENV['TELEGRAM_WEBHOOK_URL'] ?? '';
if (empty($url)) {
    echo "TELEGRAM_WEBHOOK_URL not defined in .env" . PHP_EOL;
    exit(1);
}

$result = $telegram->setWebhook($url);
echo "Webhook configured: " . json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;

$info = $telegram->getWebhookInfo();
echo "Webhook info: " . json_encode($info, JSON_PRETTY_PRINT) . PHP_EOL;
