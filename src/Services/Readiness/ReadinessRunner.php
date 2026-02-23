<?php

declare(strict_types=1);

namespace BrainCLI\Services\Readiness;

use BrainCLI\Services\Memory\MemoryStatusCollector;

/**
 * Pre-release readiness check orchestrator.
 *
 * Runs all quality checks as shell subprocesses via proc_open(),
 * producing a structured result array with timing and status.
 */
class ReadinessRunner
{
    private const VERSION = '1.0.0';

    /**
     * MCP infrastructure error patterns → human-readable reasons.
     *
     * @var array<string, string>
     */
    private const MCP_INFRA_PATTERNS = [
        '.mcp.json not found' => 'MCP config not found (.mcp.json missing)',
        'not configured in .mcp.json' => 'vector-memory server not configured',
        'invalid command in .mcp.json' => 'vector-memory server has invalid command',
        'not found on PATH' => 'MCP server binary not found on PATH',
        'MCP error:' => 'MCP server connection failed',
        'Failed to start process' => 'Failed to start MCP server process',
    ];

    /**
     * Allowed untracked patterns for repo_health check.
     *
     * @var list<string>
     */
    private const ALLOWED_UNTRACKED = [
        '.compile-stamp',
        '.work/',
        '.phpunit.result.cache',
        '.phpstan/',
    ];

    public function __construct(
        private readonly string $projectRoot,
    ) {
    }

    /**
     * Run all readiness checks and return structured result.
     *
     * @return array<string, mixed>
     */
    public function run(bool $skipMemory = false): array
    {
        $startTime = hrtime(true);

        $checks = [];

        $checks['repo_health'] = $this->checkRepoHealth();
        $checks['phpstan_core'] = $this->checkPhpStan('core');
        $checks['phpstan_cli'] = $this->checkPhpStan('cli');
        $checks['phpunit_core'] = $this->checkPhpUnit('core');
        $checks['phpunit_cli'] = $this->checkPhpUnit('cli');
        $checks['docs_validation'] = $this->checkDocsValidation();
        $checks['composer_audit_core'] = $this->checkComposerAudit('core');
        $checks['composer_audit_cli'] = $this->checkComposerAudit('cli');

        if ($skipMemory) {
            $checks['memory_hygiene'] = [
                'status' => 'SKIP',
                'duration_ms' => 0,
                'details' => ['mode' => 'skipped', 'reason' => '--skip-memory'],
            ];
        } else {
            $checks['memory_hygiene'] = $this->checkMemoryHygiene();
        }

        $checks['memory_status'] = $this->checkMemoryStatus();

        $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        return [
            'version' => self::VERSION,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'duration_ms' => $durationMs,
            'overall' => $this->computeOverall($checks),
            'checks' => $checks,
        ];
    }

    /**
     * Check repository health: clean worktree, branch info.
     *
     * @return array{status: string, duration_ms: int, details: array<string, mixed>}
     */
    protected function checkRepoHealth(): array
    {
        $startTime = hrtime(true);

        [$exitCode, $stdout] = $this->exec('git status --porcelain', $this->projectRoot);
        [, $branchOutput] = $this->exec('git branch --show-current', $this->projectRoot);

        $branch = trim($branchOutput);
        $lines = array_filter(explode("\n", trim($stdout)), fn (string $line): bool => $line !== '');

        $untrackedIgnored = 0;
        $hasTrackedChanges = false;
        $hasUnallowedUntracked = false;

        foreach ($lines as $line) {
            $file = trim(substr($line, 3));
            $statusCode = substr($line, 0, 2);

            if ($statusCode === '??') {
                if ($this->isAllowedUntracked($file)) {
                    $untrackedIgnored++;
                } else {
                    $hasUnallowedUntracked = true;
                }
            } else {
                $hasTrackedChanges = true;
            }
        }

        if ($hasTrackedChanges) {
            $status = 'FAIL';
        } elseif ($hasUnallowedUntracked) {
            $status = 'WARN';
        } else {
            $status = 'PASS';
        }

        $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        return [
            'status' => $status,
            'duration_ms' => $durationMs,
            'details' => [
                'worktree_clean' => ! $hasTrackedChanges && ! $hasUnallowedUntracked,
                'branch' => $branch,
                'untracked_ignored' => $untrackedIgnored,
            ],
        ];
    }

