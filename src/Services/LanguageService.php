<?php

namespace App\Services;

/**
 * Lightweight, deterministic i18n layer.
 *
 * - detect(): heuristic language detection for the three v1 languages, with no
 *   API call (free-chat replies are steered by the model separately).
 * - t(): sprintf-style translation lookup with fallback to the default language
 *   and finally to the key itself.
 */
class LanguageService
{
    /** Stopwords that strongly signal a language. */
    private const HINTS = [
        'pt-BR' => [' que ', ' não ', ' você ', ' está ', ' por ', ' para ', ' com ', ' uma ', ' meu ', ' minha ', 'ção', 'amanhã', 'lembre', 'tarefa', 'olá', 'obrigad'],
        'es'    => [' que ', ' no ', ' usted ', ' está ', ' por ', ' para ', ' con ', ' una ', ' mi ', ' hola ', 'mañana', 'recuérda', 'recordat', 'tarea', 'gracias', 'ñ'],
        'en'    => [' the ', ' you ', ' is ', ' for ', ' with ', ' my ', ' remind ', ' task ', ' hello ', ' tomorrow ', ' please '],
    ];

    public static function default(): string
    {
        return $_ENV['APP_DEFAULT_LANGUAGE'] ?? 'en';
    }

    /** @return string[] */
    public static function supported(): array
    {
        $raw = $_ENV['APP_SUPPORTED_LANGUAGES'] ?? 'en,pt-BR,es';
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public static function isSupported(string $lang): bool
    {
        return in_array($lang, self::supported(), true);
    }

    /**
     * Detect the language of a free-text message. Returns the default language
     * when there is no clear signal (e.g. short commands).
     */
    public static function detect(string $text): string
    {
        $haystack = ' ' . mb_strtolower(trim($text)) . ' ';
        if (mb_strlen(trim($text)) < 3) {
            return self::default();
        }

        $scores = ['en' => 0, 'pt-BR' => 0, 'es' => 0];
        foreach (self::HINTS as $lang => $hints) {
            foreach ($hints as $hint) {
                if (str_contains($haystack, $hint)) {
                    $scores[$lang]++;
                }
            }
        }

        arsort($scores);
        $best = array_key_first($scores);
        return $scores[$best] > 0 ? $best : self::default();
    }

    /**
     * Resolve the language to use for a chat: stored preference if any,
     * otherwise the detected language of the current message.
     */
    public static function resolve(?string $stored, string $text): string
    {
        if ($stored && self::isSupported($stored)) {
            return $stored;
        }
        return self::detect($text);
    }

    public static function t(string $key, string $lang, array $params = []): string
    {
        $lang = self::isSupported($lang) ? $lang : self::default();
        $template = self::STRINGS[$lang][$key]
            ?? self::STRINGS[self::default()][$key]
            ?? $key;

        return $params ? vsprintf($template, $params) : $template;
    }

    /**
     * Translation table. Keys are shared across languages; %s/%d placeholders
     * are filled via t() params.
     */
    private const STRINGS = [
        'en' => [
            'unauthorized'      => '⛔ Unauthorized access.',
            'unknown_command'   => "Unknown command. Use /help to see what I can do.",
            'start'             => "👋 *Hi! I'm PersonalAgent, your private assistant.*\n\nI can help you with:\n📅 Calendar events\n⏰ Reminders\n✅ Tasks\n💡 Ideas\n📝 Notes\n💰 Personal finance\n🧠 Long-term memory\n\nJust talk to me naturally, or use /help to see the commands.",
            'help'              => "*Commands*\n\n/today — today's overview\n/agenda — upcoming events\n/tasks — your tasks\n/reminders — active reminders\n/ideas — your ideas\n/notes — your notes\n/balance — finance summary\n/summary — full daily briefing\n/memory — long-term memory\n/config — settings\n/clear — reset conversation\n\n*Or just talk to me:*\n_\"Remind me tomorrow at 8am to pay rent\"_\n_\"Create an urgent task to review the contract\"_\n_\"Add expense: 75 for gas\"_\n_\"Remember that I prefer short answers\"_",
            'cleared'           => '🗑️ Conversation history cleared. Context reset.',
            'event_created'     => "📅 *Event scheduled!*\n\n%s\n🕐 %s%s\n_#%d_",
            'calendar_empty'    => '📅 No events in the next %d days.',
            'calendar_header'   => "*Agenda — next %d days*\n",
            'task_created'      => "✅ *Task created!*\n\n%s %s\n📊 Priority: %s%s\n_#%d_",
            'tasks_empty'       => '✅ No %s tasks found.',
            'tasks_header'      => "*Tasks (%s)*\n",
            'task_completed'    => '✅ Task #%d marked as done.',
            'task_cancelled'    => '❌ Task #%d cancelled.',
            'reminder_created'  => "⏰ *Reminder set!*\n\n%s\n🕐 %s%s\n_#%d_",
            'reminders_empty'   => '⏰ No active reminders.',
            'reminders_header'  => "*Active reminders*\n",
            'notify_event'      => "📅 *Upcoming event!*\n\n*%s*\n🕐 %s%s",
            'notify_reminder'   => "⏰ *Reminder!*\n\n%s",
            'idea_created'      => "💡 *Idea saved!*\n\n*%s*\n%s\n📁 %s\n_#%d_",
            'ideas_empty'       => '💡 No ideas saved yet.',
            'ideas_header'      => "*Your ideas*\n",
            'note_created'      => "📝 *Note saved!*\n\n*%s*\n%s\n_#%d_",
            'notes_empty'       => '📝 No notes found.',
            'notes_header'      => "*Your notes*\n",
            'balance_header'    => "💰 *Finance summary*\n",
            'balance_total'     => 'Available balance: *%s*',
            'balance_income'    => 'Income this week: %s',
            'balance_expense'   => 'Expenses this week: %s',
            'balance_no_accounts' => '💰 No accounts yet. Try: "Set my checking balance to 1200".',
            'balance_set'       => '💰 Balance of *%s* set to %s.',
            'tx_income'         => '💵 Income recorded: %s%s. New balance of *%s*: %s.',
            'tx_expense'        => '💸 Expense recorded: %s%s. New balance of *%s*: %s.',
            'account_created'   => '🏦 Account *%s* created.',
            'memory_saved'      => '🧠 Got it, I\'ll remember that.',
            'memories_empty'    => '🧠 No long-term memories stored.',
            'memories_header'   => "🧠 *Things I remember*\n",
            'memory_forgotten'  => '🧠 Forgotten.',
            'memory_not_found'  => '🧠 I could not find a matching memory to forget.',
            'config_header'     => "⚙️ *Settings*\n\n👤 Name: %s\n🕐 Timezone: %s\n🌐 Language: %s\n🔔 Notifications: %s\n\n_/config name John_\n_/config timezone America/New_York_\n_/config language en|pt-BR|es_\n_/config notifications on|off_\n_/config context I am a software engineer._",
            'config_updated'    => '⚙️ Setting *%s* updated.',
            'config_lang_bad'   => '⚠️ Unsupported language. Supported: %s',
            'on'                => 'on',
            'off'               => 'off',
            'not_set'           => 'not set',
            'confirm_needed'    => '⚠️ Please confirm: %s\nReply "yes" to proceed.',
            'summary_title'     => '🌅 *Good morning%s! Here is your day — %s*',
            'summary_events'    => '📅 *Events today:*',
            'summary_tasks'     => '✅ *Priority tasks:*',
            'summary_reminders' => '⏰ *Reminders due:*',
            'summary_finance'   => '💰 Available balance: %s',
            'summary_nothing'   => "Nothing scheduled for today. Enjoy! 🎉",
            'suggestion'        => '💡 *Suggestion:* %s',
            'priority_low'      => 'low', 'priority_medium' => 'medium', 'priority_high' => 'high', 'priority_urgent' => 'urgent',
            'status_pending'    => 'pending', 'status_in_progress' => 'in progress', 'status_completed' => 'completed', 'status_cancelled' => 'cancelled', 'status_all' => 'all',
            'rec_daily' => 'daily', 'rec_weekly' => 'weekly', 'rec_biweekly' => 'biweekly', 'rec_monthly' => 'monthly',
        ],
        'pt-BR' => [
            'unauthorized'      => '⛔ Acesso não autorizado.',
            'unknown_command'   => 'Comando desconhecido. Use /help para ver o que eu faço.',
            'start'             => "👋 *Olá! Sou o PersonalAgent, seu assistente pessoal.*\n\nPosso te ajudar com:\n📅 Agenda\n⏰ Lembretes\n✅ Tarefas\n💡 Ideias\n📝 Notas\n💰 Finanças pessoais\n🧠 Memória de longo prazo\n\nFale comigo naturalmente ou use /help para ver os comandos.",
            'help'              => "*Comandos*\n\n/today — resumo de hoje\n/agenda — próximos eventos\n/tasks — suas tarefas\n/reminders — lembretes ativos\n/ideas — suas ideias\n/notes — suas notas\n/balance — resumo financeiro\n/summary — resumo completo do dia\n/memory — memória de longo prazo\n/config — configurações\n/clear — reiniciar conversa\n\n*Ou fale comigo:*\n_\"Me lembre amanhã às 8h de pagar o aluguel\"_\n_\"Crie tarefa urgente para revisar o contrato\"_\n_\"Adicionar despesa: 75 de gasolina\"_\n_\"Lembre que eu prefiro respostas curtas\"_",
            'cleared'           => '🗑️ Histórico da conversa limpo. Contexto reiniciado.',
            'event_created'     => "📅 *Evento agendado!*\n\n%s\n🕐 %s%s\n_#%d_",
            'calendar_empty'    => '📅 Nenhum evento nos próximos %d dias.',
            'calendar_header'   => "*Agenda — próximos %d dias*\n",
            'task_created'      => "✅ *Tarefa criada!*\n\n%s %s\n📊 Prioridade: %s%s\n_#%d_",
            'tasks_empty'       => '✅ Nenhuma tarefa %s encontrada.',
            'tasks_header'      => "*Tarefas (%s)*\n",
            'task_completed'    => '✅ Tarefa #%d marcada como concluída.',
            'task_cancelled'    => '❌ Tarefa #%d cancelada.',
            'reminder_created'  => "⏰ *Lembrete criado!*\n\n%s\n🕐 %s%s\n_#%d_",
            'reminders_empty'   => '⏰ Nenhum lembrete ativo.',
            'reminders_header'  => "*Lembretes ativos*\n",
            'notify_event'      => "📅 *Evento em breve!*\n\n*%s*\n🕐 %s%s",
            'notify_reminder'   => "⏰ *Lembrete!*\n\n%s",
            'idea_created'      => "💡 *Ideia salva!*\n\n*%s*\n%s\n📁 %s\n_#%d_",
            'ideas_empty'       => '💡 Nenhuma ideia registrada ainda.',
            'ideas_header'      => "*Suas ideias*\n",
            'note_created'      => "📝 *Nota salva!*\n\n*%s*\n%s\n_#%d_",
            'notes_empty'       => '📝 Nenhuma nota encontrada.',
            'notes_header'      => "*Suas notas*\n",
            'balance_header'    => "💰 *Resumo financeiro*\n",
            'balance_total'     => 'Saldo disponível: *%s*',
            'balance_income'    => 'Receitas na semana: %s',
            'balance_expense'   => 'Despesas na semana: %s',
            'balance_no_accounts' => '💰 Nenhuma conta ainda. Tente: "Defina meu saldo da conta corrente em 1200".',
            'balance_set'       => '💰 Saldo da conta *%s* definido como %s.',
            'tx_income'         => '💵 Receita registrada: %s%s. Novo saldo de *%s*: %s.',
            'tx_expense'        => '💸 Despesa registrada: %s%s. Novo saldo de *%s*: %s.',
            'account_created'   => '🏦 Conta *%s* criada.',
            'memory_saved'      => '🧠 Entendi, vou lembrar disso.',
            'memories_empty'    => '🧠 Nenhuma memória de longo prazo armazenada.',
            'memories_header'   => "🧠 *Coisas que eu lembro*\n",
            'memory_forgotten'  => '🧠 Esquecido.',
            'memory_not_found'  => '🧠 Não encontrei uma memória correspondente para esquecer.',
            'config_header'     => "⚙️ *Configurações*\n\n👤 Nome: %s\n🕐 Fuso: %s\n🌐 Idioma: %s\n🔔 Notificações: %s\n\n_/config name João_\n_/config timezone America/Sao_Paulo_\n_/config language en|pt-BR|es_\n_/config notifications on|off_\n_/config context Sou engenheiro de software._",
            'config_updated'    => '⚙️ Configuração *%s* atualizada.',
            'config_lang_bad'   => '⚠️ Idioma não suportado. Suportados: %s',
            'on'                => 'ativadas',
            'off'               => 'desativadas',
            'not_set'           => 'não definido',
            'confirm_needed'    => '⚠️ Confirme por favor: %s\nResponda "sim" para prosseguir.',
            'summary_title'     => '🌅 *Bom dia%s! Aqui está o seu dia — %s*',
            'summary_events'    => '📅 *Eventos de hoje:*',
            'summary_tasks'     => '✅ *Tarefas prioritárias:*',
            'summary_reminders' => '⏰ *Lembretes do dia:*',
            'summary_finance'   => '💰 Saldo disponível: %s',
            'summary_nothing'   => 'Nada agendado para hoje. Aproveite! 🎉',
            'suggestion'        => '💡 *Sugestão:* %s',
            'priority_low'      => 'baixa', 'priority_medium' => 'média', 'priority_high' => 'alta', 'priority_urgent' => 'urgente',
            'status_pending'    => 'pendente', 'status_in_progress' => 'em andamento', 'status_completed' => 'concluída', 'status_cancelled' => 'cancelada', 'status_all' => 'todas',
            'rec_daily' => 'diário', 'rec_weekly' => 'semanal', 'rec_biweekly' => 'quinzenal', 'rec_monthly' => 'mensal',
        ],
        'es' => [
            'unauthorized'      => '⛔ Acceso no autorizado.',
            'unknown_command'   => 'Comando desconocido. Usa /help para ver lo que puedo hacer.',
            'start'             => "👋 *¡Hola! Soy PersonalAgent, tu asistente personal.*\n\nPuedo ayudarte con:\n📅 Agenda\n⏰ Recordatorios\n✅ Tareas\n💡 Ideas\n📝 Notas\n💰 Finanzas personales\n🧠 Memoria a largo plazo\n\nHáblame con naturalidad o usa /help para ver los comandos.",
            'help'              => "*Comandos*\n\n/today — resumen de hoy\n/agenda — próximos eventos\n/tasks — tus tareas\n/reminders — recordatorios activos\n/ideas — tus ideas\n/notes — tus notas\n/balance — resumen financiero\n/summary — resumen completo del día\n/memory — memoria a largo plazo\n/config — configuración\n/clear — reiniciar conversación\n\n*O háblame:*\n_\"Recuérdame mañana a las 8 pagar el alquiler\"_\n_\"Crea una tarea urgente para revisar el contrato\"_\n_\"Agregar gasto: 75 de gasolina\"_\n_\"Recuerda que prefiero respuestas cortas\"_",
            'cleared'           => '🗑️ Historial de conversación borrado. Contexto reiniciado.',
            'event_created'     => "📅 *¡Evento agendado!*\n\n%s\n🕐 %s%s\n_#%d_",
            'calendar_empty'    => '📅 Sin eventos en los próximos %d días.',
            'calendar_header'   => "*Agenda — próximos %d días*\n",
            'task_created'      => "✅ *¡Tarea creada!*\n\n%s %s\n📊 Prioridad: %s%s\n_#%d_",
            'tasks_empty'       => '✅ No se encontraron tareas %s.',
            'tasks_header'      => "*Tareas (%s)*\n",
            'task_completed'    => '✅ Tarea #%d marcada como completada.',
            'task_cancelled'    => '❌ Tarea #%d cancelada.',
            'reminder_created'  => "⏰ *¡Recordatorio creado!*\n\n%s\n🕐 %s%s\n_#%d_",
            'reminders_empty'   => '⏰ No hay recordatorios activos.',
            'reminders_header'  => "*Recordatorios activos*\n",
            'notify_event'      => "📅 *¡Evento próximo!*\n\n*%s*\n🕐 %s%s",
            'notify_reminder'   => "⏰ *¡Recordatorio!*\n\n%s",
            'idea_created'      => "💡 *¡Idea guardada!*\n\n*%s*\n%s\n📁 %s\n_#%d_",
            'ideas_empty'       => '💡 Aún no hay ideas guardadas.',
            'ideas_header'      => "*Tus ideas*\n",
            'note_created'      => "📝 *¡Nota guardada!*\n\n*%s*\n%s\n_#%d_",
            'notes_empty'       => '📝 No se encontraron notas.',
            'notes_header'      => "*Tus notas*\n",
            'balance_header'    => "💰 *Resumen financiero*\n",
            'balance_total'     => 'Saldo disponible: *%s*',
            'balance_income'    => 'Ingresos esta semana: %s',
            'balance_expense'   => 'Gastos esta semana: %s',
            'balance_no_accounts' => '💰 Aún no hay cuentas. Prueba: "Pon mi saldo de cuenta corriente en 1200".',
            'balance_set'       => '💰 Saldo de la cuenta *%s* establecido en %s.',
            'tx_income'         => '💵 Ingreso registrado: %s%s. Nuevo saldo de *%s*: %s.',
            'tx_expense'        => '💸 Gasto registrado: %s%s. Nuevo saldo de *%s*: %s.',
            'account_created'   => '🏦 Cuenta *%s* creada.',
            'memory_saved'      => '🧠 Entendido, lo recordaré.',
            'memories_empty'    => '🧠 No hay memorias a largo plazo guardadas.',
            'memories_header'   => "🧠 *Cosas que recuerdo*\n",
            'memory_forgotten'  => '🧠 Olvidado.',
            'memory_not_found'  => '🧠 No encontré una memoria que coincida para olvidar.',
            'config_header'     => "⚙️ *Configuración*\n\n👤 Nombre: %s\n🕐 Zona horaria: %s\n🌐 Idioma: %s\n🔔 Notificaciones: %s\n\n_/config name Juan_\n_/config timezone America/Mexico_City_\n_/config language en|pt-BR|es_\n_/config notifications on|off_\n_/config context Soy ingeniero de software._",
            'config_updated'    => '⚙️ Configuración *%s* actualizada.',
            'config_lang_bad'   => '⚠️ Idioma no soportado. Soportados: %s',
            'on'                => 'activadas',
            'off'               => 'desactivadas',
            'not_set'           => 'sin definir',
            'confirm_needed'    => '⚠️ Confirma por favor: %s\nResponde "sí" para continuar.',
            'summary_title'     => '🌅 *¡Buenos días%s! Aquí está tu día — %s*',
            'summary_events'    => '📅 *Eventos de hoy:*',
            'summary_tasks'     => '✅ *Tareas prioritarias:*',
            'summary_reminders' => '⏰ *Recordatorios de hoy:*',
            'summary_finance'   => '💰 Saldo disponible: %s',
            'summary_nothing'   => 'Nada agendado para hoy. ¡Disfruta! 🎉',
            'suggestion'        => '💡 *Sugerencia:* %s',
            'priority_low'      => 'baja', 'priority_medium' => 'media', 'priority_high' => 'alta', 'priority_urgent' => 'urgente',
            'status_pending'    => 'pendiente', 'status_in_progress' => 'en progreso', 'status_completed' => 'completada', 'status_cancelled' => 'cancelada', 'status_all' => 'todas',
            'rec_daily' => 'diario', 'rec_weekly' => 'semanal', 'rec_biweekly' => 'quincenal', 'rec_monthly' => 'mensual',
        ],
    ];
}
