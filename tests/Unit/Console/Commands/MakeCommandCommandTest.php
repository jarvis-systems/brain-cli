<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console\Commands;

use PHPUnit\Framework\TestCase;

/**
 * Tests for MakeCommandCommand parameter generation and contracts.
 *
 * Uses source inspection to verify naming conventions, file paths,
 * and stub structure without touching the filesystem.
 */
class MakeCommandCommandTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/MakeCommandCommand.php'
        ) ?: '';
    }

    // ─── Source Inspection ───────────────────────────────────────────

    public function test_command_signature(): void
    {
        $this->assertStringContainsString("'make:command", $this->source);
        $this->assertStringContainsString('{name}', $this->source);
        $this->assertStringContainsString('--force', $this->source);
    }

    public function test_command_extends_illuminate_command(): void
    {
        $this->assertStringContainsString('extends Command', $this->source);
    }

    public function test_uses_stub_generator_trait(): void
    {
        $this->assertStringContainsString('use StubGeneratorTrait', $this->source);
    }

    public function test_uses_helpers_trait(): void
    {
        $this->assertStringContainsString('use HelpersTrait', $this->source);
    }

    public function test_uses_self_dev_gate_trait(): void
    {
        $this->assertStringContainsString('use SelfDevGateTrait', $this->source);
    }

    public function test_requires_self_dev_before_execution(): void
    {
        $this->assertStringContainsString('requireSelfDev()', $this->source);
    }

    public function test_handle_returns_int(): void
    {
        $this->assertStringContainsString('public function handle(): int', $this->source);
    }

    public function test_generates_into_commands_directory(): void
    {
        $this->assertStringContainsString("node/Commands/", $this->source);
    }

    public function test_uses_command_stub(): void
    {
        $this->assertStringContainsString("'stub' => 'command'", $this->source);
    }

    public function test_namespace_starts_with_brain_node_commands(): void
    {
        $this->assertStringContainsString("'BrainNode\\\\Commands'", $this->source);
    }

    public function test_appends_command_suffix(): void
    {
        $this->assertStringContainsString("'Command'", $this->source);
        $this->assertStringContainsString("str_ends_with(\$className, 'Command')", $this->source);
    }

    public function test_supports_inner_path_extraction(): void
    {
        $this->assertStringContainsString('extractInnerPathNameName', $this->source);
    }

    // ─── Stub File Existence ─────────────────────────────────────────

    public function test_command_stub_file_exists(): void
    {
        $stubPath = dirname(__DIR__, 4) . '/src/Console/Commands/stubs/command.stub';
        $this->assertFileExists($stubPath);
    }

    public function test_command_stub_has_required_placeholders(): void
    {
        $stubPath = dirname(__DIR__, 4) . '/src/Console/Commands/stubs/command.stub';
        $content = file_get_contents($stubPath) ?: '';

        $this->assertStringContainsString('{{ namespace }}', $content);
        $this->assertStringContainsString('{{ className }}', $content);
        $this->assertStringContainsString('{{ commandId }}', $content);
        $this->assertStringContainsString('{{ purpose }}', $content);
    }

    public function test_command_stub_extends_command_archetype(): void
    {
        $stubPath = dirname(__DIR__, 4) . '/src/Console/Commands/stubs/command.stub';
        $content = file_get_contents($stubPath) ?: '';

        $this->assertStringContainsString('extends CommandArchetype', $content);
        $this->assertStringContainsString('use BrainCore\Archetypes\CommandArchetype', $content);
    }

    public function test_command_stub_has_strict_types(): void
    {
        $stubPath = dirname(__DIR__, 4) . '/src/Console/Commands/stubs/command.stub';
        $content = file_get_contents($stubPath) ?: '';

        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }
}
