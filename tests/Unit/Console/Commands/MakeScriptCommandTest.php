<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console\Commands;

use PHPUnit\Framework\TestCase;

/**
 * Tests for MakeScriptCommand parameter generation and contracts.
 *
 * Uses source inspection to verify naming conventions, file paths,
 * and stub structure without touching the filesystem.
 *
 * Note: MakeScriptCommand generates into scripts/ (not node/)
 * with BrainScripts namespace (not BrainNode). The stub extends
 * Illuminate\Console\Command directly (not a Brain archetype).
 */
class MakeScriptCommandTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/MakeScriptCommand.php'
        ) ?: '';
    }

    // ─── Source Inspection ───────────────────────────────────────────

    public function test_command_signature(): void
    {
        $this->assertStringContainsString("'make:script", $this->source);
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

    public function test_generates_into_scripts_directory(): void
    {
        // Scripts go into scripts/ NOT node/ — distinct from other archetypes
        $this->assertStringContainsString("scripts/", $this->source);
    }

    public function test_uses_script_stub(): void
    {
        $this->assertStringContainsString("'stub' => 'script'", $this->source);
    }

    public function test_namespace_starts_with_brain_scripts(): void
    {
        // Scripts use BrainScripts namespace, NOT BrainNode
        $this->assertStringContainsString("'BrainScripts'", $this->source);
    }

    public function test_appends_script_suffix(): void
    {
        $this->assertStringContainsString("'Script'", $this->source);
        $this->assertStringContainsString("str_ends_with(\$className, 'Script')", $this->source);
    }

    public function test_supports_inner_path_extraction(): void
    {
        $this->assertStringContainsString('extractInnerPathNameName', $this->source);
    }

    // ─── Stub File Existence ─────────────────────────────────────────

    public function test_script_stub_file_exists(): void
    {
        $stubPath = dirname(__DIR__, 4) . '/src/Console/Commands/stubs/script.stub';
        $this->assertFileExists($stubPath);
    }

    public function test_script_stub_has_required_placeholders(): void
    {
        $stubPath = dirname(__DIR__, 4) . '/src/Console/Commands/stubs/script.stub';
        $content = file_get_contents($stubPath) ?: '';

        $this->assertStringContainsString('{{ namespace }}', $content);
        $this->assertStringContainsString('{{ className }}', $content);
        $this->assertStringContainsString('{{ signature }}', $content);
    }

    public function test_script_stub_extends_illuminate_command(): void
    {
        $stubPath = dirname(__DIR__, 4) . '/src/Console/Commands/stubs/script.stub';
        $content = file_get_contents($stubPath) ?: '';

        // Scripts extend Illuminate Command directly, NOT a Brain archetype
        $this->assertStringContainsString('extends Command', $content);
        $this->assertStringContainsString('use Illuminate\Console\Command', $content);
    }

    public function test_script_stub_has_strict_types(): void
    {
        $stubPath = dirname(__DIR__, 4) . '/src/Console/Commands/stubs/script.stub';
        $content = file_get_contents($stubPath) ?: '';

        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }
}