    /**
     * Run PHPStan analysis for a package.
     *
     * @return array{status: string, duration_ms: int, details: array<string, mixed>}
     */
    protected function checkPhpStan(string $pkg): array
    {
        $cwd = $this->projectRoot . '/' . $pkg;

        [$exitCode, $stdout, $stderr, $durationMs] = $this->exec('composer analyse 2>&1', $cwd);

        $errors = 0;
        if (preg_match('/\[ERROR\]\s+Found\s+(\d+)\s+error/i', $stdout, $m)) {
            $errors = (int) $m[1];
        }

        return [
            'status' => $exitCode === 0 ? 'PASS' : 'FAIL',
            'duration_ms' => $durationMs,
            'details' => [
                'errors' => $errors,
            ],
        ];
    }

    /**
     * Run PHPUnit tests for a package.
     *
     * @return array{status: string, duration_ms: int, details: array<string, mixed>}
     */
    protected function checkPhpUnit(string $pkg): array
    {
        $cwd = $this->projectRoot . '/' . $pkg;

        [$exitCode, $stdout, $stderr, $durationMs] = $this->exec('composer test 2>&1', $cwd);

        $parsed = $this->parsePhpUnitOutput($stdout);

        $hasFail = $exitCode !== 0 || $parsed['failures'] > 0 || $parsed['errors'] > 0;

        return [
            'status' => $hasFail ? 'FAIL' : 'PASS',
            'duration_ms' => $durationMs,
            'details' => [
                'tests' => $parsed['tests'],
                'assertions' => $parsed['assertions'],
                'failures' => $parsed['failures'],
                'errors' => $parsed['errors'],
            ],
        ];
    }

    /**
     * Run docs validation via brain docs --validate.
     *
     * @return array{status: string, duration_ms: int, details: array<string, mixed>}
     */
    protected function checkDocsValidation(): array
    {
        [$exitCode, $stdout, $stderr, $durationMs] = $this->exec(
            'php cli/bin/brain docs --validate 2>&1',
            $this->projectRoot,
        );

        $parsed = $this->parseDocsOutput($stdout);

        if ($parsed['invalid'] > 0) {
            $status = 'FAIL';
        } elseif ($parsed['warnings'] > 0) {
            $status = 'WARN';
        } else {
            $status = 'PASS';
        }

        return [
            'status' => $status,
            'duration_ms' => $durationMs,
            'details' => [
                'total' => $parsed['total'],
                'valid' => $parsed['valid'],
                'invalid' => $parsed['invalid'],
                'warnings' => $parsed['warnings'],
            ],
        ];
    }

    /**
     * Run composer audit for a package.
     *
     * @return array{status: string, duration_ms: int, details: array<string, mixed>}
     */
    protected function checkComposerAudit(string $pkg): array
    {
        $cwd = $this->projectRoot . '/' . $pkg;

        [$exitCode, $stdout, $stderr, $durationMs] = $this->exec('composer audit --format=json 2>&1', $cwd);

        $advisories = 0;
        $json = json_decode($stdout, true);

        if (is_array($json) && isset($json['advisories']) && is_array($json['advisories'])) {
            foreach ($json['advisories'] as $packageAdvisories) {
                if (is_array($packageAdvisories)) {
                    $advisories += count($packageAdvisories);
                }
            }
        }

        return [
            'status' => $exitCode === 0 ? 'PASS' : 'FAIL',
            'duration_ms' => $durationMs,
            'details' => [
                'advisories' => $advisories,
            ],
        ];
    }

