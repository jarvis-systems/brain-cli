<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Support;

use BrainCLI\Tests\Support\CliOutputCapture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CliOutputCapture trait utility methods.
 *
 * Covers normalize(), captureStdout(), createTempDir(), cleanDirectory().
 */
class CliOutputCaptureTest extends TestCase
{
    use CliOutputCapture;

    // ─── normalize(): absolute paths ────────────────────────────────

    #[Test]
    public function normalize_replaces_absolute_paths_with_placeholder(): void
    {
        $input = 'Error in /Users/dev/projects/jarvis-brain-node/src/Core.php line 42';
        $result = $this->normalize($input);

        $this->assertStringContainsString('<PATH>', $result);
        $this->assertStringNotContainsString('/Users/dev/projects/jarvis-brain-node', $result);
    }

    #[Test]
    public function normalize_replaces_brain_dir_paths(): void
    {
        $input = 'Loaded config from /home/ci/.brain/node/Brain.php';
        $result = $this->normalize($input);

        $this->assertStringContainsString('<PATH>', $result);
        $this->assertStringNotContainsString('/home/ci/.brain', $result);
    }

    // ─── normalize(): PID numbers ───────────────────────────────────

    #[Test]
    public function normalize_replaces_pid_numbers(): void
    {
        $input = 'Compilation locked by PID 54321 (since 2025-06-01T12:00:00)';
        $result = $this->normalize($input);

        $this->assertStringContainsString('PID <PID>', $result);
        $this->assertStringNotContainsString('PID 54321', $result);
    }

    // ─── normalize(): timestamps ────────────────────────────────────

    #[Test]
    public function normalize_replaces_since_timestamps(): void
    {
        $input = 'Lock active since 2025-06-01T12:34:56+00:00';
        $result = $this->normalize($input);

        $this->assertStringContainsString('since <TIMESTAMP>', $result);
        $this->assertStringNotContainsString('2025-06-01', $result);
    }

    #[Test]
    public function normalize_replaces_space_separated_timestamps(): void
    {
        $input = 'Started since 2025-01-15 09:30:00';
        $result = $this->normalize($input);

        $this->assertStringContainsString('since <TIMESTAMP>', $result);
    }

    // ─── normalize(): durations ─────────────────────────────────────

    #[Test]
    public function normalize_replaces_duration_values(): void
    {
        $input = 'Compiled in 0.342s';
        $result = $this->normalize($input);

        $this->assertStringContainsString('<DURATION>', $result);
        $this->assertStringNotContainsString('0.342s', $result);
    }

    // ─── normalize(): combined ──────────────────────────────────────

    #[Test]
    public function normalize_handles_multiple_dynamic_values_at_once(): void
    {
        $input = 'PID 99999 locked /tmp/jarvis-brain-node/compile.lock since 2025-12-31T23:59:59 completed in 1.23s';
        $result = $this->normalize($input);

        $this->assertStringContainsString('PID <PID>', $result);
        $this->assertStringContainsString('<PATH>', $result);
        $this->assertStringContainsString('since <TIMESTAMP>', $result);
        $this->assertStringContainsString('<DURATION>', $result);
    }

    #[Test]
    public function normalize_preserves_static_text(): void
    {
        $input = 'Brain compilation succeeded. No warnings.';
        $result = $this->normalize($input);

        $this->assertSame('Brain compilation succeeded. No warnings.', $result);
    }

    // ─── captureStdout() ────────────────────────────────────────────

    #[Test]
    public function capture_stdout_returns_printed_output(): void
    {
        $output = $this->captureStdout(function () {
            echo 'test output';
        });

        $this->assertSame('test output', $output);
    }

    #[Test]
    public function capture_stdout_returns_empty_string_on_no_output(): void
    {
        $output = $this->captureStdout(function () {
            // no output
        });

        $this->assertSame('', $output);
    }

    // ─── createTempDir() + cleanDirectory() ─────────────────────────

    #[Test]
    public function create_temp_dir_creates_existing_directory(): void
    {
        $dir = $this->createTempDir();

        try {
            $this->assertDirectoryExists($dir);
        } finally {
            $this->cleanDirectory($dir);
        }
    }

    #[Test]
    public function clean_directory_removes_nested_structure(): void
    {
        $dir = $this->createTempDir();
        mkdir($dir . '/sub/deep', 0755, true);
        file_put_contents($dir . '/root.txt', 'root');
        file_put_contents($dir . '/sub/child.txt', 'child');
        file_put_contents($dir . '/sub/deep/leaf.txt', 'leaf');

        $this->cleanDirectory($dir);

        $this->assertDirectoryDoesNotExist($dir);
    }

    #[Test]
    public function clean_directory_is_safe_on_nonexistent_path(): void
    {
        // Should not throw on a path that does not exist
        $this->cleanDirectory('/tmp/nonexistent-' . uniqid());

        $this->assertTrue(true); // No exception = pass
    }
}
