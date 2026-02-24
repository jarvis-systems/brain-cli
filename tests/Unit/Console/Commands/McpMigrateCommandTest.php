<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console\Commands;

use PHPUnit\Framework\TestCase;

/**
 * Tests for McpMigrateCommand structure and contracts.
 *
 * Uses source inspection to verify command behavior
 * without requiring database or migration infrastructure.
 */
class McpMigrateCommandTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/McpMigrateCommand.php'
        ) ?: '';
    }

    public function test_command_signature_is_mcp_migrate(): void
    {
        $this->assertStringContainsString("'mcp:migrate'", $this->source);
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

    public function test_handle_returns_success(): void
    {
        $this->assertStringContainsString('return self::SUCCESS', $this->source);
    }

    public function test_uses_migration_runner(): void
    {
        $this->assertStringContainsString('MigrationRunner::run()', $this->source);
        $this->assertStringContainsString('use BrainCLI\Database\Migrations\MigrationRunner', $this->source);
    }

    public function test_outputs_completion_message(): void
    {
        $this->assertStringContainsString('Database migrations completed.', $this->source);
    }

    public function test_has_description(): void
    {
        $this->assertStringContainsString('$description', $this->source);
        $this->assertStringContainsString('migrations', $this->source);
    }
}
