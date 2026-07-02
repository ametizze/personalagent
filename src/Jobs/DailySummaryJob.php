<?php

namespace App\Jobs;

use App\Models\{Configuration, NotificationLog};
use App\Services\{TelegramService, OpenAIService, SummaryService, LanguageService, LogService};

/**
 * Sends the morning briefing at 07:00 in each user's own timezone. Runs every
 * minute via the scheduler; NotificationLog dedupe guarantees one send per day.
 */
class DailySummaryJob
{
    public function __construct(
        private TelegramService $telegram,
        private ?OpenAIService $openai = null
    ) {}

    public function run(): void
    {
        foreach (Configuration::listAllActive() as $config) {
            $chatId = (int)$config['chat_id'];
            try {
                $this->maybeSend($chatId, $config);
            } catch (\Throwable $e) {
                LogService::error('Daily summary error', ['chat_id' => $chatId, 'error' => $e->getMessage()]);
            }
        }
    }

    private function maybeSend(int $chatId, array $config): void
    {
        $tz = new \DateTimeZone($config['timezone'] ?? ($_ENV['APP_TIMEZONE'] ?? 'UTC'));
        $now = new \DateTime('now', $tz);

        if ((int)$now->format('H') !== 7) {
            return;
        }

        $today = $now->format('Y-m-d');
        if (NotificationLog::wasSent($chatId, 'daily_summary', null, $today)) {
            return;
        }

        $lang = ($config['language'] && LanguageService::isSupported($config['language']))
            ? $config['language']
            : LanguageService::default();

        $summary = (new SummaryService($this->openai))->build($chatId, $lang);
        $this->telegram->sendMessage($chatId, $summary);
        NotificationLog::record($chatId, 'daily_summary', null, $today);
        LogService::info('Daily summary sent', ['chat_id' => $chatId]);
    }
}
