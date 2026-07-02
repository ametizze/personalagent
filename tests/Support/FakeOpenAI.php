<?php

namespace Tests\Support;

use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;

/**
 * Helpers to build a faked OpenAI client with canned chat completions, so tests
 * never touch the real API.
 */
class FakeOpenAI
{
    public static function response(string $content): CreateResponse
    {
        return CreateResponse::fake([
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => $content]],
            ],
        ]);
    }

    /** @param string[] $contents one canned reply per upcoming chat()->create() call */
    public static function client(array $contents): ClientFake
    {
        return new ClientFake(array_map(self::response(...), $contents));
    }
}
