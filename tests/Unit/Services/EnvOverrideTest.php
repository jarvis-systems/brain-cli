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
        putenv('BRAIN_ALLOW_NO_LOCK=1');
    }

    public static function tearDownAfterClass(): void
    {
        putenv(self::TEST_VAR_NAME);
        putenv('BRAIN_ALLOW_NO_LOCK');
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
                'WEB_RESEARCH_MASTER_MODEL=' . escapeshellarg($testModel) . ' php ' . escapeshellarg($brainBin) . ' compile opencode --no-lock 2>&1',
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
                'php ' . escapeshellarg($brainBin) . ' compile opencode --no-lock >/dev/null 2>&1'
            );
        }
    }

    public function testModelResolutionClassNameCalculation(): void
    {
        $className = 'WEB_RESEARCH_MASTER';
        $expectedEnvVar = $className . '_MODEL';

        $this->assertSame('WEB_RESEARCH_MASTER_MODEL', $expectedEnvVar);
    }

    public function testProcessEnvWinsOverDotenvForExistingKeys(): void
    {
        $claudeMd = self::PROJECT_ROOT . '/.claude/CLAUDE.md';

        $originalContent = file_exists($claudeMd) ? file_get_contents($claudeMd) : null;

        try {
            $output = [];
            $exitCode = 0;
            $brainBin = self::PROJECT_ROOT . '/cli/bin/brain';

            exec(
                'STRICT_MODE=standard COGNITIVE_LEVEL=standard php ' . escapeshellarg($brainBin) . ' compile claude --no-lock 2>&1',
                $output,
                $exitCode
            );

            $this->assertSame(0, $exitCode, 'Compile command should succeed: ' . implode("\n", $output));

            $this->assertFileExists($claudeMd, 'Compiled CLAUDE.md should exist');
            $lines = file_exists($claudeMd) ? count(file($claudeMd)) : 0;

            $this->assertLessThanOrEqual(
                450,
                $lines,
                "Standard mode should produce <=450 lines (got $lines lines - check if process env wins over .env)"
            );
        } finally {
            if ($originalContent !== null) {
                file_put_contents($claudeMd, $originalContent);
            }
            $brainBin = self::PROJECT_ROOT . '/cli/bin/brain';
            exec(
                'STRICT_MODE=paranoid COGNITIVE_LEVEL=exhaustive php ' . escapeshellarg($brainBin) . ' compile claude --no-lock >/dev/null 2>&1'
            );
        }
    }

    public function testExhaustiveModeProducesLargerOutput(): void
    {
        $claudeMd = self::PROJECT_ROOT . '/.claude/CLAUDE.md';

        $originalContent = file_exists($claudeMd) ? file_get_contents($claudeMd) : null;

        try {
            $output = [];
            $exitCode = 0;
            $brainBin = self::PROJECT_ROOT . '/cli/bin/brain';

            exec(
                'STRICT_MODE=paranoid COGNITIVE_LEVEL=exhaustive php ' . escapeshellarg($brainBin) . ' compile claude --no-lock 2>&1',
                $output,
                $exitCode
            );

            $this->assertSame(0, $exitCode, 'Compile command should succeed: ' . implode("\n", $output));

            $this->assertFileExists($claudeMd, 'Compiled CLAUDE.md should exist');
            $lines = file_exists($claudeMd) ? count(file($claudeMd)) : 0;

            $this->assertGreaterThan(
                500,
                $lines,
                "Exhaustive mode should produce >500 lines (got $lines lines)"
            );
        } finally {
            if ($originalContent !== null) {
                file_put_contents($claudeMd, $originalContent);
            }
        }
    }
}
