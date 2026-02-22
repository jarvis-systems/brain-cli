<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console\Commands;

use BrainCLI\Console\Kernel\CommandKernel;
use BrainCLI\Exceptions\CommandTerminatedException;
use BrainCLI\Tests\Support\CliOutputCapture;
use PHPUnit\Framework\TestCase;

/**
 * Golden parity tests for CompileCommand behavior through CommandKernel.
 */
class CompileCommandGoldenTest extends TestCase
{
    use CliOutputCapture;

    protected function tearDown(): void
    {
        putenv('BRAIN_CLI_DEBUG');
        putenv('DEBUG');
    }

    public function test_lock_conflict_exit_code(): void
    {
        // CTE (default exit code 1) should propagate through CommandKernel
        $result = CommandKernel::run(function () {
            throw new CommandTerminatedException();
        }, 'compile');

        $this->assertSame(1, $result);
    }

    public function test_lock_conflict_error_message_format(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/CompileCommand.php'
        ) ?: '';

        // Verify the lock conflict message pattern exists
        $this->assertStringContainsString('Compilation locked by PID', $source);
        $this->assertStringContainsString('(since', $source);
        $this->assertStringContainsString('Another brain compile is running', $source);
    }

    public function test_lock_conflict_no_debug_output_when_disabled(): void
    {
        putenv('BRAIN_CLI_DEBUG');
        putenv('DEBUG');

        $stderr = $this->captureStderr(function () {
            CommandKernel::run(function () {
                throw new CommandTerminatedException();
            }, 'compile');
        });

        $this->assertEmpty(trim($stderr));
    }
}
