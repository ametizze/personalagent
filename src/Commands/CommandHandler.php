<?php

namespace App\Commands;

use App\Services\{OpenAIService, TelegramService, IntentService, LanguageService, MemoryService, SummaryService, LogService};
use App\Models\{CalendarEvent, Reminder, Task, Idea, Note, Account, Transaction, Memory, Conversation, Configuration, User};
use App\Support\{ChatContext, DateParser, ResponseFormatter, Validator};

class CommandHandler
{
    /** Multilingual command aliases mapped to their canonical English command. */
    private const ALIASES = [
        '/iniciar' => '/start', '/comenzar' => '/start',
        '/ajuda' => '/help', '/ayuda' => '/help',
        '/hoje' => '/today', '/hoy' => '/today',
        '/calendario' => '/agenda',
        '/tarefas' => '/tasks', '/tareas' => '/tasks',
        '/lembretes' => '/reminders', '/recordatorios' => '/reminders',
        '/ideias' => '/ideas',
        '/notas' => '/notes',
        '/saldo' => '/balance', '/financas' => '/balance', '/finanzas' => '/balance',
        '/resumo' => '/summary', '/resumen' => '/summary',
        '/configuracao' => '/config', '/configuracion' => '/config',
        '/memoria' => '/memory',
        '/limpar' => '/clear', '/limpiar' => '/clear',
    ];

    public function __construct(
        private TelegramService $telegram,
        private OpenAIService $openai,
        private IntentService $intent
    ) {}

    public function handle(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
            return;
        }

        if (!isset($update['message'])) {
            return;
        }

        $message = $update['message'];
        $text    = trim($message['text'] ?? '');

        $ctx = $this->authorize($message);
        if ($ctx === null) {
            return;
        }

        $from = $message['from'] ?? [];
        User::touch($ctx->ownerId, $from['username'] ?? null, $from['first_name'] ?? null);
        $ctx->lang = LanguageService::resolve(Configuration::language($ctx->ownerId), $text);

        LogService::debug('Message received', ['owner' => $ctx->ownerId, 'chat' => $ctx->replyChatId, 'group' => $ctx->isGroup, 'text' => $text]);

        if ($text === '') {
            return;
        }

        if (str_starts_with($text, '/')) {
            $this->handleCommand($ctx, $text);
            return;
        }

        // In a group, only react to free text when the bot is addressed (mention/reply),
        // so it doesn't process — or pay for — every message in the room.
        if ($ctx->isGroup && !$this->addressedInGroup($message, $text)) {
            return;
        }

