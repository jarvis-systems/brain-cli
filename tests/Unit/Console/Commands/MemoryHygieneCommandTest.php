<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console\Commands;

use PHPUnit\Framework\TestCase;

/**
 * Source inspection tests for MemoryHygieneCommand.
 *
 * Verifies command structure, options, and integration patterns
 * without spawning processes or MCP servers.
 */
class MemoryHygieneCommandTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/MemoryHygieneCommand.php'
        ) ?: '';
    }

    public function test_command_signature_is_memory_hygiene(): void
    {
        $this->assertStringContainsString("'memory:hygiene", $this->source);
    }

    public function test_consolidate_option_exists(): void
    {
        $this->assertStringContainsString('--consolidate', $this->source);
    }

    public function test_yes_option_exists(): void
    {
        $this->assertStringContainsString('--yes', $this->source);
    }

    public function test_handle_uses_command_kernel(): void
    {
        $this->assertStringContainsString('CommandKernel::run(', $this->source);
    }

    public function test_output_dir_is_work_memory_hygiene(): void
    {
        $this->assertStringContainsString('.work/memory-hygiene', $this->source);
    }
}
