<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console\Commands;

use PHPUnit\Framework\TestCase;

/**
 * Tests for MakeIncludeCommand parameter generation and contracts.
 *
 * Uses source inspection to verify naming conventions, file paths,
 * and stub structure without touching the filesystem.
 */
class MakeIncludeCommandTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/MakeIncludeCommand.php'
        ) ?: '';
    }

    // ─── Source Inspection ───────────────────────────────────────────

    public function test_command_signature(): void
    {
        $this->assertStringContainsString("'make:include", $this->source);
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

    public function test_generates_into_includes_directory(): void
    {
        $this->assertStringContainsString("node/Includes/", $this->source);
    }

    public function test_uses_include_stub(): void
    {
        $this->assertStringContainsString("'stub' => 'include'", $this->source);
    }

    public function test_namespace_starts_with_brain_node_includes(): void
    {
        $this->assertStringContainsString("'BrainNode\\\\Includes'", $this->source);
    }

    public function test_appends_include_suffix(): void
    {
        $this->assertStringContainsString("'Include'", $this->source);
        $this->assertStringContainsString("str_ends_with(\$className, 'Include')", $this->source);
    }

    public function test_supports_inner_path_extraction(): void
    {
        // MakeIncludeCommand uses extractInnerPathNameName for nested namespaces
        $this->assertStringContainsString('extractInnerPathNameName', $this->source);
    }

    // ─── Stub File Existence ─────────────────────────────────────────

    public function test_include_stub_file_exists(): void
    {
        $stubPath = dirname(__DIR__, 4) . '/src/Console/Commands/stubs/include.stub';
        $this->assertFileExists($stubPath);
    }

    public function test_include_stub_has_required_placeholders(): void
    {
        $stubPath = dirname(__DIR__, 4) . '/src/Console/Commands/stubs/include.stub';
        $content = file_get_contents($stubPath) ?: '';

        $this->assertStringContainsString('{{ namespace }}', $content);
        $this->assertStringContainsString('{{ className }}', $content);
        $this->assertStringContainsString('{{ purpose }}', $content);
    }

    public function test_include_stub_extends_include_archetype(): void
    {
        $stubPath = dirname(__DIR__, 4) . '/src/Console/Commands/stubs/include.stub';
        $content = file_get_contents($stubPath) ?: '';

        $this->assertStringContainsString('extends IncludeArchetype', $content);
        $this->assertStringContainsString('use BrainCore\Archetypes\IncludeArchetype', $content);
    }

    public function test_include_stub_has_strict_types(): void
    {
        $stubPath = dirname(__DIR__, 4) . '/src/Console/Commands/stubs/include.stub';
        $content = file_get_contents($stubPath) ?: '';

        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    public function test_include_stub_has_purpose_attribute(): void
    {
        $stubPath = dirname(__DIR__, 4) . '/src/Console/Commands/stubs/include.stub';
        $content = file_get_contents($stubPath) ?: '';

        $this->assertStringContainsString('#[Purpose(', $content);
        $this->assertStringContainsString('use BrainCore\Attributes\Purpose', $content);
    }
}
