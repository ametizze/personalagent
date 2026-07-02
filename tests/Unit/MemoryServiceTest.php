<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Database\{Database, Migration};
use App\Services\MemoryService;

class MemoryServiceTest extends TestCase
{
    protected function setUp(): void
    {
        Database::reset();
        Database::connect(':memory:');
        (new Migration(Database::get()))->run();
    }

    public function testRememberClampsCategoryAndImportance(): void
    {
        MemoryService::remember(123, 'My timezone is America/New_York', 'bogus-category', 99);
        $memories = MemoryService::list(123);
        $this->assertCount(1, $memories);
        $this->assertEquals('other', $memories[0]['category']);
        $this->assertEquals(5, $memories[0]['importance']);
    }

    public function testForgetById(): void
    {
        $id = MemoryService::remember(123, "Daughter's name is Brie", 'family', 5);
        $this->assertTrue(MemoryService::forgetById(123, $id));
        $this->assertCount(0, MemoryService::list(123));
    }

    public function testForgetByText(): void
    {
        MemoryService::remember(123, 'I work with Laravel', 'work', 3);
        MemoryService::remember(123, 'I prefer short answers', 'preference', 4);

        $count = MemoryService::forgetByText(123, 'Laravel');
        $this->assertEquals(1, $count);
        $this->assertCount(1, MemoryService::list(123));
    }

    public function testForContextIsScopedPerChat(): void
    {
        MemoryService::remember(123, 'fact A', 'other', 3);
        MemoryService::remember(456, 'fact B', 'other', 3);
        $this->assertCount(1, MemoryService::forContext(123));
    }
}
