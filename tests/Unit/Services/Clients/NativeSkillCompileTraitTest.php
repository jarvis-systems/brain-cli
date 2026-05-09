<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Services\Clients;

use BrainCLI\Abstracts\CommandBridgeAbstract;
use BrainCLI\Dto\Compile\Data;
use BrainCLI\Dto\Compile\SkillInfo;
use BrainCLI\Enums\CompiledData\Format;
use BrainCLI\Services\Clients\CodexClient;
use PHPUnit\Framework\TestCase;

class NativeSkillCompileTraitTest extends TestCase
{
    public function test_duplicate_skill_names_are_rejected(): void
    {
        $client = $this->client();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Duplicate skill name [same-skill]');

        $client->exposeAssertUniqueSkillNames([
            $this->skill('same-skill', 'node/Skills/SameSkill.php'),
            $this->skill('same-skill', 'node/Skills/same-skill/SKILL.md'),
        ]);
    }

    public function test_codex_native_resource_target_is_skill_directory(): void
    {
        $client = $this->client();
        $info = SkillInfo::fromAssoc([
            'filename' => 'native-skill',
            'insidePath' => '',
            'name' => 'native-skill',
            'description' => 'Native skill',
            'meta' => ['_native' => true],
        ]);

        $target = $client->exposeNativeSkillResourceTargetDirectory(
            '/tmp/project/.codex/skills',
            $info,
            '/tmp/project/.codex/skills/native-skill/SKILL.md'
        );

        $this->assertSame('/tmp/project/.codex/skills/native-skill', $target);
    }

    public function test_flat_native_resource_target_is_named_subdirectory(): void
    {
        $client = $this->client();
        $info = SkillInfo::fromAssoc([
            'filename' => 'native-skill',
            'insidePath' => '',
            'name' => 'native-skill',
            'description' => 'Native skill',
            'meta' => ['_native' => true],
        ]);

        $target = $client->exposeNativeSkillResourceTargetDirectory(
            '/tmp/project/.claude/skills',
            $info,
            '/tmp/project/.claude/skills/native-skill.md'
        );

        $this->assertSame('/tmp/project/.claude/skills/native-skill', $target);
    }

    private function client(): object
    {
        return new class(new class extends CommandBridgeAbstract {
            protected $signature = 'test:bridge';

            protected function handleBridge(): int|array
            {
                return 0;
            }
        }) extends CodexClient {
            public function exposeAssertUniqueSkillNames(array $skills): void
            {
                $this->assertUniqueSkillNames($skills);
            }

            public function exposeNativeSkillResourceTargetDirectory(
                string $skillsDirectory,
                SkillInfo $info,
                string $skillFile
            ): string {
                return $this->nativeSkillResourceTargetDirectory($skillsDirectory, $info, $skillFile);
            }
        };
    }

    private function skill(string $name, string $file): Data
    {
        return Data::fromAssoc([
            'id' => $name . '-skill',
            'file' => $file,
            'class' => 'BrainNode\\Skills\\TestSkill',
            'meta' => ['name' => $name, 'description' => 'Test skill'],
            'namespace' => 'BrainNode\\Skills',
            'namespaceType' => 'Skills',
            'classBasename' => 'TestSkill',
            'format' => Format::XML,
            'structure' => '# Test',
        ]);
    }
}
