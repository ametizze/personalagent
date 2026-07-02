<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\LanguageService;

class LanguageServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['APP_DEFAULT_LANGUAGE'] = 'en';
        $_ENV['APP_SUPPORTED_LANGUAGES'] = 'en,pt-BR,es';
    }

    public function testDetectPortuguese(): void
    {
        $this->assertEquals('pt-BR', LanguageService::detect('Me lembre amanhã de pagar o aluguel por favor'));
    }

    public function testDetectSpanish(): void
    {
        $this->assertEquals('es', LanguageService::detect('Recuérdame mañana pagar el alquiler'));
    }

    public function testDetectFallsBackToDefault(): void
    {
        $this->assertEquals('en', LanguageService::detect('ok'));
    }

    public function testResolvePrefersStoredPreference(): void
    {
        // Message looks English, but stored preference wins.
        $this->assertEquals('pt-BR', LanguageService::resolve('pt-BR', 'hello there'));
    }

    public function testTranslateFallsBackAcrossLanguagesAndKeys(): void
    {
        $this->assertSame('on', LanguageService::t('on', 'en'));
        $this->assertSame('ativadas', LanguageService::t('on', 'pt-BR'));
        // Unknown key returns the key itself.
        $this->assertSame('totally_unknown_key', LanguageService::t('totally_unknown_key', 'en'));
    }

    public function testTranslateWithParams(): void
    {
        $this->assertSame('⚙️ Setting *name* updated.', LanguageService::t('config_updated', 'en', ['name']));
    }
}
