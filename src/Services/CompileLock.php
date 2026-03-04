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
        'MARKER_IN_PROJECT_ROOT' => 'marker_in_project_root',
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

        $parent = $realWorkdir;
        while (true) {
            $distTmp = $parent . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'tmp';
            if (is_dir($distTmp) && realpath($distTmp) !== false) {
                $realDistTmp = realpath($distTmp);
                if ($realWorkdir === $realDistTmp || str_starts_with($realWorkdir, $realDistTmp . DIRECTORY_SEPARATOR)) {
                    return true;
                }
            }

            $nextParent = dirname($parent);
            if ($nextParent === $parent) {
                break;
            }
            $parent = $nextParent;
        }

        return false;
    }

    public static function hasTestModeMarker(string $workdir): bool
    {
        $markerFile = $workdir . DIRECTORY_SEPARATOR . self::TESTMODE_MARKER;

        return is_file($markerFile);
    }

    public static function isBrainProjectRoot(string $workdir): bool
    {
        $realWorkdir = realpath($workdir);
        if ($realWorkdir === false) {
            return false;
        }

        $projectRoot = self::findProjectRoot($workdir);

        return $projectRoot !== null && $realWorkdir === $projectRoot;
    }

    public static function isIsolatedWorkdir(string $workdir): bool
    {
        $underTemp = self::isUnderTempDir($workdir);
        $underDistTmp = self::isUnderDistTmp($workdir);
        $hasMarker = self::hasTestModeMarker($workdir);
        $isProjectRoot = self::isBrainProjectRoot($workdir);

        if ($isProjectRoot) {
            return false;
        }

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
        $isProjectRoot = self::isBrainProjectRoot($workdir);
        $hasMarker = self::hasTestModeMarker($workdir);

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

        if ($isPhpUnit) {
            return [
                'valid' => true,
                'code' => null,
                'reason' => null,
                'hint' => null,
            ];
        }

        if ($isProjectRoot && $hasMarker) {
            return [
                'valid' => false,
                'code' => self::ERROR_CODES['NOLOCK_FORBIDDEN'],
                'reason' => self::ERROR_CODES['MARKER_IN_PROJECT_ROOT'],
                'hint' => 'Marker file in project root is a misconfiguration. Remove ' . basename(self::TESTMODE_MARKER) . ' or run from isolated temp directory.',
            ];
        }

        if (! $isIsolated) {
            $underTemp = self::isUnderTempDir($workdir);
            $underDistTmp = self::isUnderDistTmp($workdir);

            $reason = self::ERROR_CODES['NON_ISOLATED_WORKDIR'];
            $hintParts = [];

            if (! $hasMarker) {
                $hintParts[] = basename(self::TESTMODE_MARKER) . ' marker file';
            }
            if (! $underTemp && ! $underDistTmp) {
                $hintParts[] = 'workdir under system temp or dist/tmp';
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
        $isProjectRoot = self::isBrainProjectRoot($workdir);
        $isIsolated = self::isIsolatedWorkdir($workdir);
        $contract = self::validateTestModeContract($workdir);
        $projectRoot = self::findProjectRoot($workdir);

        $reasons = [];
        if (! $isPhpUnit && ! $isTestMode) {
            $reasons[] = 'missing_test_mode';
        }
        if ($isTestMode && ! $isPhpUnit && ! $isSourceCi) {
            $reasons[] = 'leaky_test_mode';
        }
        if (! $isPhpUnit && $isProjectRoot && $hasMarker) {
            $reasons[] = 'marker_in_project_root';
        }
        if (! $isPhpUnit && ! $isIsolated && ! ($isProjectRoot && $hasMarker)) {
            if (! $hasMarker) {
                $reasons[] = 'missing_marker';
            }
            if (! $underTemp && ! $underDistTmp) {
                $reasons[] = 'workdir_not_isolated';
            }
        }

        return [
            'original_cwd' => $workdir,
            'project_root' => $projectRoot,
            'cwd_matches_project_root' => $projectRoot !== null && realpath($workdir) === $projectRoot,
            'test_mode_enabled' => $isTestMode,
            'test_mode_source_ci' => $isSourceCi,
            'phpunit_detected' => $isPhpUnit,
            'under_temp_dir' => $underTemp,
            'under_dist_tmp' => $underDistTmp,
            'is_project_root' => $isProjectRoot,
            'has_marker' => $hasMarker,
            'isolated_workdir' => $isIsolated,
            'nolock_allowed' => $contract['valid'],
            'reasons' => $reasons,
        ];
    }

    public static function getCwdDiagnostics(string $originalCwd, ?string $projectRoot = null): array
    {
        $brainDirName = '.brain';
        $resolvedProjectRoot = $projectRoot ?? self::findProjectRoot($originalCwd, $brainDirName);

        $realOriginalCwd = realpath($originalCwd);
        $realProjectRoot = $resolvedProjectRoot !== null ? realpath($resolvedProjectRoot) : false;

        return [
            'original_cwd' => $originalCwd,
            'project_root' => $resolvedProjectRoot,
            'cwd_matches_project_root' => $realOriginalCwd !== false && $realProjectRoot !== false && $realOriginalCwd === $realProjectRoot,
            'chdir_performed' => false,
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
