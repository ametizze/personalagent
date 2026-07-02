<?php

namespace App\Support;

/**
 * Small presentation helpers shared by the command handler and jobs. Anything
 * language-specific is resolved via LanguageService; this class only handles
 * language-neutral formatting (icons, money).
 */
class ResponseFormatter
{
    public static function priorityIcon(string $priority): string
    {
        return match ($priority) {
            'urgent' => '🔴',
            'high'   => '🟠',
            'low'    => '🟢',
            default  => '🟡',
        };
    }

    public static function recurrenceIcon(?string $recurrence): string
    {
        return $recurrence ? ' 🔄' : '';
    }

    public static function money(float $amount, string $currency = 'USD'): string
    {
        $symbols = ['USD' => '$', 'BRL' => 'R$', 'EUR' => '€', 'GBP' => '£'];
        $symbol = $symbols[strtoupper($currency)] ?? '';
        $formatted = number_format($amount, 2);
        return $symbol !== '' ? "{$symbol}{$formatted}" : "{$formatted} {$currency}";
    }
}
