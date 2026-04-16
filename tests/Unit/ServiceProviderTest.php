<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ServiceProviderTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = file_get_contents(
            dirname(__DIR__, 2) . '/src/ServiceProvider.php'
        ) ?: '';
    }

    public function test_init_command_skips_database_bootstrap(): void
    {
        $this->assertStringContainsString('if (static::shouldBootDatabase()) {', $this->source);
        $this->assertStringContainsString('return static::currentCommandName() !== \'init\';', $this->source);
    }
}
