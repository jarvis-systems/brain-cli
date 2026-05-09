<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Services\Compile;

use BrainCLI\Services\Compile\NativeSkillCollector;
use PHPUnit\Framework\TestCase;

class NativeSkillCollectorTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/brain-native-skill-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/node/Skills/example-skill/references', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmp);
    }

    public function test_collects_native_skill_front_matter_and_body(): void
    {
        file_put_contents($this->tmp . '/node/Skills/example-skill/SKILL.md', <<<MD
---
name: example-skill
description: Example native skill
---

# Example

Use this skill for tests.
MD);

        file_put_contents($this->tmp . '/node/Skills/example-skill/references/details.md', 'details');

        $skills = (new NativeSkillCollector())->collect($this->tmp . '/node/Skills', '.brain');

        $this->assertCount(1, $skills);
        $this->assertSame('example-skill-skill', $skills[0]->id);
        $this->assertSame('example-skill', $skills[0]->meta['name']);
        $this->assertSame('Example native skill', $skills[0]->meta['description']);
        $this->assertTrue($skills[0]->meta['_native']);
        $this->assertStringContainsString('Use this skill for tests.', (string) $skills[0]->structure);
    }

    public function test_requires_front_matter_name_and_description(): void
    {
        file_put_contents($this->tmp . '/node/Skills/example-skill/SKILL.md', <<<MD
---
name: example-skill
---

# Example
MD);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('description');

        (new NativeSkillCollector())->collect($this->tmp . '/node/Skills', '.brain');
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
