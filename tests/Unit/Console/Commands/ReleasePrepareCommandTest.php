<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console\Commands;

use PHPUnit\Framework\TestCase;

/**
 * Source inspection tests for ReleasePrepareCommand.
 *
 * Verifies command structure, arguments, options, and kernel usage
 * without spawning processes or running actual release preparation.
 */
class ReleasePrepareCommandTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/ReleasePrepareCommand.php'
        ) ?: '';
    }

    public function test_command_signature_is_release_prepare(): void
    {
        $this->assertStringContainsString("'release:prepare", $this->source);
    }

    public function test_version_argument_exists(): void
    {
        $this->assertStringContainsString('{version?', $this->source);
    }

    public function test_evidence_option_exists(): void
    {
        $this->assertStringContainsString('--evidence', $this->source);
    }

    public function test_handle_uses_command_kernel(): void
    {
        $this->assertStringContainsString('CommandKernel::run(', $this->source);
    }

    public function test_apply_option_exists(): void
    {
        $this->assertStringContainsString('--apply', $this->source);
    }
}
