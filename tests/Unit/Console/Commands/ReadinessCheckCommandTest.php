<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console\Commands;

use PHPUnit\Framework\TestCase;

/**
 * Source inspection tests for ReadinessCheckCommand.
 *
 * Verifies command structure, options, and check coverage
 * without spawning processes or running actual checks.
 */
class ReadinessCheckCommandTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/ReadinessCheckCommand.php'
        ) ?: '';
    }

    public function test_command_signature_is_readiness_check(): void
    {
        $this->assertStringContainsString("'readiness:check", $this->source);
    }

    public function test_human_option_exists(): void
    {
        $this->assertStringContainsString('--human', $this->source);
    }

    public function test_skip_memory_option_exists(): void
    {
        $this->assertStringContainsString('--skip-memory', $this->source);
    }

    public function test_handle_uses_command_kernel(): void
    {
        $this->assertStringContainsString('CommandKernel::run(', $this->source);
    }

    public function test_json_output_uses_pretty_print(): void
    {
        $this->assertStringContainsString('JSON_PRETTY_PRINT', $this->source);
        $this->assertStringContainsString('JSON_UNESCAPED_UNICODE', $this->source);
        $this->assertStringContainsString('JSON_UNESCAPED_SLASHES', $this->source);
    }

    public function test_check_ids_present(): void
    {
        // Verify all 9 check IDs are referenced in the runner or command
        $runnerSource = file_get_contents(
            dirname(__DIR__, 4) . '/src/Services/Readiness/ReadinessRunner.php'
        ) ?: '';

        $combined = $this->source . $runnerSource;

        $checkIds = [
            'repo_health',
            'phpstan_core',
            'phpstan_cli',
            'phpunit_core',
            'phpunit_cli',
            'docs_validation',
            'composer_audit_core',
            'composer_audit_cli',
            'memory_hygiene',
        ];

        foreach ($checkIds as $id) {
            $this->assertStringContainsString("'{$id}'", $combined, "Check ID '{$id}' not found");
        }
    }

    public function test_overall_status_values(): void
    {
        $runnerSource = file_get_contents(
            dirname(__DIR__, 4) . '/src/Services/Readiness/ReadinessRunner.php'
        ) ?: '';

        // PASS, WARN, FAIL must all be in computeOverall
        $this->assertStringContainsString("'PASS'", $runnerSource);
        $this->assertStringContainsString("'WARN'", $runnerSource);
        $this->assertStringContainsString("'FAIL'", $runnerSource);
    }

    public function test_neutral_status_for_no_data(): void
    {
        $runnerSource = file_get_contents(
            dirname(__DIR__, 4) . '/src/Services/Readiness/ReadinessRunner.php'
        ) ?: '';

        $this->assertStringContainsString("'NEUTRAL'", $runnerSource);
    }
}
