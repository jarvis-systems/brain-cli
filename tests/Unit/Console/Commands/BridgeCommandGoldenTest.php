<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console\Commands;

use BrainCLI\Console\Kernel\CommandKernel;
use BrainCLI\Exceptions\CommandTerminatedException;
use BrainCLI\Tests\Support\CliOutputCapture;
use PHPUnit\Framework\TestCase;

/**
 * Golden parity tests for CommandBridgeAbstract behavior via CommandKernel.
 */
class BridgeCommandGoldenTest extends TestCase
{
    use CliOutputCapture;

    protected function tearDown(): void
    {
        putenv('BRAIN_CLI_DEBUG');
        putenv('DEBUG');
    }

    public function test_bridge_int_result_passes_through(): void
    {
        $result = CommandKernel::run(fn () => 0, 'bridge');

        $this->assertSame(0, $result);
    }

    public function test_bridge_throwable_returns_one_with_error(): void
    {
        $errorCalled = false;

        $result = CommandKernel::run(
            function () { throw new \RuntimeException('test error'); },
            'bridge',
            function (\Throwable $e) use (&$errorCalled) {
                $errorCalled = true;
                $this->assertSame('test error', $e->getMessage());
            },
        );

        $this->assertSame(1, $result);
        $this->assertTrue($errorCalled);
    }

    public function test_bridge_cte_returns_exit_code_no_error(): void
    {
        $errorCalled = false;

        $result = CommandKernel::run(
            function () { throw new CommandTerminatedException(42); },
            'bridge',
            function () use (&$errorCalled) { $errorCalled = true; },
        );

        $this->assertSame(42, $result);
        $this->assertFalse($errorCalled);
    }

    public function test_bridge_source_uses_command_kernel(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Abstracts/CommandBridgeAbstract.php'
        ) ?: '';

        $this->assertStringContainsString('CommandKernel::run(', $source);
        $this->assertStringContainsString("'bridge'", $source);
    }
}
