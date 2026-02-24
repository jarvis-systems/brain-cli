<?php

declare(strict_types=1);

namespace BrainCLI\Services;

/**
 * File-based mutex for brain compile operations.
 *
 * Uses flock() for atomic lock acquisition with PID/timestamp
 * metadata for observability. Kernel automatically releases
 * the lock on process death — no stale lock cleanup needed.
 */
class CompileLock
{
    private const LOCK_FILE = 'compile.lock';

    /** @var resource|null */
    private mixed $handle = null;

    public function __construct(
        private readonly string $lockDir,
    ) {}

    /**
     * Acquire exclusive compile lock (non-blocking).
     *
     * Returns true if lock acquired, false if another process holds it.
     */
    public function acquire(): bool
    {
        $lockPath = $this->lockPath();

        if (! is_dir($this->lockDir)) {
            mkdir($this->lockDir, 0755, true);
        }

        $handle = fopen($lockPath, 'c+');

        if ($handle === false) {
            return false;
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        $this->handle = $handle;

        // Write lock metadata for observability
        ftruncate($this->handle, 0);
        rewind($this->handle);
        fwrite($this->handle, json_encode([
            'pid' => getmypid(),
            'started_at' => date('c'),
            'timestamp' => time(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
        fflush($this->handle);

        return true;
    }

    /**
     * Release the compile lock.
     */
    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;

            @unlink($this->lockPath());
        }
    }

    /**
     * Read lock holder metadata (PID, timestamp).
     *
     * @return array{pid: int, started_at: string, timestamp: int}|null
     */
    public function getHolderInfo(): ?array
    {
        $lockPath = $this->lockPath();

        if (! is_file($lockPath)) {
            return null;
        }

        $content = file_get_contents($lockPath);

        if ($content === false || $content === '') {
            return null;
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Check if lock file exists (does not verify if actively held).
     */
    public function exists(): bool
    {
        return is_file($this->lockPath());
    }

    /**
     * Check whether --no-lock flag is allowed under current strict mode.
     *
     * Under "paranoid" or "strict" modes, --no-lock is blocked
     * unless BRAIN_ALLOW_NO_LOCK override is explicitly set.
     *
     * @param  string|null  $strictMode       Current STRICT_MODE value
     * @param  mixed        $allowOverride    BRAIN_ALLOW_NO_LOCK env value
     */
    public static function isNoLockAllowed(?string $strictMode, mixed $allowOverride): bool
    {
        $restricted = in_array($strictMode, ['paranoid', 'strict'], true);

        if (! $restricted) {
            return true;
        }

        return in_array($allowOverride, [true, 1, '1', 'true'], true);
    }

    /**
     * Check if test mode is active.
     *
     * Test mode is required for --no-lock to prevent accidental use in production.
     */
    public static function isTestMode(): bool
    {
        $testMode = getenv('BRAIN_TEST_MODE');

        return in_array($testMode, [true, 1, '1', 'true'], true);
    }

    /**
     * Check if running under PHPUnit.
     */
    public static function isPhpUnit(): bool
    {
        return defined('PHPUnit_RUNNING') || class_exists(\PHPUnit\Framework\TestCase::class, false);
    }

    /**
     * Check if working directory is an isolated test directory.
     *
     * An isolated directory is either:
     * - Under system temp directory
     * - Contains .brain/test-workdir marker file
     */
    public static function isIsolatedWorkdir(string $workdir): bool
    {
        $tempDir = sys_get_temp_dir();
        $realWorkdir = realpath($workdir);
        $realTempDir = realpath($tempDir);

        if ($realWorkdir !== false && $realTempDir !== false && str_starts_with($realWorkdir, $realTempDir)) {
            return true;
        }

        $markerFile = $workdir . DIRECTORY_SEPARATOR . '.brain' . DIRECTORY_SEPARATOR . 'test-workdir';

        return is_file($markerFile);
    }

    /**
     * Validate test mode contract for --no-lock usage.
     *
     * @return array{valid: bool, error: string|null}
     */
    public static function validateTestModeContract(string $workdir): array
    {
        if (! self::isTestMode() && ! self::isPhpUnit()) {
            return [
                'valid' => false,
                'error' => 'BRAIN_TEST_MODE=1 is required for --no-lock. This flag is restricted to isolated test environments only.',
            ];
        }

        if (! self::isIsolatedWorkdir($workdir)) {
            return [
                'valid' => false,
                'error' => 'Working directory must be under system temp or contain .brain/test-workdir marker. Isolated workdir required for --no-lock.',
            ];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Walk up from $startDir looking for a Brain project root.
     *
     * A project root is identified by the presence of
     * {brainDirName}/node/Brain.php in the directory.
     */
    public static function findProjectRoot(string $startDir, string $brainDirName = '.brain'): ?string
    {
        $dir = realpath($startDir);

        if ($dir === false) {
            return null;
        }

        while (true) {
            $candidate = $dir . DIRECTORY_SEPARATOR
                . $brainDirName . DIRECTORY_SEPARATOR
                . 'node' . DIRECTORY_SEPARATOR
                . 'Brain.php';

            if (is_file($candidate)) {
                return $dir;
            }

            $parent = dirname($dir);

            if ($parent === $dir) {
                break;
            }

            $dir = $parent;
        }

        return null;
    }

    private function lockPath(): string
    {
        return $this->lockDir . DIRECTORY_SEPARATOR . self::LOCK_FILE;
    }
}
