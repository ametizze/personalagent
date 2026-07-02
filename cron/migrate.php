<?php
/**
 * Run once to create all tables:
 * php cron/migrate.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database\{Database, Migration};
use App\Services\LogService;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

LogService::init(__DIR__ . '/../storage/logs/app.log');

$db = Database::connect(__DIR__ . '/../' . ($_ENV['DB_PATH'] ?? 'storage/bot.sqlite'));
(new Migration($db))->run();

echo "✅ Migrations executed successfully!" . PHP_EOL;
