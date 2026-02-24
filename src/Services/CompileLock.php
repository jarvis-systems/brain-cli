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
    public const TESTMODE_MARKER = '.brain-testmode.marker';

    private const ERROR_CODES = [
        'NOLOCK_FORBIDDEN' => 'no-lock forbidden',
        'MISSING_TEST_MODE' => 'missing_test_mode',
        'NON_ISOLATED_WORKDIR' => 'non_isolated_workdir',
        'LEAKY_TEST_MODE' => 'leaky_test_mode',
    ];

    private mixed $handle = null;

    public function __construct(
        private readonly string $lockDir,
    ) {}

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

    public function exists(): bool
    {
        return is_file($this->lockPath());
    }

    public static function isNoLockAllowed(?string $strictMode, mixed $allowOverride): bool
    {
        $restricted = in_array($strictMode, ['paranoid', 'strict'], true);

        if (! $restricted) {
            return true;
        }

        return in_array($allowOverride, [true, 1, '1', 'true'], true);
    }

    public static function isTestMode(): bool
    {
        $testMode = getenv('BRAIN_TEST_MODE');

        return in_array($testMode, [true, 1, '1', 'true'], true);
    }

    public static function isTestModeSourceCi(): bool
    {
        $source = getenv('BRAIN_TEST_MODE_SOURCE');

        return $source === 'ci';
    }

    public static function isPhpUnit(): bool
    {
        return defined('PHPUnit_RUNNING') || class_exists(\PHPUnit\Framework\TestCase::class, false);
    }

    public static function isUnderTempDir(string $workdir): bool
    {
        $tempDir = sys_get_temp_dir();
        $realWorkdir = realpath($workdir);
        $realTempDir = realpath($tempDir);

        return $realWorkdir !== false && $realTempDir !== false && str_starts_with($realWorkdir, $realTempDir);
    }

    public static function isUnderDistTmp(string $workdir): bool
    {
        $realWorkdir = realpath($workdir);
        if ($realWorkdir === false) {
            return false;
        }

        $distTmp = $realWorkdir . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'tmp';
        $realDistTmp = realpath($distTmp);

        return $realDistTmp !== false && str_starts_with($realWorkdir, $realDistTmp);
    }

    public static function hasTestModeMarker(string $workdir): bool
    {
        $markerFile = $workdir . DIRECTORY_SEPARATOR . self::TESTMODE_MARKER;

        return is_file($markerFile);
    }

    public static function isIsolatedWorkdir(string $workdir): bool
    {
        $underTemp = self::isUnderTempDir($workdir);
        $underDistTmp = self::isUnderDistTmp($workdir);
        $hasMarker = self::hasTestModeMarker($workdir);

        return ($underTemp || $underDistTmp) && $hasMarker;
    }

    /**
     * @return array{valid: bool, code: string|null, reason: string|null, hint: string|null}
     */
    public static function validateTestModeContract(string $workdir): array
    {
        $isPhpUnit = self::isPhpUnit();
        $isTestMode = self::isTestMode();
        $isSourceCi = self::isTestModeSourceCi();
        $isIsolated = self::isIsolatedWorkdir($workdir);

        if (! $isPhpUnit && ! $isTestMode) {
            return [
                'valid' => false,
                'code' => self::ERROR_CODES['NOLOCK_FORBIDDEN'],
                'reason' => self::ERROR_CODES['MISSING_TEST_MODE'],
                'hint' => 'Set BRAIN_TEST_MODE=1 and run under PHPUnit, or use CI with BRAIN_TEST_MODE_SOURCE=ci.',
            ];
        }

        if ($isTestMode && ! $isPhpUnit && ! $isSourceCi) {
            return [
                'valid' => false,
                'code' => self::ERROR_CODES['NOLOCK_FORBIDDEN'],
                'reason' => self::ERROR_CODES['LEAKY_TEST_MODE'],
                'hint' => 'BRAIN_TEST_MODE=1 requires either PHPUnit runtime or BRAIN_TEST_MODE_SOURCE=ci.',
            ];
        }

        if (! $isIsolated) {
            $underTemp = self::isUnderTempDir($workdir);
            $underDistTmp = self::isUnderDistTmp($workdir);
            $hasMarker = self::hasTestModeMarker($workdir);

            $reason = self::ERROR_CODES['NON_ISOLATED_WORKDIR'];
            $hintParts = [];

            if (! $underTemp && ! $underDistTmp) {
                $hintParts[] = 'workdir under system temp or dist/tmp';
            }
            if (! $hasMarker) {
                $hintParts[] = basename(self::TESTMODE_MARKER) . ' marker file';
            }

            return [
                'valid' => false,
                'code' => self::ERROR_CODES['NOLOCK_FORBIDDEN'],
                'reason' => $reason,
                'hint' => 'Requires: ' . implode(' AND ', $hintParts) . '.',
            ];
        }

        return [
            'valid' => true,
            'code' => null,
            'reason' => null,
            'hint' => null,
        ];
    }

    public static function getContractDiagnostics(string $workdir): array
    {
        $isPhpUnit = self::isPhpUnit();
        $isTestMode = self::isTestMode();
        $isSourceCi = self::isTestModeSourceCi();
        $underTemp = self::isUnderTempDir($workdir);
        $underDistTmp = self::isUnderDistTmp($workdir);
        $hasMarker = self::hasTestModeMarker($workdir);
        $isIsolated = $underTemp || $underDistTmp;
        $contract = self::validateTestModeContract($workdir);

        $reasons = [];
        if (! $isPhpUnit && ! $isTestMode) {
            $reasons[] = 'missing_test_mode';
        }
        if ($isTestMode && ! $isPhpUnit && ! $isSourceCi) {
            $reasons[] = 'leaky_test_mode';
        }
        if (! ($underTemp || $underDistTmp)) {
            $reasons[] = 'workdir_not_isolated';
        }
        if (! $hasMarker) {
            $reasons[] = 'missing_marker';
        }

        return [
            'test_mode_enabled' => $isTestMode,
            'test_mode_source_ci' => $isSourceCi,
            'phpunit_detected' => $isPhpUnit,
            'under_temp_dir' => $underTemp,
            'under_dist_tmp' => $underDistTmp,
            'has_marker' => $hasMarker,
            'isolated_workdir' => $isIsolated && $hasMarker,
            'nolock_allowed' => $contract['valid'],
            'reasons' => $reasons,
        ];
    }

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
