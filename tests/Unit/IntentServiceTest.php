<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\{OpenAIService, IntentService};
use Tests\Support\FakeOpenAI;

class IntentServiceTest extends TestCase
{
    public function testParsesValidIntent(): void
    {
        $json = json_encode([
            'intent'     => 'create_task',
            'confidence' => 0.95,
            'language'   => 'en',
            'data'       => ['title' => 'Review contract', 'priority' => 'urgent'],
        ]);
        $intent = new IntentService(new OpenAIService(FakeOpenAI::client([$json])));

        $result = $intent->extract('create urgent task review contract', 'UTC', 'en');
        $this->assertEquals('create_task', $result['intent']);
        $this->assertEquals('urgent', $result['data']['priority']);
        $this->assertEqualsWithDelta(0.95, $result['confidence'], 0.001);
    }

    public function testStripsCodeFences(): void
    {
        $fenced = "```json\n{\"intent\":\"daily_summary\",\"confidence\":0.8,\"language\":\"en\",\"data\":{}}\n```";
        $intent = new IntentService(new OpenAIService(FakeOpenAI::client([$fenced])));

        $result = $intent->extract('give me my summary', 'UTC', 'en');
        $this->assertEquals('daily_summary', $result['intent']);
    }

    public function testFallsBackToFreeChatOnGarbage(): void
    {
        $intent = new IntentService(new OpenAIService(FakeOpenAI::client(['not json at all'])));

        $result = $intent->extract('hello', 'UTC', 'en');
        $this->assertEquals('free_chat', $result['intent']);
        $this->assertEquals([], $result['data']);
    }

    public function testFallsBackOnUnknownIntentName(): void
    {
        $json = json_encode(['intent' => 'make_coffee', 'confidence' => 0.9, 'data' => []]);
        $intent = new IntentService(new OpenAIService(FakeOpenAI::client([$json])));

        $result = $intent->extract('make me a coffee', 'UTC', 'en');
        $this->assertEquals('free_chat', $result['intent']);
    }
}
