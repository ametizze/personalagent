<?php

namespace App\Jobs;

use App\Models\{CalendarEvent, Reminder, Configuration, NotificationLog};
use App\Services\{TelegramService, LanguageService, LogService};
use App\Support\DateParser;

class NotificationsJob
{
    public function __construct(private TelegramService $telegram) {}

    public function run(): void
    {
        $this->notifyCalendar();
        $this->notifyReminders();
    }

    private function notifyCalendar(): void
    {
        foreach (CalendarEvent::pendingNotification() as $event) {
            $chatId = (int)$event['chat_id'];
            $target = (int)($event['notify_chat_id'] ?? 0) ?: $chatId;
            $id = (int)$event['id'];
            if (NotificationLog::wasSent($chatId, 'calendar', $id)) {
                CalendarEvent::markNotified($id);
                continue;
            }

            try {
                $lang = $this->langFor($chatId);
                $tz   = $event['timezone'] ?? Configuration::timezone($chatId);
                $desc = $event['description'] ? "\n📝 {$event['description']}" : '';
                $msg = LanguageService::t('notify_event', $lang, [$event['title'], DateParser::toLocal($event['scheduled_at'], $tz, 'H:i'), $desc]);

                $this->telegram->sendMessage($target, $msg);
                CalendarEvent::markNotified($id);
                NotificationLog::record($chatId, 'calendar', $id);
                LogService::info('Calendar notification sent', ['id' => $id]);
            } catch (\Throwable $e) {
                LogService::error('Failed to send calendar notification', ['id' => $id, 'error' => $e->getMessage()]);
            }
        }
    }

    private function notifyReminders(): void
    {
        foreach (Reminder::pendingNotification() as $reminder) {
            $chatId = (int)$reminder['chat_id'];
            $target = (int)($reminder['notify_chat_id'] ?? 0) ?: $chatId;
            $id = (int)$reminder['id'];
            if (NotificationLog::wasSent($chatId, 'reminder', $id, $reminder['scheduled_at'])) {
                Reminder::markNotified($id);
                continue;
            }

            try {
                $lang = $this->langFor($chatId);
                $this->telegram->sendMessage($target, LanguageService::t('notify_reminder', $lang, [$reminder['message']]));
                Reminder::markNotified($id);
                NotificationLog::record($chatId, 'reminder', $id, $reminder['scheduled_at']);

                if ($reminder['recurrence']) {
                    $this->rescheduleRecurring($reminder);
                }
                LogService::info('Reminder sent', ['id' => $id]);
            } catch (\Throwable $e) {
                LogService::error('Failed to send reminder', ['id' => $id, 'error' => $e->getMessage()]);
            }
        }
    }

    private function rescheduleRecurring(array $reminder): void
    {
        $dt = new \DateTime($reminder['scheduled_at']);
        $next = match ($reminder['recurrence']) {
            'daily'    => $dt->modify('+1 day'),
            'weekly'   => $dt->modify('+1 week'),
            'biweekly' => $dt->modify('+15 days'),
            'monthly'  => $dt->modify('+1 month'),
            default    => null,
        };

        if ($next) {
            Reminder::reschedule((int)$reminder['id'], $next->format('Y-m-d H:i:s'));
        }
    }

    private function langFor(int $chatId): string
    {
        $lang = Configuration::language($chatId);
        return ($lang && LanguageService::isSupported($lang)) ? $lang : LanguageService::default();
    }
}
