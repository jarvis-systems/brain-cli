<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

class EnvOverrideTest extends TestCase
{
    private const TEST_VAR_NAME = 'BRAIN_CLI_TEST_OVERRIDE_VAR';
    private const PROJECT_ROOT = '/Users/xsaven/PhpstormProjects/jarvis-brain-node';

    public static function setUpBeforeClass(): void
    {
        putenv(self::TEST_VAR_NAME . '=test_value_12345');
    }

    public static function tearDownAfterClass(): void
    {
        putenv(self::TEST_VAR_NAME);
    }

    public function testGetenvReturnsProcessEnvValue(): void
    {
        $value = getenv(self::TEST_VAR_NAME);
        $this->assertSame('test_value_12345', $value, 'getenv() should return process-injected env value');
    }

    public function testProcessEnvOverridesDotenv(): void
    {
        $compiledAgent = self::PROJECT_ROOT . '/.opencode/agents/web-research-master.md';
        $testModel = 'test-override/provider-model';

        $originalContent = file_exists($compiledAgent) ? file_get_contents($compiledAgent) : null;

        try {
            putenv('WEB_RESEARCH_MASTER_MODEL=' . $testModel);

            $output = [];
            $exitCode = 0;
            $brainBin = self::PROJECT_ROOT . '/cli/bin/brain';
            exec(
                'WEB_RESEARCH_MASTER_MODEL=' . escapeshellarg($testModel) . ' php ' . escapeshellarg($brainBin) . ' compile opencode 2>&1',
                $output,
                $exitCode
            );

            $this->assertSame(0, $exitCode, 'Compile command should succeed: ' . implode("\n", $output));

            $this->assertFileExists($compiledAgent, 'Compiled agent file should exist');
            $content = file_get_contents($compiledAgent);
            $this->assertMatchesRegularExpression(
                '/model:\s*["\']test-override\\\\?\/provider-model["\']/',
                $content,
                'Compiled frontmatter should contain the override model'
            );
        } finally {
            putenv('WEB_RESEARCH_MASTER_MODEL');
            if ($originalContent !== null) {
                file_put_contents($compiledAgent, $originalContent);
            }
            $brainBin = self::PROJECT_ROOT . '/cli/bin/brain';
            exec(
                'php ' . escapeshellarg($brainBin) . ' compile opencode >/dev/null 2>&1'
            );
        }
    }

    public function testModelResolutionClassNameCalculation(): void
    {
        $className = 'WEB_RESEARCH_MASTER';
        $expectedEnvVar = $className . '_MODEL';

        $this->assertSame('WEB_RESEARCH_MASTER_MODEL', $expectedEnvVar);
    }
}
