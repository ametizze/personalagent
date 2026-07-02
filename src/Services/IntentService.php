<?php

namespace App\Services;

/**
 * Natural-language intent extraction. Wraps a strict-JSON OpenAI call and
 * guarantees a well-formed result (falling back to free_chat on any failure).
 */
class IntentService
{
    public const INTENTS = [
        'create_calendar_event', 'list_calendar_events', 'update_calendar_event', 'cancel_calendar_event',
        'create_task', 'list_tasks', 'update_task', 'complete_task', 'cancel_task',
        'create_reminder', 'list_reminders', 'update_reminder', 'cancel_reminder',
        'create_idea', 'list_ideas', 'update_idea', 'archive_idea',
        'create_note', 'list_notes', 'update_note', 'delete_note',
        'set_account_balance', 'create_transaction', 'list_transactions', 'get_balance_summary',
        'create_memory', 'list_memories', 'forget_memory',
        'daily_summary', 'free_chat', 'unknown',
    ];

    public function __construct(private OpenAIService $openai) {}

    public function extract(string $message, string $timezone, string $defaultLanguage): array
    {
        $now = (new \DateTime('now', new \DateTimeZone($timezone)))->format('Y-m-d H:i:s');
        $intents = implode('|', self::INTENTS);

        $system = <<<PROMPT
            You are an intent parser for a personal-assistant bot. Current datetime: {$now} (timezone: {$timezone}).
            Respond ONLY with valid JSON, no prose, no markdown fences.

            Shape:
            {"intent":"<one of: {$intents}>","confidence":0.0-1.0,"language":"en|pt-BR|es","data":{...}}

            Rules:
            - Resolve relative dates ("tomorrow", "Friday", "the 10th") to absolute "YYYY-MM-DD HH:MM:SS" in the given timezone.
            - "language" is the language of the user's message.
            - Use "free_chat" for general conversation/questions, "unknown" if truly unclear.

            data fields by intent:
            - create_calendar_event: title, datetime, description, recurrence(none|daily|weekly|biweekly|monthly)
            - list_calendar_events: range(today|week|days), days
            - cancel_calendar_event / complete_task / cancel_task / archive_idea / delete_note / forget_memory / update_*: id (integer if mentioned), query (text)
            - create_task: title, priority(low|medium|high|urgent), deadline, description, tags(array)
            - list_tasks: status(pending|in_progress|completed|cancelled|all), priority
            - create_reminder: message, datetime, recurrence
            - list_reminders: filter(active|recurring)
            - create_idea: title, content, category, tags(array)
            - list_ideas: category
            - create_note: title, content, category, tags(array)
            - list_notes: query
            - set_account_balance: account(name or null), amount, currency
            - create_transaction: kind(income|expense), account, amount, currency, category, description
            - get_balance_summary: range(now|week)
            - create_memory: content, category(personal|preference|work|family|health|project|finance|other), importance(1-5)
            PROMPT;

        $data = $this->openai->json($system, $message);

        $intent = $data['intent'] ?? null;
        if (!in_array($intent, self::INTENTS, true)) {
            return ['intent' => 'free_chat', 'confidence' => 0.0, 'language' => $defaultLanguage, 'data' => []];
        }

        return [
            'intent'     => $intent,
            'confidence' => (float)($data['confidence'] ?? 0.5),
            'language'   => $data['language'] ?? $defaultLanguage,
            'data'       => is_array($data['data'] ?? null) ? $data['data'] : [],
        ];
    }
}
