<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console\Commands;

use PHPUnit\Framework\TestCase;

/**
 * Tests for MakeMasterCommand parameter generation and contracts.
 *
 * Uses source inspection to verify naming conventions, file paths,
 * and stub structure without touching the filesystem.
 */
class MakeMasterCommandTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/MakeMasterCommand.php'
        ) ?: '';
    }

    // ─── Source Inspection ───────────────────────────────────────────

    public function test_command_signature(): void
    {
        $this->assertStringContainsString("'make:master", $this->source);
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

    public function test_generates_into_agents_directory(): void
    {
        $this->assertStringContainsString("node/Agents/", $this->source);
    }

    public function test_uses_agent_stub(): void
    {
        $this->assertStringContainsString("'stub' => 'agent'", $this->source);
    }

    public function test_namespace_is_brain_node_agents(): void
    {
        $this->assertStringContainsString("'BrainNode\\\\Agents'", $this->source);
    }

    public function test_appends_master_suffix(): void
    {
        $this->assertStringContainsString("'Master'", $this->source);
        $this->assertStringContainsString("str_ends_with(\$className, 'Master')", $this->source);
    }

    public function test_generates_agent_id_from_name(): void
    {
        $this->assertStringContainsString("Str::snake(\$name, '-')", $this->source);
    }

    // ─── Stub File Existence ─────────────────────────────────────────

    public function test_agent_stub_file_exists(): void
    {
        $stubPath = dirname(__DIR__, 4) . '/src/Console/Commands/stubs/agent.stub';
        $this->assertFileExists($stubPath);
    }

    public function test_agent_stub_has_required_placeholders(): void
    {
        $stubPath = dirname(__DIR__, 4) . '/src/Console/Commands/stubs/agent.stub';
        $content = file_get_contents($stubPath) ?: '';

        $this->assertStringContainsString('{{ namespace }}', $content);
        $this->assertStringContainsString('{{ className }}', $content);
        $this->assertStringContainsString('{{ agentId }}', $content);
        $this->assertStringContainsString('{{ agentPurpose }}', $content);
    }

    public function test_agent_stub_extends_agent_archetype(): void
    {
        $stubPath = dirname(__DIR__, 4) . '/src/Console/Commands/stubs/agent.stub';
        $content = file_get_contents($stubPath) ?: '';

        $this->assertStringContainsString('extends AgentArchetype', $content);
        $this->assertStringContainsString('use BrainCore\Archetypes\AgentArchetype', $content);
    }

    public function test_agent_stub_has_strict_types(): void
    {
        $stubPath = dirname(__DIR__, 4) . '/src/Console/Commands/stubs/agent.stub';
        $content = file_get_contents($stubPath) ?: '';

        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }
}
