<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console\Commands;

use PHPUnit\Framework\TestCase;

/**
 * Tests for StatusCommand structure and contracts.
 *
 * Uses source inspection to verify command behavior
 * without requiring the Task model or database.
 */
class StatusCommandTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/StatusCommand.php'
        ) ?: '';
    }

    public function test_command_signature_is_status(): void
    {
        $this->assertStringContainsString("'status'", $this->source);
    }

    public function test_command_extends_illuminate_command(): void
    {
        $this->assertStringContainsString('extends Command', $this->source);
        $this->assertStringContainsString('use Illuminate\Console\Command', $this->source);
    }

    public function test_handle_returns_int(): void
    {
        $this->assertStringContainsString('public function handle(): int', $this->source);
    }

    public function test_handle_returns_zero(): void
    {
        $this->assertStringContainsString('return 0;', $this->source);
    }

    public function test_uses_task_model(): void
    {
        $this->assertStringContainsString('use BrainCLI\Models\Task', $this->source);
        $this->assertStringContainsString('Task::all()', $this->source);
    }

    public function test_outputs_json_format(): void
    {
        $this->assertStringContainsString('json_encode', $this->source);
        $this->assertStringContainsString('JSON_PRETTY_PRINT', $this->source);
        $this->assertStringContainsString('JSON_UNESCAPED_UNICODE', $this->source);
    }

    public function test_uses_first_task(): void
    {
        $this->assertStringContainsString('->first()', $this->source);
    }
}
