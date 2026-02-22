<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Services;

use BrainCLI\Core;
use PHPUnit\Framework\TestCase;

class DebugExceptionMethodTest extends TestCase
{
    private Core $core;

    protected function setUp(): void
    {
        $this->core = new Core;
    }

    protected function tearDown(): void
    {
        putenv('BRAIN_CLI_DEBUG');
        putenv('DEBUG');
    }

    public function test_debug_exception_emits_nothing_when_debug_disabled(): void
    {
        putenv('BRAIN_CLI_DEBUG');
        putenv('DEBUG');

        $stderrFile = tempnam(sys_get_temp_dir(), 'debug-test-');
        $oldHandler = ini_set('error_log', $stderrFile);

        try {
            $this->core->debugException(new \RuntimeException('should not appear'));
            $output = file_get_contents($stderrFile) ?: '';
            $this->assertEmpty(trim($output));
        } finally {
            ini_set('error_log', $oldHandler ?: '');
            @unlink($stderrFile);
        }
    }

    public function test_debug_exception_emits_class_and_message_when_debug_enabled(): void
    {
        putenv('BRAIN_CLI_DEBUG=1');

        $stderrFile = tempnam(sys_get_temp_dir(), 'debug-test-');
        $oldHandler = ini_set('error_log', $stderrFile);

        try {
            $this->core->debugException(new \InvalidArgumentException('bad input'));
            $output = file_get_contents($stderrFile) ?: '';
            $this->assertStringContainsString('[brain-debug]', $output);
            $this->assertStringContainsString('InvalidArgumentException', $output);
            $this->assertStringContainsString('bad input', $output);
        } finally {
            ini_set('error_log', $oldHandler ?: '');
            @unlink($stderrFile);
        }
    }

    public function test_debug_exception_uses_custom_prefix(): void
    {
        putenv('BRAIN_CLI_DEBUG=1');

        $stderrFile = tempnam(sys_get_temp_dir(), 'debug-test-');
        $oldHandler = ini_set('error_log', $stderrFile);

        try {
            $this->core->debugException(new \LogicException('test'), 'custom-prefix');
            $output = file_get_contents($stderrFile) ?: '';
            $this->assertStringContainsString('[custom-prefix]', $output);
            $this->assertStringContainsString('LogicException', $output);
        } finally {
            ini_set('error_log', $oldHandler ?: '');
            @unlink($stderrFile);
        }
    }

    public function test_debug_exception_handles_nested_exception(): void
    {
        putenv('BRAIN_CLI_DEBUG=1');

        $stderrFile = tempnam(sys_get_temp_dir(), 'debug-test-');
        $oldHandler = ini_set('error_log', $stderrFile);

        try {
            $inner = new \RuntimeException('inner cause');
            $outer = new \RuntimeException('outer error', 0, $inner);
            $this->core->debugException($outer);
            $output = file_get_contents($stderrFile) ?: '';
            $this->assertStringContainsString('outer error', $output);
            $this->assertStringNotContainsString('inner cause', $output);
        } finally {
            ini_set('error_log', $oldHandler ?: '');
            @unlink($stderrFile);
        }
    }
}
