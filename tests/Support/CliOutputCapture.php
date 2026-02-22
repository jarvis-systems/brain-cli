<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Support;

/**
 * Utility trait for capturing command output in golden tests.
 */
trait CliOutputCapture
{
    /**
     * Capture stdout from a callable.
     */
    protected function captureStdout(callable $fn): string
    {
        ob_start();
        $fn();
        return ob_get_clean() ?: '';
    }

    /**
     * Capture error_log output from a callable.
     */
    protected function captureStderr(callable $fn): string
    {
        $stderrFile = tempnam(sys_get_temp_dir(), 'golden-test-');
        $oldHandler = ini_set('error_log', $stderrFile);

        try {
            $fn();
            return file_get_contents($stderrFile) ?: '';
        } finally {
            ini_set('error_log', $oldHandler ?: '');
            @unlink($stderrFile);
        }
    }

    /**
     * Normalize dynamic values for stable snapshots.
     */
    protected function normalize(string $output): string
    {
        // Absolute paths → <PATH>
        $output = preg_replace('#/[\w/._-]+/(jarvis-brain-node|\.brain)[^\s"\']*#', '<PATH>', $output);

        // PID numbers → <PID>
        $output = preg_replace('/PID \d+/', 'PID <PID>', $output);

        // Timestamps (since YYYY-MM-DD HH:MM:SS) → <TIMESTAMP>
        $output = preg_replace('/since \d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}[^\s)*]*/', 'since <TIMESTAMP>', $output);

        // Durations → <DURATION>
        $output = preg_replace('/\d+\.\d+s/', '<DURATION>', $output);

        return $output;
    }

    /**
     * Create a temporary directory for test isolation.
     */
    protected function createTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/golden-test-' . uniqid();
        mkdir($dir, 0755, true);
        return $dir;
    }

    /**
     * Recursively remove a directory.
     */
    protected function cleanDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
