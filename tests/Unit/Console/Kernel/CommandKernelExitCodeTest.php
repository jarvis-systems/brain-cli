<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console\Kernel;

use BrainCLI\Console\Kernel\CommandKernel;
use BrainCLI\Exceptions\CommandTerminatedException;
use PHPUnit\Framework\TestCase;

class CommandKernelExitCodeTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('BRAIN_CLI_DEBUG');
        putenv('DEBUG');
    }

    public function test_success_returns_callable_result(): void
    {
        $result = CommandKernel::run(fn () => 0, 'test');

        $this->assertSame(0, $result);
    }

    public function test_success_casts_to_int(): void
    {
        /** @phpstan-ignore argument.type */
        $result = CommandKernel::run(fn () => '42', 'test');

        $this->assertSame(42, $result);
    }

    public function test_cte_returns_exit_code(): void
    {
        $result = CommandKernel::run(function () {
            throw new CommandTerminatedException(42);
        }, 'test');

        $this->assertSame(42, $result);
    }

    public function test_cte_default_exit_code(): void
    {
        $result = CommandKernel::run(function () {
            throw new CommandTerminatedException();
        }, 'test');

        $this->assertSame(1, $result);
    }

    public function test_throwable_returns_one(): void
    {
        $result = CommandKernel::run(function () {
            throw new \RuntimeException('boom');
        }, 'test');

        $this->assertSame(1, $result);
    }

    public function test_no_output_when_debug_disabled(): void
    {
        putenv('BRAIN_CLI_DEBUG');
        putenv('DEBUG');

        $stderrFile = tempnam(sys_get_temp_dir(), 'kernel-test-');
        $oldHandler = ini_set('error_log', $stderrFile);

        try {
            CommandKernel::run(function () {
                throw new \RuntimeException('silent');
            }, 'test');

            $output = file_get_contents($stderrFile) ?: '';
            $this->assertEmpty(trim($output));
        } finally {
            ini_set('error_log', $oldHandler ?: '');
            @unlink($stderrFile);
        }
    }

    public function test_debug_output_when_enabled(): void
    {
        putenv('BRAIN_CLI_DEBUG=1');

        $stderrFile = tempnam(sys_get_temp_dir(), 'kernel-test-');
        $oldHandler = ini_set('error_log', $stderrFile);

        try {
            CommandKernel::run(function () {
                throw new \RuntimeException('debug-visible');
            }, 'myctx');

            $output = file_get_contents($stderrFile) ?: '';
            $this->assertStringContainsString('[brain-debug:myctx]', $output);
            $this->assertStringContainsString('RuntimeException', $output);
            $this->assertStringContainsString('debug-visible', $output);
        } finally {
            ini_set('error_log', $oldHandler ?: '');
            @unlink($stderrFile);
        }
    }

    public function test_on_error_callback_invoked_on_throwable(): void
    {
        $captured = null;

        CommandKernel::run(
            function () { throw new \RuntimeException('captured'); },
            'test',
            function (\Throwable $e) use (&$captured) { $captured = $e; },
        );

        $this->assertInstanceOf(\RuntimeException::class, $captured);
        $this->assertSame('captured', $captured->getMessage());
    }

    public function test_on_error_not_called_on_cte(): void
    {
        $called = false;

        CommandKernel::run(
            function () { throw new CommandTerminatedException(5); },
            'test',
            function () use (&$called) { $called = true; },
        );

        $this->assertFalse($called);
    }
}
