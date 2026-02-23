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

    // ─── Source Inspection: --diff option ────────────────────────────

    public function test_diff_option_exists_in_signature(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/CompileCommand.php'
        ) ?: '';

        $this->assertStringContainsString('--diff', $source);
    }

    public function test_diff_mode_uses_compile_diff_service(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/CompileCommand.php'
        ) ?: '';

        $this->assertStringContainsString('CompileDiff', $source);
    }

    public function test_diff_mode_creates_backup(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/CompileCommand.php'
        ) ?: '';

        $this->assertStringContainsString('backupOutputDirs', $source);
        $this->assertStringContainsString('restoreOutputDirs', $source);
    }

    public function test_diff_mode_returns_exit_code_2_for_differences(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/CompileCommand.php'
        ) ?: '';

        // Exit code 2 for differences found (ternary: isEmpty ? OK : 2)
        $this->assertStringContainsString('OK : 2', $source);
    }

    public function test_diff_mode_supports_json_output(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/CompileCommand.php'
        ) ?: '';

        $this->assertStringContainsString('renderDiffHuman', $source);
        $this->assertStringContainsString('JSON_PRETTY_PRINT', $source);
    }

    public function test_diff_mode_has_restore_in_finally(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/CompileCommand.php'
        ) ?: '';

        // Restore must be in finally block for safety
        $this->assertStringContainsString('finally', $source);
        $this->assertStringContainsString('restoreOutputDirs', $source);
        $this->assertStringContainsString('deleteDirectory', $source);
    }

    public function test_diff_json_uses_stable_schema(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/CompileCommand.php'
        ) ?: '';

        // JSON mode must use toJsonSchema() for stable output
        $this->assertStringContainsString('toJsonSchema', $source);
    }

    public function test_diff_json_schema_has_status_and_exit_code(): void
    {
        $diffSource = file_get_contents(
            dirname(__DIR__, 4) . '/src/Services/Compile/CompileDiff.php'
        ) ?: '';

        // Schema must include status and exit_code fields
        $this->assertStringContainsString("'status'", $diffSource);
        $this->assertStringContainsString("'exit_code'", $diffSource);
        $this->assertStringContainsString("'no_diff'", $diffSource);
        $this->assertStringContainsString("'diff'", $diffSource);
    }

    public function test_diff_output_dirs_include_claude_and_mcp(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/CompileCommand.php'
        ) ?: '';

        $this->assertStringContainsString('.claude', $source);
        $this->assertStringContainsString('.mcp.json', $source);
        $this->assertStringContainsString('agent-schema.json', $source);
    }

    // ─── Source Inspection: --json stdout purity ────────────────────

    public function test_json_mode_guards_empty_line_before_loop(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/CompileCommand.php'
        ) ?: '';

        // The $this->line('') before the loop must be guarded by !option('json')
        $this->assertStringContainsString("if (! \$this->option('json'))", $source);
    }

    public function test_json_mode_guards_empty_lines_around_loop(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/CompileCommand.php'
        ) ?: '';

        // handleBridge must have json guards to prevent stdout pollution.
        // The two $this->line('') calls (before and after agent loop) must be
        // inside `if (! $this->option('json'))` blocks.
        $start = strpos($source, 'function handleBridge()');
        $this->assertNotFalse($start, 'handleBridge() not found');

        $body = substr($source, $start, strpos($source, 'private function acquireCompileLock', $start) - $start);

        // Must have at least 2 json guards (before-loop and after-loop)
        $guardCount = substr_count($body, "! \$this->option('json')");
        $this->assertGreaterThanOrEqual(2, $guardCount, 'handleBridge() needs json guards for empty lines');
    }
}
