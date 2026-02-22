<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Console\Kernel\CommandKernel;
use BrainCLI\Console\Traits\HelpersTrait;
use BrainCLI\Services\Readiness\ReadinessRunner;
use Illuminate\Console\Command;

/**
 * Pre-release readiness check: runs all quality gates and outputs a JSON summary.
 *
 * Delegates to ReadinessRunner for check execution.
 * Supports dual output: JSON (default) and human-readable (--human).
 */
class ReadinessCheckCommand extends Command
{
    use HelpersTrait;

    /**
     * @var string
     */
    protected $signature = 'readiness:check
        {--human : Human-readable output instead of JSON}
        {--skip-memory : Skip memory hygiene check}
    ';

    /**
     * @var string
     */
    protected $description = 'Run pre-release readiness checks: PHPStan, PHPUnit, docs, audit, repo health';

    public function handle(): int
    {
        return CommandKernel::run(
            fn () => $this->executeCommand(),
            'readiness:check',
            fn (\Throwable $e) => $this->components->error($e->getMessage()),
        );
    }

    protected function executeCommand(): int
    {
        $this->checkWorkingDir();

        $cwd = getcwd();

        if ($cwd === false) {
            $this->components->error('Unable to determine working directory.');

            return ERROR;
        }

        $runner = new ReadinessRunner($cwd);
        $result = $runner->run((bool) $this->option('skip-memory'));

        if ($this->option('human')) {
            $this->renderHuman($result);
        } else {
            $this->line((string) json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));
        }

        return $result['overall'] === 'FAIL' ? ERROR : OK;
    }

    /**
     * Render results in human-readable format.
     *
     * @param  array<string, mixed>  $result
     */
    private function renderHuman(array $result): void
    {
        $this->components->info('Release Readiness Check');
        $this->newLine();

        /** @var array<string, array{status: string, duration_ms: int, details: array<string, mixed>}> $checks */
        $checks = $result['checks'] ?? [];

        foreach ($checks as $id => $check) {
            $status = $check['status'];
            $durationMs = $check['duration_ms'];
            $details = $check['details'] ?? [];

            $badge = match ($status) {
                'PASS' => '<fg=green>PASS</>',
                'WARN' => '<fg=yellow>WARN</>',
                'FAIL' => '<fg=red>FAIL</>',
                'NEUTRAL' => '<fg=gray>NEUTRAL</>',
                'SKIP' => '<fg=gray>SKIP</>',
                default => $status,
            };

            $timing = $this->formatDuration($durationMs);
            $extra = $this->formatDetails($id, $details);
            $right = $badge . ($timing !== '' ? "  ({$timing})" : '') . ($extra !== '' ? "   {$extra}" : '');

            $this->components->twoColumnDetail($id, $right);
        }

        $this->newLine();

        $overall = $result['overall'] ?? 'UNKNOWN';
        $totalDuration = $this->formatDuration($result['duration_ms'] ?? 0);

        $overallBadge = match ($overall) {
            'PASS' => '<fg=green>PASS</>',
            'WARN' => '<fg=yellow>WARN</>',
            'FAIL' => '<fg=red>FAIL</>',
            default => $overall,
        };

        $this->components->twoColumnDetail('Overall', "{$overallBadge} ({$totalDuration})");
    }

    /**
     * Format duration in human-readable form.
     */
    private function formatDuration(int $ms): string
    {
        if ($ms === 0) {
            return '';
        }

        if ($ms < 1000) {
            return "{$ms}ms";
        }

        $seconds = round($ms / 1000, 1);

        return "{$seconds}s";
    }

    /**
     * Format check-specific details for human output.
     *
     * @param  array<string, mixed>  $details
     */
    private function formatDetails(string $checkId, array $details): string
    {
        return match (true) {
            str_starts_with($checkId, 'phpstan_')
                => sprintf('%d errors', $details['errors'] ?? 0),

            str_starts_with($checkId, 'phpunit_')
                => sprintf(
                    '%d tests, %d assertions',
                    $details['tests'] ?? 0,
                    $details['assertions'] ?? 0,
                ),

            $checkId === 'docs_validation'
                => sprintf(
                    '%d/%d valid',
                    $details['valid'] ?? 0,
                    $details['total'] ?? 0,
                ),

            str_starts_with($checkId, 'composer_audit_')
                => sprintf('%d advisories', $details['advisories'] ?? 0),

            $checkId === 'memory_hygiene'
                => $details['reason'] ?? $details['mode'] ?? '',

            $checkId === 'repo_health'
                => $details['branch'] ?? '',

            default => '',
        };
    }
}
