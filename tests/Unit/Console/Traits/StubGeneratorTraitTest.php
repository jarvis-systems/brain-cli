<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console\Traits;

use BrainCLI\Console\Commands\MakeIncludeCommand;
use BrainCLI\Console\Commands\MakeMasterCommand;
use BrainCLI\Console\Commands\MakeSkillCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for StubGeneratorTrait::generateStub() via Reflection.
 *
 * Uses concrete Make*Command classes to invoke the trait method,
 * verifying placeholder replacement, stub discovery, and output structure.
 */
class StubGeneratorTraitTest extends TestCase
{
    protected function setUp(): void
    {
        defined('DS') || define('DS', DIRECTORY_SEPARATOR);
        defined('OK') || define('OK', 0);
        defined('ERROR') || define('ERROR', 1);
    }

    // ─── Stub discovery: correct stub file is selected ──────────────

    #[Test]
    public function generate_stub_loads_agent_stub_for_make_master(): void
    {
        $result = $this->callGenerateStub(
            new MakeMasterCommand(),
            'agent',
            ['namespace' => 'BrainNode\\Agents', 'className' => 'FooMaster', 'agentId' => 'foo-master', 'agentPurpose' => 'Test']
        );

        $this->assertStringContainsString('extends AgentArchetype', $result);
        $this->assertStringContainsString('class FooMaster', $result);
    }

    #[Test]
    public function generate_stub_loads_skill_stub_for_make_skill(): void
    {
        $result = $this->callGenerateStub(
            new MakeSkillCommand(),
            'skill',
            ['namespace' => 'BrainNode\\Skills', 'className' => 'BarSkill', 'purpose' => 'Test skill']
        );

        $this->assertStringContainsString('extends SkillArchetype', $result);
        $this->assertStringContainsString('class BarSkill', $result);
    }

    #[Test]
    public function generate_stub_loads_include_stub_for_make_include(): void
    {
        $result = $this->callGenerateStub(
            new MakeIncludeCommand(),
            'include',
            ['namespace' => 'BrainNode\\Includes', 'className' => 'BazInclude', 'purpose' => 'Test include']
        );

        $this->assertStringContainsString('extends IncludeArchetype', $result);
        $this->assertStringContainsString('class BazInclude', $result);
    }

    // ─── Placeholder replacement ────────────────────────────────────

    #[Test]
    public function generate_stub_replaces_namespace_placeholder(): void
    {
        $result = $this->callGenerateStub(
            new MakeMasterCommand(),
            'agent',
            ['namespace' => 'BrainNode\\Custom\\Agents', 'className' => 'XMaster', 'agentId' => 'x', 'agentPurpose' => 'X']
        );

        $this->assertStringContainsString('namespace BrainNode\\Custom\\Agents;', $result);
        $this->assertStringNotContainsString('{{ namespace }}', $result);
    }

    #[Test]
    public function generate_stub_replaces_class_name_placeholder(): void
    {
        $result = $this->callGenerateStub(
            new MakeSkillCommand(),
            'skill',
            ['namespace' => 'BrainNode\\Skills', 'className' => 'SearchSkill', 'purpose' => 'Search']
        );

        $this->assertStringContainsString('class SearchSkill extends', $result);
        $this->assertStringNotContainsString('{{ className }}', $result);
    }

    #[Test]
    public function generate_stub_replaces_all_placeholders_in_agent(): void
    {
        $result = $this->callGenerateStub(
            new MakeMasterCommand(),
            'agent',
            [
                'namespace' => 'BrainNode\\Agents',
                'className' => 'DeployMaster',
                'agentId' => 'deploy-master',
                'agentPurpose' => 'Deployment orchestrator',
            ]
        );

        $this->assertStringContainsString("'id', 'deploy-master'", $result);
        $this->assertStringContainsString("'Deployment orchestrator'", $result);
        $this->assertStringContainsString('class DeployMaster', $result);
        $this->assertStringContainsString('namespace BrainNode\\Agents;', $result);

        // No unreplaced placeholders should remain
        $this->assertStringNotContainsString('{{ ', $result);
    }

    #[Test]
    public function generate_stub_replaces_all_placeholders_in_skill(): void
    {
        $result = $this->callGenerateStub(
            new MakeSkillCommand(),
            'skill',
            [
                'namespace' => 'BrainNode\\Skills',
                'className' => 'CacheSkill',
                'purpose' => 'Cache management',
            ]
        );

        $this->assertStringContainsString("'Cache management'", $result);
        $this->assertStringContainsString('class CacheSkill', $result);
        $this->assertStringNotContainsString('{{ ', $result);
    }

    #[Test]
    public function generate_stub_replaces_all_placeholders_in_include(): void
    {
        $result = $this->callGenerateStub(
            new MakeIncludeCommand(),
            'include',
            [
                'namespace' => 'BrainNode\\Includes\\Security',
                'className' => 'AuthInclude',
                'purpose' => 'Authentication rules',
            ]
        );

        $this->assertStringContainsString("'Authentication rules'", $result);
        $this->assertStringContainsString('class AuthInclude', $result);
        $this->assertStringNotContainsString('{{ ', $result);
    }

    // ─── Output structure ───────────────────────────────────────────

    #[Test]
    public function generated_stub_has_strict_types_declaration(): void
    {
        $result = $this->callGenerateStub(
            new MakeMasterCommand(),
            'agent',
            ['namespace' => 'BrainNode\\Agents', 'className' => 'A', 'agentId' => 'a', 'agentPurpose' => 'a']
        );

        $this->assertStringContainsString('declare(strict_types=1)', $result);
    }

    #[Test]
    public function generated_stub_starts_with_php_tag(): void
    {
        $result = $this->callGenerateStub(
            new MakeSkillCommand(),
            'skill',
            ['namespace' => 'BrainNode\\Skills', 'className' => 'S', 'purpose' => 's']
        );

        $this->assertStringStartsWith('<?php', $result);
    }

    #[Test]
    public function generated_stub_contains_handle_method(): void
    {
        $result = $this->callGenerateStub(
            new MakeIncludeCommand(),
            'include',
            ['namespace' => 'BrainNode\\Includes', 'className' => 'I', 'purpose' => 'i']
        );

        $this->assertStringContainsString('protected function handle(): void', $result);
    }

    // ─── Error handling: missing stub ────────────────────────────────

    #[Test]
    public function generate_stub_throws_on_nonexistent_stub(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Stub file nonexistent-stub-xyz.stub does not exist');

        $this->callGenerateStub(
            new MakeMasterCommand(),
            'nonexistent-stub-xyz',
            []
        );
    }

    // ─── Helpers ────────────────────────────────────────────────────

    /**
     * Call generateStub() on a command instance via reflection.
     */
    private function callGenerateStub(object $command, string $stubName, array $replacements): string
    {
        $method = new ReflectionMethod($command, 'generateStub');

        /** @var string */
        return $method->invoke($command, $stubName, $replacements);
    }
}
