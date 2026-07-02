<?php

namespace App\Services;

use App\Models\{CalendarEvent, Task, Reminder, Account, Configuration};
use App\Support\{DateParser, ResponseFormatter};

/**
 * Builds the daily briefing deterministically from stored data, then optionally
 * appends a single AI-generated suggestion. The deterministic part never calls
 * the API, so summaries still work if OpenAI is unavailable.
 */
class SummaryService
{
    public function __construct(private ?OpenAIService $openai = null) {}

    public function build(int $chatId, string $lang, bool $withSuggestion = true): string
    {
        $config = Configuration::findByChatId($chatId);
        $tz = $config['timezone'] ?? ($_ENV['APP_TIMEZONE'] ?? 'UTC');
        $name = $config['name'] ?? null;
        $today = (new \DateTime('now', new \DateTimeZone($tz)))->format('Y-m-d');

        $events    = CalendarEvent::listToday($chatId);
        $tasks     = Task::listImportant($chatId, 5);
        $dueTasks  = Task::dueToday($chatId);
        $reminders = Reminder::listToday($chatId);
        $balance   = Account::totalBalance($chatId);
        $hasAccounts = !empty(Account::list($chatId));

        $tasks = $this->mergeTasks($tasks, $dueTasks);

        $greeting = $name ? ", {$name}" : '';
        $lines = [LanguageService::t('summary_title', $lang, [$greeting, $today]), ''];

        if ($events) {
            $lines[] = LanguageService::t('summary_events', $lang);
            foreach ($events as $e) {
                $lines[] = '  • ' . DateParser::toLocal($e['scheduled_at'], $e['timezone'] ?? $tz, 'H:i') . " — {$e['title']}";
            }
            $lines[] = '';
        }

        if ($reminders) {
            $lines[] = LanguageService::t('summary_reminders', $lang);
            foreach ($reminders as $r) {
                $lines[] = '  • ' . DateParser::toLocal($r['scheduled_at'], $tz, 'H:i') . " — {$r['message']}";
            }
            $lines[] = '';
        }

        if ($tasks) {
            $lines[] = LanguageService::t('summary_tasks', $lang);
            foreach ($tasks as $t) {
                $lines[] = '  ' . ResponseFormatter::priorityIcon($t['priority']) . " {$t['title']}";
            }
            $lines[] = '';
        }

        if ($hasAccounts) {
            $lines[] = LanguageService::t('summary_finance', $lang, [ResponseFormatter::money($balance)]);
            $lines[] = '';
        }

        if (!$events && !$reminders && !$tasks) {
            $lines[] = LanguageService::t('summary_nothing', $lang);
        }

        $text = trim(implode("\n", $lines));

        if ($withSuggestion && $this->openai && ($events || $tasks || $reminders)) {
            $suggestion = $this->suggestion($lang, $events, $tasks);
            if ($suggestion !== '') {
                $text .= "\n\n" . LanguageService::t('suggestion', $lang, [$suggestion]);
            }
        }

        return $text;
    }

    /** Deduplicate by id, keeping order (important tasks first, then due-today). */
    private function mergeTasks(array $a, array $b): array
    {
        $byId = [];
        foreach ([...$a, ...$b] as $t) {
            $byId[$t['id']] = $t;
        }
        return array_values($byId);
    }

    private function suggestion(string $lang, array $events, array $tasks): string
    {
        $context = "Events: " . implode('; ', array_map(fn($e) => $e['title'], $events))
            . ". Tasks: " . implode('; ', array_map(fn($t) => "[{$t['priority']}] {$t['title']}", $tasks));

        $system = "You are a concise productivity coach. Given today's events and tasks, reply with ONE short, "
            . "actionable suggestion (max 2 sentences) about what to focus on first. Reply in language '{$lang}'.";

        try {
            return trim($this->openai->generateText($system, $context, 120));
        } catch (\Throwable) {
            return '';
        }
    }
}
