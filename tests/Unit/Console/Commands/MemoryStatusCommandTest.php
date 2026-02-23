<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console\Commands;

use PHPUnit\Framework\TestCase;

/**
 * Source inspection tests for MemoryStatusCommand.
 *
 * Verifies command structure, options, and integration patterns
 * without executing the command or spawning processes.
 */
class MemoryStatusCommandTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/MemoryStatusCommand.php'
        ) ?: '';
    }

    public function test_command_signature_is_memory_status(): void
    {
        $this->assertStringContainsString("'memory:status", $this->source);
    }

    public function test_json_option_exists(): void
    {
        $this->assertStringContainsString('--json', $this->source);
    }

    public function test_handle_uses_command_kernel(): void
    {
        $this->assertStringContainsString('CommandKernel::run(', $this->source);
    }

    public function test_command_has_help_examples(): void
    {
        $this->assertStringContainsString('function getHelp()', $this->source);
        $this->assertStringContainsString('Examples:', $this->source);
        $this->assertStringContainsString('brain memory:status', $this->source);
        $this->assertStringContainsString('--json', $this->source);
    }

    public function test_exit_code_is_always_ok(): void
    {
        // Both branches (JSON and human output) return OK
        $this->assertStringNotContainsString('return ERROR', $this->source);
    }
}