        $this->handleNaturalMessage($ctx, $this->stripBotMention($text));
    }

    // ── Authentication & addressing ────────────────────────────────────────────

    /** Build a context for an authorized message, or null to ignore it. */
    private function authorize(array $message): ?ChatContext
    {
        $chat        = $message['chat'] ?? [];
        $replyChatId = (int)($chat['id'] ?? 0);
        $type        = $chat['type'] ?? 'private';
        $isGroup     = in_array($type, ['group', 'supergroup'], true);
        $ownerId     = (int)($message['from']['id'] ?? $replyChatId);

        if ($isGroup) {
            // Stay silent in groups that aren't whitelisted or for non-allowed members,
            // but log it so the admin can discover the group id to add to ALLOWED_GROUP_IDS.
            if (!$this->groupAllowed($replyChatId)) {
                LogService::info('Ignored message from non-whitelisted group', ['group_id' => $replyChatId]);
                return null;
            }
            if (!$this->userAllowed($ownerId)) {
                LogService::info('Ignored group message from non-whitelisted user', ['group_id' => $replyChatId, 'user_id' => $ownerId]);
                return null;
            }
        } elseif (!$this->userAllowed($ownerId)) {
            $this->telegram->sendMessage($replyChatId, LanguageService::t('unauthorized', LanguageService::default()));
            LogService::info('Blocked unauthorized user', ['user_id' => $ownerId]);
            return null;
        }

        return new ChatContext($ownerId, $replyChatId, $isGroup, LanguageService::default());
    }

    /** Allowed user ids from ALLOWED_USER_ID (single) + ALLOWED_USER_IDS (csv). Empty = no restriction. */
    private function allowedUserIds(): array
    {
        $ids = [];
        $single = (int)($_ENV['ALLOWED_USER_ID'] ?? 0);
        if ($single !== 0) {
            $ids[] = $single;
        }
        foreach (explode(',', $_ENV['ALLOWED_USER_IDS'] ?? '') as $part) {
            $id = (int)trim($part);
            if ($id !== 0) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    private function userAllowed(int $userId): bool
    {
        $ids = $this->allowedUserIds();
        return empty($ids) || in_array($userId, $ids, true);
    }

    /** Groups are opt-in: a chat must be listed in ALLOWED_GROUP_IDS. */
    private function groupAllowed(int $groupId): bool
    {
        foreach (explode(',', $_ENV['ALLOWED_GROUP_IDS'] ?? '') as $part) {
            if ((int)trim($part) === $groupId && $groupId !== 0) {
                return true;
            }
        }
        return false;
    }

    /** True if a group free-text message targets the bot (reply to it or @mention). */
    private function addressedInGroup(array $message, string $text): bool
    {
        $username = trim($_ENV['BOT_USERNAME'] ?? '');
        if ($username === '') {
            return false; // without a known username we can't tell — commands still work
        }

        $repliedTo = $message['reply_to_message']['from'] ?? null;
        if ($repliedTo && ($repliedTo['is_bot'] ?? false) && strcasecmp($repliedTo['username'] ?? '', $username) === 0) {
            return true;
        }

        return stripos($text, '@' . $username) !== false;
    }

    private function stripBotMention(string $text): string
    {
        $username = trim($_ENV['BOT_USERNAME'] ?? '');
        if ($username !== '') {
            $text = preg_replace('/@' . preg_quote($username, '/') . '\b/i', '', $text);
        }
        return trim((string)$text);
    }

    // ── Command routing ───────────────────────────────────────────────────────

    private function handleCommand(ChatContext $ctx, string $text): void
    {
        $parts   = explode(' ', $text, 2);
        $command = strtolower(explode('@', $parts[0])[0]); // strip @botname suffix used in groups
        $command = self::ALIASES[$command] ?? $command;
        $args    = trim($parts[1] ?? '');

        match ($command) {
            '/start'     => $this->cmdStart($ctx),
            '/help'      => $this->reply($ctx, 'help'),
            '/today', '/summary' => $this->cmdSummary($ctx),
            '/agenda'    => $this->cmdAgenda($ctx, $args),
            '/tasks'     => $this->cmdTasks($ctx, $args),
            '/reminders' => $this->cmdReminders($ctx, $args),
            '/ideas'     => $this->cmdIdeas($ctx, $args),
            '/notes'     => $this->cmdNotes($ctx, $args),
            '/balance'   => $this->cmdBalance($ctx, $args),
            '/memory'    => $this->cmdMemory($ctx, $args),
            '/config'    => $this->cmdConfig($ctx, $args),
            '/clear'     => $this->cmdClear($ctx),
            default      => $this->reply($ctx, 'unknown_command'),
        };
    }

    private function handleNaturalMessage(ChatContext $ctx, string $text): void
    {
        if ($text === '') {
            return;
        }
        $this->telegram->sendTypingAction($ctx->replyChatId);

        try {
            $tz = Configuration::timezone($ctx->ownerId) ?? ($_ENV['APP_TIMEZONE'] ?? 'UTC');
            $result = $this->intent->extract($text, $tz, $ctx->lang);

            if (LanguageService::isSupported($result['language'] ?? '')) {
                $ctx->lang = $result['language'];
            }

            LogService::debug('Intent detected', $result);

            $data = $result['data'] ?? [];
            if (($result['confidence'] ?? 0) < 0.4) {
                $this->respondWithAI($ctx, $text);
                return;
            }

            match ($result['intent']) {
                'create_calendar_event' => $this->createEvent($ctx, $data, $text),
                'list_calendar_events'  => $this->cmdAgenda($ctx, (string)($data['days'] ?? '')),
                'cancel_calendar_event' => $this->confirmCancelEvent($ctx, $data),

                'create_task'   => $this->createTask($ctx, $data, $text),
                'list_tasks'    => $this->cmdTasks($ctx, $data['status'] ?? ''),
                'complete_task' => $this->confirmTask($ctx, $data, 'task_done'),
                'cancel_task'   => $this->confirmTask($ctx, $data, 'task_cancel'),

                'create_reminder' => $this->createReminder($ctx, $data, $text),
                'list_reminders'  => $this->cmdReminders($ctx, $data['filter'] ?? ''),

                'create_idea' => $this->createIdea($ctx, $data, $text),
                'list_ideas'  => $this->cmdIdeas($ctx, $data['category'] ?? ''),

                'create_note' => $this->createNote($ctx, $data, $text),
                'list_notes'  => $this->cmdNotes($ctx, $data['query'] ?? ''),
                'delete_note' => $this->confirmDeleteNote($ctx, $data),

                'set_account_balance' => $this->setBalance($ctx, $data),
                'create_transaction'  => $this->createTransaction($ctx, $data),
                'list_transactions', 'get_balance_summary' => $this->cmdBalance($ctx, ''),

                'create_memory' => $this->createMemory($ctx, $data),
                'list_memories' => $this->cmdMemory($ctx, ''),
                'forget_memory' => $this->forgetMemory($ctx, $data),

                'daily_summary' => $this->cmdSummary($ctx),

                default => $this->respondWithAI($ctx, $text),
            };
        } catch (\Throwable $e) {
            LogService::error('Processing error', ['error' => $e->getMessage()]);
            $this->respondWithAI($ctx, $text);
        }
    }

    // ── Create actions (natural language) ──────────────────────────────────────

    private function createEvent(ChatContext $ctx, array $data, string $text): void
    {
        $title = Validator::text($data['title'] ?? '', 200);
        $tz    = Configuration::timezone($ctx->ownerId);
        $when  = DateParser::toUtc($data['datetime'] ?? null, $tz); // stored in UTC
        if ($title === '' || $when === null) {
            $this->respondWithAI($ctx, $text);
            return;
        }

        $recurrence = Validator::recurrence($data['recurrence'] ?? null);
        $description = Validator::text($data['description'] ?? '', 500) ?: null;
        $id = CalendarEvent::create($ctx->ownerId, $title, $when, $description, $recurrence, $tz, $ctx->replyChatId);
        $desc = $description ? "\n📝 {$description}" : '';
        $this->reply($ctx, 'event_created', [$title, DateParser::toLocal($when, $tz, 'Y-m-d H:i'), $desc, $id]);
    }

    private function createTask(ChatContext $ctx, array $data, string $text): void
    {
        $title = Validator::text($data['title'] ?? '', 200);
        if ($title === '') {
            $this->respondWithAI($ctx, $text);
            return;
        }

        $priority = Validator::priority($data['priority'] ?? null);
        $tz       = Configuration::timezone($ctx->ownerId);
        $deadline = DateParser::toUtc($data['deadline'] ?? null, $tz);
        $id = Task::create($ctx->ownerId, $title, $priority, Validator::text($data['description'] ?? '', 500) ?: null, $deadline, Validator::tags($data['tags'] ?? []));

        $deadlineLine = $deadline ? "\n⏳ " . DateParser::toLocal($deadline, $tz, 'Y-m-d') : '';
        $this->reply($ctx, 'task_created', [
            ResponseFormatter::priorityIcon($priority), $title, $this->priorityLabel($priority, $ctx->lang), $deadlineLine, $id,
        ]);
    }

    private function createReminder(ChatContext $ctx, array $data, string $text): void
    {
        $msg  = Validator::text($data['message'] ?? ($data['title'] ?? ''), 300);
        $tz   = Configuration::timezone($ctx->ownerId);
        $when = DateParser::toUtc($data['datetime'] ?? null, $tz); // stored in UTC
        if ($msg === '' || $when === null) {
            $this->respondWithAI($ctx, $text);
            return;
        }

        $recurrence = Validator::recurrence($data['recurrence'] ?? null);
        $id = Reminder::create($ctx->ownerId, $msg, $when, $recurrence, $ctx->replyChatId);
        $rec = $recurrence ? ' (🔄 ' . $this->recurrenceLabel($recurrence, $ctx->lang) . ')' : '';
        $this->reply($ctx, 'reminder_created', [$msg, DateParser::toLocal($when, $tz, 'Y-m-d H:i') . $rec, '', $id]);
    }

    private function createIdea(ChatContext $ctx, array $data, string $text): void
    {
        $content = Validator::text($data['content'] ?? '', 1000);
        if ($content === '') {
            $this->respondWithAI($ctx, $text);
            return;
        }
        $title    = Validator::text($data['title'] ?? '', 200) ?: mb_substr($content, 0, 40);
        $category = Validator::text($data['category'] ?? 'general', 50) ?: 'general';
        $id = Idea::create($ctx->ownerId, $title, $content, $category, Validator::tags($data['tags'] ?? []));
        $this->reply($ctx, 'idea_created', [$title, $content, $category, $id]);
    }

    private function createNote(ChatContext $ctx, array $data, string $text): void
    {
        $content = Validator::text($data['content'] ?? '', 2000);
        if ($content === '') {
            $this->respondWithAI($ctx, $text);
            return;
        }
        $title    = Validator::text($data['title'] ?? '', 200) ?: mb_substr($content, 0, 40);
        $category = Validator::text($data['category'] ?? 'general', 50) ?: 'general';
        $id = Note::create($ctx->ownerId, $title, $content, $category, Validator::tags($data['tags'] ?? []));
        $this->reply($ctx, 'note_created', [$title, $content, $id]);
    }

    private function createMemory(ChatContext $ctx, array $data): void
    {
        $content = Validator::text($data['content'] ?? '', 500);
        if ($content === '') {
            return;
        }
        MemoryService::remember($ctx->ownerId, $content, $data['category'] ?? 'other', (int)($data['importance'] ?? 3));
        $this->reply($ctx, 'memory_saved');
    }

    private function forgetMemory(ChatContext $ctx, array $data): void
    {
        if (!empty($data['id'])) {
            $ok = MemoryService::forgetById($ctx->ownerId, (int)$data['id']);
            $this->reply($ctx, $ok ? 'memory_forgotten' : 'memory_not_found');
            return;
        }
        $term = Validator::text($data['query'] ?? ($data['content'] ?? ''), 200);
        $count = $term !== '' ? MemoryService::forgetByText($ctx->ownerId, $term) : 0;
        $this->reply($ctx, $count > 0 ? 'memory_forgotten' : 'memory_not_found');
    }

    // ── Finance ────────────────────────────────────────────────────────────────

    private function setBalance(ChatContext $ctx, array $data): void
    {
        $amount   = (float)($data['amount'] ?? 0);
        $currency = strtoupper(Validator::text($data['currency'] ?? 'USD', 5)) ?: 'USD';
        $name     = Validator::text($data['account'] ?? '', 50);

        $account = Account::findByName($ctx->ownerId, $name ?: null);
        if (!$account) {
            $accountName = $name ?: 'checking';
            $id = Account::create($ctx->ownerId, $accountName, Validator::accountType($name), $currency, $amount);
            $account = Account::find($id, $ctx->ownerId);
            $this->reply($ctx, 'account_created', [$accountName]);
        } else {
            Account::setBalance((int)$account['id'], $ctx->ownerId, $amount);
        }
        $this->reply($ctx, 'balance_set', [$account['name'], ResponseFormatter::money($amount, $currency)]);
    }

    private function createTransaction(ChatContext $ctx, array $data): void
    {
        $kind     = Validator::transactionType($data['kind'] ?? $data['type'] ?? 'expense');
        $amount   = abs((float)($data['amount'] ?? 0));
        $currency = strtoupper(Validator::text($data['currency'] ?? 'USD', 5)) ?: 'USD';
        if ($amount <= 0) {
            return;
        }

        $account = Account::findByName($ctx->ownerId, Validator::text($data['account'] ?? '', 50) ?: null);
        if (!$account) {
            $this->reply($ctx, 'balance_no_accounts');
            return;
        }

        $description = Validator::text($data['description'] ?? '', 200);
        Transaction::create($ctx->ownerId, (int)$account['id'], $kind, $amount, $currency, Validator::text($data['category'] ?? '', 50) ?: null, $description ?: null);
        $newBalance = Account::find((int)$account['id'], $ctx->ownerId)['balance'];

        $descLine = $description !== '' ? " ({$description})" : '';
        $key = $kind === 'income' ? 'tx_income' : 'tx_expense';
        $this->reply($ctx, $key, [
            ResponseFormatter::money($amount, $currency), $descLine, $account['name'], ResponseFormatter::money((float)$newBalance, $currency),
        ]);
    }

    // ── Listing commands ────────────────────────────────────────────────────────

    private function cmdStart(ChatContext $ctx): void
    {
        Configuration::upsert($ctx->ownerId, ['notifications_enabled' => 1]);
        $this->reply($ctx, 'start');
    }

    private function cmdAgenda(ChatContext $ctx, string $args): void
    {
        $days   = is_numeric($args) ? max(1, (int)$args) : 7;
        $events = CalendarEvent::listUpcoming($ctx->ownerId, $days);

        if (empty($events)) {
            $this->reply($ctx, 'calendar_empty', [$days]);
            return;
        }

        $tz = Configuration::timezone($ctx->ownerId);
        $list = LanguageService::t('calendar_header', $ctx->lang, [$days]) . "\n";
        foreach ($events as $e) {
            $list .= "📅 *{$e['title']}*\n   🕐 " . DateParser::toLocal($e['scheduled_at'], $e['timezone'] ?? $tz, 'm-d H:i');
            if ($e['description']) $list .= " — {$e['description']}";
            $list .= "\n   _#{$e['id']}_\n\n";
        }
        $this->send($ctx, $list);
    }

    private function cmdTasks(ChatContext $ctx, string $args): void
    {
        if (in_array(strtolower($args), ['urgent', 'high', 'low', 'medium'], true)) {
            $tasks = Task::listByPriority($ctx->ownerId, strtolower($args));
            $label = $this->priorityLabel(strtolower($args), $ctx->lang);
        } else {
            $status = Validator::taskStatus($args ?: 'pending');
            $tasks = Task::list($ctx->ownerId, $status);
            $label = $this->statusLabel($status, $ctx->lang);
        }

        if (empty($tasks)) {
            $this->reply($ctx, 'tasks_empty', [$label]);
            return;
        }

        $tz = Configuration::timezone($ctx->ownerId);
        $list = LanguageService::t('tasks_header', $ctx->lang, [$label]) . "\n";
        foreach ($tasks as $t) {
            $list .= ResponseFormatter::priorityIcon($t['priority']) . " *{$t['title']}*";
            if ($t['deadline']) $list .= "\n   ⏳ " . DateParser::toLocal($t['deadline'], $tz, 'Y-m-d');
            $list .= "\n   _#{$t['id']}_\n\n";
        }
        $this->send($ctx, $list);
    }

    private function cmdReminders(ChatContext $ctx, string $args): void
    {
        $reminders = strtolower($args) === 'recurring'
            ? Reminder::listRecurring($ctx->ownerId)
            : Reminder::list($ctx->ownerId);

        if (empty($reminders)) {
            $this->reply($ctx, 'reminders_empty');
            return;
        }

        $tz = Configuration::timezone($ctx->ownerId);
        $list = LanguageService::t('reminders_header', $ctx->lang) . "\n";
        foreach ($reminders as $r) {
            $list .= "⏰ {$r['message']}\n   🕐 " . DateParser::toLocal($r['scheduled_at'], $tz, 'Y-m-d H:i');
            if ($r['recurrence']) $list .= " (🔄 " . $this->recurrenceLabel($r['recurrence'], $ctx->lang) . ")";
            $list .= "\n   _#{$r['id']}_\n\n";
        }
        $this->send($ctx, $list);
    }

    private function cmdIdeas(ChatContext $ctx, string $args): void
    {
        $ideas = Idea::list($ctx->ownerId, $args ?: null);
        if (empty($ideas)) {
            $this->reply($ctx, 'ideas_empty');
            return;
        }

        $list = LanguageService::t('ideas_header', $ctx->lang) . "\n";
        foreach ($ideas as $i) {
            $list .= "💡 *{$i['title']}*\n   📁 {$i['category']} | {$i['content']}\n   _#{$i['id']}_\n\n";
        }
        $this->send($ctx, $list);
    }

    private function cmdNotes(ChatContext $ctx, string $args): void
    {
        $notes = Note::list($ctx->ownerId, $args ?: null);
        if (empty($notes)) {
            $this->reply($ctx, 'notes_empty');
            return;
        }

        $list = LanguageService::t('notes_header', $ctx->lang) . "\n";
        foreach ($notes as $n) {
            $list .= "📝 *{$n['title']}*\n   {$n['content']}\n   _#{$n['id']}_\n\n";
        }
        $this->send($ctx, $list);
    }

    private function cmdBalance(ChatContext $ctx, string $args): void
    {
        $accounts = Account::list($ctx->ownerId);
        if (empty($accounts)) {
            $this->reply($ctx, 'balance_no_accounts');
            return;
        }

        $weekStart = DateParser::startOfWeek(Configuration::timezone($ctx->ownerId));
        $income  = Transaction::sumSince($ctx->ownerId, 'income', $weekStart);
        $expense = Transaction::sumSince($ctx->ownerId, 'expense', $weekStart);

        $msg = LanguageService::t('balance_header', $ctx->lang) . "\n";
        foreach ($accounts as $a) {
            $msg .= "🏦 {$a['name']}: " . ResponseFormatter::money((float)$a['balance'], $a['currency']) . "\n";
        }
        $msg .= "\n" . LanguageService::t('balance_total', $ctx->lang, [ResponseFormatter::money(Account::totalBalance($ctx->ownerId))]) . "\n";
        $msg .= LanguageService::t('balance_income', $ctx->lang, [ResponseFormatter::money($income)]) . "\n";
        $msg .= LanguageService::t('balance_expense', $ctx->lang, [ResponseFormatter::money($expense)]);
        $this->send($ctx, $msg);
    }

    private function cmdMemory(ChatContext $ctx, string $args): void
    {
        $args = trim($args);
        if (str_starts_with($args, 'forget')) {
            $id = (int)trim(substr($args, strlen('forget')));
            if ($id > 0) {
                $ok = MemoryService::forgetById($ctx->ownerId, $id);
                $this->reply($ctx, $ok ? 'memory_forgotten' : 'memory_not_found');
                return;
            }
        }

        $memories = MemoryService::list($ctx->ownerId);
        if (empty($memories)) {
            $this->reply($ctx, 'memories_empty');
            return;
        }

        $list = LanguageService::t('memories_header', $ctx->lang) . "\n";
        foreach ($memories as $m) {
            $list .= "• {$m['content']} _({$m['category']}, #{$m['id']})_\n";
        }
        $this->send($ctx, $list);
    }

    private function cmdSummary(ChatContext $ctx): void
    {
        $this->telegram->sendTypingAction($ctx->replyChatId);
        $summary = (new SummaryService($this->openai))->build($ctx->ownerId, $ctx->lang);
        $this->send($ctx, $summary);
    }

    private function cmdConfig(ChatContext $ctx, string $args): void
    {
        if ($args === '') {
            $config = Configuration::findByChatId($ctx->ownerId);
            $this->reply($ctx, 'config_header', [
                $config['name'] ?? LanguageService::t('not_set', $ctx->lang),
                $config['timezone'] ?? ($_ENV['APP_TIMEZONE'] ?? 'UTC'),
                $config['language'] ?? LanguageService::default(),
                ($config['notifications_enabled'] ?? 1) ? LanguageService::t('on', $ctx->lang) : LanguageService::t('off', $ctx->lang),
            ]);
            return;
        }

        $parts = explode(' ', $args, 2);
        $key   = strtolower($parts[0]);
        $value = trim($parts[1] ?? '');

        switch ($key) {
            case 'name':
                Configuration::upsert($ctx->ownerId, ['name' => Validator::text($value, 100)]);
                break;
            case 'timezone':
                $tz = Validator::timezone($value);
                if ($tz === null) {
                    $this->send($ctx, "⚠️ Invalid timezone.");
                    return;
                }
                Configuration::upsert($ctx->ownerId, ['timezone' => $tz]);
                break;
            case 'language':
                if (!LanguageService::isSupported($value)) {
                    $this->reply($ctx, 'config_lang_bad', [implode(', ', LanguageService::supported())]);
                    return;
                }
                Configuration::upsert($ctx->ownerId, ['language' => $value]);
                $ctx->lang = $value;
                break;
            case 'notifications':
                Configuration::upsert($ctx->ownerId, ['notifications_enabled' => $value === 'on' ? 1 : 0]);
                break;
            case 'context':
                Configuration::upsert($ctx->ownerId, ['system_context' => Validator::text($value, 1000)]);
                break;
            default:
                $this->reply($ctx, 'unknown_command');
                return;
        }
        $this->reply($ctx, 'config_updated', [$key]);
    }

    private function cmdClear(ChatContext $ctx): void
    {
        Conversation::clear($ctx->ownerId);
        $this->reply($ctx, 'cleared');
    }

    private function respondWithAI(ChatContext $ctx, string $text): void
    {
        $reply = $this->openai->chat($ctx->ownerId, $text, $ctx->lang, MemoryService::forContext($ctx->ownerId));
        $this->send($ctx, $reply);
    }

    // ── Destructive-action confirmations (inline buttons) ───────────────────────

    private function confirmCancelEvent(ChatContext $ctx, array $data): void
    {
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            $this->respondWithAI($ctx, Validator::text($data['query'] ?? '', 200));
            return;
        }
        $this->askConfirmation($ctx, "❌ Cancel event #{$id}?", "event_cancel:{$id}");
    }

    private function confirmTask(ChatContext $ctx, array $data, string $action): void
    {
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            $this->cmdTasks($ctx, 'pending');
            return;
        }
        if ($action === 'task_done') {
            // Completion is non-destructive — execute immediately.
            if (Task::complete($id, $ctx->ownerId)) {
                $this->reply($ctx, 'task_completed', [$id]);
            }
            return;
        }
        $this->askConfirmation($ctx, "❌ Cancel task #{$id}?", "task_cancel:{$id}");
    }

    private function confirmDeleteNote(ChatContext $ctx, array $data): void
    {
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            $this->cmdNotes($ctx, Validator::text($data['query'] ?? '', 100));
            return;
        }
        $this->askConfirmation($ctx, "🗑️ Delete note #{$id}?", "note_delete:{$id}");
    }

    private function askConfirmation(ChatContext $ctx, string $question, string $confirmData): void
    {
        $this->telegram->sendWithButtons($ctx->replyChatId, LanguageService::t('confirm_needed', $ctx->lang, [$question]), [[
            ['text' => '✅', 'callback_data' => $confirmData],
            ['text' => '✖️', 'callback_data' => 'noop'],
        ]]);
    }

    private function handleCallback(array $callback): void
    {
        $replyChatId = (int)($callback['message']['chat']['id'] ?? 0);
        $ownerId     = (int)($callback['from']['id'] ?? $replyChatId);
        $data        = $callback['data'] ?? '';
        $lang        = LanguageService::resolve(Configuration::language($ownerId), '');
        $this->telegram->answerCallback($callback['id']);

        // The presser may only act on their own items (scoped by ownerId).
        if (!$this->userAllowed($ownerId)) {
            return;
        }

        [$action, $rawId] = array_pad(explode(':', $data), 2, '0');
        $id = (int)$rawId;
        $ctx = new ChatContext($ownerId, $replyChatId, false, $lang);

        match ($action) {
            'task_done'    => Task::complete($id, $ownerId) ? $this->reply($ctx, 'task_completed', [$id]) : null,
            'task_cancel'  => Task::cancel($id, $ownerId) ? $this->reply($ctx, 'task_cancelled', [$id]) : null,
            'event_cancel' => CalendarEvent::cancel($id, $ownerId) ? $this->send($ctx, "❌ #{$id}") : null,
            'note_delete'  => Note::delete($id, $ownerId) ? $this->send($ctx, "🗑️ #{$id}") : null,
            'memory_forget'=> MemoryService::forgetById($ownerId, $id) ? $this->reply($ctx, 'memory_forgotten') : null,
            default        => null,
        };
    }

    // ── Reply + label helpers ────────────────────────────────────────────────────

    private function reply(ChatContext $ctx, string $key, array $params = []): void
    {
        $this->telegram->sendMessage($ctx->replyChatId, LanguageService::t($key, $ctx->lang, $params));
    }

    private function send(ChatContext $ctx, string $text): void
    {
        $this->telegram->sendMessage($ctx->replyChatId, $text);
    }

    private function priorityLabel(string $priority, string $lang): string
    {
        return LanguageService::t('priority_' . $priority, $lang);
    }

    private function statusLabel(string $status, string $lang): string
    {
        return LanguageService::t('status_' . $status, $lang);
    }

    private function recurrenceLabel(string $recurrence, string $lang): string
    {
        return LanguageService::t('rec_' . $recurrence, $lang);
    }
}
