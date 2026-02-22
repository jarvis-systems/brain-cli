<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;

/**
 * Guards against new exit() calls being introduced in core command files.
 * Allowed exceptions: signal handlers and AI command lifecycle.
 */
class NoProcessTerminationCallsTest extends TestCase
{
    /**
     * Files explicitly allowed to use exit().
     * Each entry: relative path from cli/src/ => reason.
     */
    private const ALLOWLIST = [
        'Services/ProcessFactory.php' => 'POSIX signal handlers (exit(128 + $signal))',
        'Console/AiCommands/RunCommand.php' => 'AI command lifecycle',
        'Console/AiCommands/CustomRunCommand.php' => 'AI command lifecycle',
        'Console/AiCommands/Lab/Prompts/CommandLinePrompt.php' => 'Lab/Prompts component',
        'Exceptions/CommandTerminatedException.php' => 'CTE definition (exit() mentioned in docblock/param name only)',
    ];

    public function test_no_exit_calls_in_core_commands(): void
    {
        $srcDir = dirname(__DIR__, 3) . '/src';
        $violations = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = str_replace($srcDir . '/', '', $file->getPathname());

            if (isset(self::ALLOWLIST[$relativePath])) {
                continue;
            }

            $content = file_get_contents($file->getPathname()) ?: '';

            if (preg_match('/(?<!\$|\w)exit\s*\(/', $content)) {
                $violations[] = $relativePath;
            }
        }

        $this->assertEmpty(
            $violations,
            "Unexpected exit() calls found in:\n- " . implode("\n- ", $violations)
            . "\n\nUse throw new CommandTerminatedException() instead.",
        );
    }

    public function test_allowlisted_files_still_exist(): void
    {
        $srcDir = dirname(__DIR__, 3) . '/src';

        foreach (array_keys(self::ALLOWLIST) as $relativePath) {
            $this->assertFileExists(
                $srcDir . '/' . $relativePath,
                "Allowlisted file no longer exists: {$relativePath}. Remove from allowlist.",
            );
        }
    }
}
