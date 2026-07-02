<?php

namespace App\Services;

use App\Models\Memory;

/**
 * Long-term memory operations. Memory is only ever written when the user
 * explicitly asks the assistant to remember something.
 */
class MemoryService
{
    private const CATEGORIES = ['personal', 'preference', 'work', 'family', 'health', 'project', 'finance', 'other'];

    public static function remember(int $chatId, string $content, string $category = 'other', int $importance = 3): int
    {
        $category = in_array($category, self::CATEGORIES, true) ? $category : 'other';
        $importance = max(1, min(5, $importance));
        return Memory::create($chatId, trim($content), $category, $importance);
    }

    public static function list(int $chatId): array
    {
        return Memory::list($chatId);
    }

    /** Memories injected into the conversation system prompt. */
    public static function forContext(int $chatId): array
    {
        return Memory::list($chatId, 20);
    }

    public static function forgetById(int $chatId, int $id): bool
    {
        return Memory::forget($id, $chatId);
    }

    /**
     * Forget by matching free text ("forget that I work with Laravel").
     * Returns the number of memories deactivated.
     */
    public static function forgetByText(int $chatId, string $term): int
    {
        $matches = Memory::search($chatId, $term);
        foreach ($matches as $m) {
            Memory::forget((int)$m['id'], $chatId);
        }
        return count($matches);
    }
}
