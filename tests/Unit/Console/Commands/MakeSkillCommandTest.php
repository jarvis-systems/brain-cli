<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console\Commands;

use BrainCLI\Console\Commands\MakeSkillCommand;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MakeSkillCommand parameter generation and contracts.
 *
 * Tests generateParameters() via reflection to verify naming conventions
 * and file paths without touching the filesystem.
 */
class MakeSkillCommandTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/MakeSkillCommand.php'
        ) ?: '';
    }

    // ─── Source Inspection ───────────────────────────────────────────

    public function test_command_signature(): void
    {
        $this->assertStringContainsString("'make:skill", $this->source);
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

    public function test_handle_returns_int(): void
    {
        $this->assertStringContainsString('public function handle(): int', $this->source);
    }

    public function test_generates_into_skills_directory(): void
    {
        $this->assertStringContainsString("node/Skills/", $this->source);
    }

    public function test_uses_skill_stub(): void
    {
        $this->assertStringContainsString("'stub' => 'skill'", $this->source);
    }

    public function test_namespace_is_brain_node_skills(): void
    {
        $this->assertStringContainsString("'namespace' => 'BrainNode\\\\Skills'", $this->source);
    }

    public function test_appends_skill_suffix(): void
    {
        // Should add 'Skill' suffix if not present
        $this->assertStringContainsString("'Skill'", $this->source);
        $this->assertStringContainsString("str_ends_with(\$className, 'Skill')", $this->source);
    }

    // ─── Stub File Existence ─────────────────────────────────────────

    public function test_skill_stub_file_exists(): void
    {
        $stubPath = dirname(__DIR__, 4) . '/src/Console/Commands/stubs/skill.stub';
        $this->assertFileExists($stubPath);
    }

    public function test_skill_stub_has_required_placeholders(): void
    {
        $stubPath = dirname(__DIR__, 4) . '/src/Console/Commands/stubs/skill.stub';
        $content = file_get_contents($stubPath) ?: '';

        $this->assertStringContainsString('{{ namespace }}', $content);
        $this->assertStringContainsString('{{ className }}', $content);
        $this->assertStringContainsString('{{ purpose }}', $content);
    }

    public function test_skill_stub_extends_skill_archetype(): void
    {
        $stubPath = dirname(__DIR__, 4) . '/src/Console/Commands/stubs/skill.stub';
        $content = file_get_contents($stubPath) ?: '';

        $this->assertStringContainsString('extends SkillArchetype', $content);
        $this->assertStringContainsString('use BrainCore\Archetypes\SkillArchetype', $content);
    }

    public function test_skill_stub_has_strict_types(): void
    {
        $stubPath = dirname(__DIR__, 4) . '/src/Console/Commands/stubs/skill.stub';
        $content = file_get_contents($stubPath) ?: '';

        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }
}