    /**
     * Run memory hygiene check.
     *
     * @return array{status: string, duration_ms: int, details: array<string, mixed>}
     */
    protected function checkMemoryHygiene(): array
    {
        [$exitCode, $stdout, $stderr, $durationMs] = $this->exec(
            'php cli/bin/brain memory:hygiene 2>&1',
            $this->projectRoot,
        );

        // Try to parse JSON output for threshold status
        $json = json_decode($stdout, true);

        if (! is_array($json)) {
            // NO_DATA: empty vector store
            if (str_contains($stdout, 'NO_DATA') || str_contains($stdout, 'no_data')) {
                return [
                    'status' => 'NEUTRAL',
                    'duration_ms' => $durationMs,
                    'details' => ['mode' => 'no_data', 'reason' => 'Empty vector store'],
                ];
            }

            // MCP infrastructure unavailable — WARN, not FAIL
            $infraReason = $this->classifyMcpInfraError($stdout);

            if ($exitCode !== 0 && $infraReason !== null) {
                return [
                    'status' => 'WARN',
                    'duration_ms' => $durationMs,
                    'details' => [
                        'mode' => 'infra_unavailable',
                        'error_kind' => 'infra',
                        'reason' => $infraReason,
                    ],
                ];
            }

            return [
                'status' => $exitCode === 0 ? 'PASS' : 'FAIL',
                'duration_ms' => $durationMs,
                'details' => ['mode' => 'raw', 'exit_code' => $exitCode],
            ];
        }

        $thresholdMet = $json['smoke']['threshold_met'] ?? null;

        if ($thresholdMet === null) {
            return [
                'status' => 'NEUTRAL',
                'duration_ms' => $durationMs,
                'details' => ['mode' => 'no_data', 'reason' => 'No threshold data available'],
            ];
        }

        return [
            'status' => $thresholdMet ? 'PASS' : 'FAIL',
            'duration_ms' => $durationMs,
            'details' => [
                'mode' => 'evaluated',
                'threshold_met' => $thresholdMet,
                'pass_rate' => $json['smoke']['pass_rate'] ?? 0,
            ],
        ];
    }

    /**
     * Collect memory status from cached artifacts (informational, non-blocking).
     *
     * @return array{status: string, duration_ms: int, details: array<string, mixed>}
     */
    protected function checkMemoryStatus(): array
    {
        $startTime = hrtime(true);

        $artifactDir = $this->projectRoot . '/.work/memory-hygiene';
        $collector = new MemoryStatusCollector($artifactDir);
        $data = $collector->collect();

        $memoryStatus = $data['status'] ?? 'no_data';
        $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        $details = [
            'memory_status' => $memoryStatus,
            'total_memories' => $data['counts']['total_memories'] ?? null,
            'smoke_pass_rate' => $data['smoke']['pass_rate'] ?? null,
            'critical_pass_rate' => $data['smoke']['critical_pass_rate'] ?? null,
            'last_run' => $data['last_run'] ?? null,
        ];

        // Informational only: NEUTRAL for stale/no_data, PASS for ok
        $checkStatus = match ($memoryStatus) {
            'ok' => 'NEUTRAL',
            'stale' => 'NEUTRAL',
            'no_data' => 'NEUTRAL',
            default => 'NEUTRAL',
        };

        return [
            'status' => $checkStatus,
            'duration_ms' => $durationMs,
            'details' => $details,
        ];
    }

    /**
     * Compute overall status from individual check results.
     *
     * @param  array<string, array{status: string, duration_ms: int, details: array<string, mixed>}>  $checks
     */
    protected function computeOverall(array $checks): string
    {
        $hasFail = false;
        $hasWarn = false;

        foreach ($checks as $check) {
            $status = $check['status'];

            if ($status === 'FAIL') {
                $hasFail = true;
            } elseif ($status === 'WARN') {
                $hasWarn = true;
            }
            // PASS, NEUTRAL, SKIP — no impact
        }

        if ($hasFail) {
            return 'FAIL';
        }

        if ($hasWarn) {
            return 'WARN';
        }

        return 'PASS';
    }

    /**
     * Parse PHPUnit output to extract test counts.
     *
     * @return array{tests: int, assertions: int, failures: int, errors: int}
     */
    protected function parsePhpUnitOutput(string $output): array
    {
        $tests = 0;
        $assertions = 0;
        $failures = 0;
        $errors = 0;

        // Match: "OK (273 tests, 645 assertions)" or "Tests: 273, Assertions: 645, Failures: 3"
        if (preg_match('/(\d+)\s+tests?,\s*(\d+)\s+assertions?/i', $output, $m)) {
            $tests = (int) $m[1];
            $assertions = (int) $m[2];
        }

        if (preg_match('/(\d+)\s+failures?/i', $output, $m)) {
            $failures = (int) $m[1];
        }

        if (preg_match('/(\d+)\s+errors?/i', $output, $m)) {
            $errors = (int) $m[1];
        }

        return [
            'tests' => $tests,
            'assertions' => $assertions,
            'failures' => $failures,
            'errors' => $errors,
        ];
    }

    /**
     * Parse docs validation output to extract summary.
     *
     * @return array{total: int, valid: int, invalid: int, warnings: int}
     */
    protected function parseDocsOutput(string $output): array
    {
        $total = 0;
        $valid = 0;
        $invalid = 0;
        $warnings = 0;

        // Try JSON parsing first
        $json = json_decode($output, true);

        if (is_array($json)) {
            return [
                'total' => (int) ($json['total'] ?? 0),
                'valid' => (int) ($json['valid'] ?? 0),
                'invalid' => (int) ($json['invalid'] ?? 0),
                'warnings' => (int) ($json['warnings'] ?? 0),
            ];
        }

        // Fallback: parse text output
        if (preg_match('/(\d+)\s+valid/i', $output, $m)) {
            $valid = (int) $m[1];
        }

        if (preg_match('/(\d+)\s+invalid/i', $output, $m)) {
            $invalid = (int) $m[1];
        }

        if (preg_match('/(\d+)\s+warnings?/i', $output, $m)) {
            $warnings = (int) $m[1];
        }

        if (preg_match('/(\d+)\s+(?:files?|documents?|total)/i', $output, $m)) {
            $total = (int) $m[1];
        } else {
            $total = $valid + $invalid;
        }

        return [
            'total' => $total,
            'valid' => $valid,
            'invalid' => $invalid,
            'warnings' => $warnings,
        ];
    }

    /**
     * Check if a file path matches the allowed untracked patterns.
     */
    private function isAllowedUntracked(string $file): bool
    {
        foreach (self::ALLOWED_UNTRACKED as $pattern) {
            if (str_ends_with($pattern, '/')) {
                if (str_starts_with($file, $pattern)) {
                    return true;
                }
            } elseif ($file === $pattern) {
                return true;
            }
        }

        return false;
    }

    /**
     * Classify MCP infrastructure errors from command output.
     *
     * Returns a human-readable reason if the output matches a known
     * MCP infra pattern, or null if it's not an infra error.
     */
    protected function classifyMcpInfraError(string $output): ?string
    {
        foreach (self::MCP_INFRA_PATTERNS as $pattern => $reason) {
            if (str_contains($output, $pattern)) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * Execute a shell command and return [exit_code, stdout, stderr, duration_ms].
     *
     * @return array{0: int, 1: string, 2: string, 3: int}
     */
    private function exec(string $command, ?string $cwd = null): array
    {
        $startTime = hrtime(true);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $cwd);

        if (! is_resource($process)) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            return [1, '', 'Failed to start process', $durationMs];
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        return [$exitCode, $stdout, $stderr, $durationMs];
    }
}
