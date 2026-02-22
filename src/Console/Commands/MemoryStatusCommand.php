<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Console\Kernel\CommandKernel;
use BrainCLI\Console\Traits\HelpersTrait;
use BrainCLI\Services\Memory\MemoryStatusCollector;
use Illuminate\Console\Command;

/**
 * Lightweight read-only memory status dashboard.
 *
 * Reads cached memory-hygiene artifacts and displays a quick
 * snapshot of memory health without spawning MCP servers.
 */
class MemoryStatusCommand extends Command
{
    use HelpersTrait;

    protected $signature = 'memory:status
        {--json : Output raw JSON}
    ';

    protected $description = 'Show memory health dashboard from cached artifacts';

    public function handle(): int
    {
        return CommandKernel::run(
            fn () => $this->executeCommand(),
            'memory:status',
            fn (\Throwable $e) => $this->components->error($e->getMessage()),
        );
    }

    protected function executeCommand(): int
    {
        $this->checkWorkingDir();

        $artifactDir = getcwd() . '/.work/memory-hygiene';
        $collector = new MemoryStatusCollector($artifactDir);
        $data = $collector->collect();

        if ($this->option('json')) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

            return OK;
        }

        $this->renderHumanOutput($data);

        return OK;
    }

    /**
     * Render human-readable dashboard output.
     *
     * @param  array<string, mixed>  $data
     */
    protected function renderHumanOutput(array $data): void
    {
        $status = $data['status'] ?? 'unknown';
        $statusBadge = match ($status) {
            'ok' => '<fg=green>ok</>',
            'stale' => '<fg=yellow>stale</>',
            'no_data' => '<fg=red>no_data</>',
            default => $status,
        };

        $this->newLine();
        $this->components->info('Memory Status');
        $this->newLine();

        $this->components->twoColumnDetail('Status', $statusBadge);

        if ($data['namespace'] !== null) {
            $this->components->twoColumnDetail('Namespace', $data['namespace']);
        }

        if ($data['counts'] !== null) {
            $total = $data['counts']['total_memories'];
            $active = $data['counts']['active_memories'];
            $memoryLine = $total === $active
                ? (string) $total
                : "{$total} ({$active} active)";
            $this->components->twoColumnDetail('Total memories', $memoryLine);
        }

        if ($data['health'] !== null) {
            $this->components->twoColumnDetail('Health', $data['health']);
        }

        if ($data['smoke'] !== null) {
            $passPercent = (int) round($data['smoke']['pass_rate'] * 100);
            $passed = $data['smoke']['passed'];
            $total = $data['smoke']['total'];
            $thresholdTag = $data['smoke']['threshold_met'] ? 'threshold met' : '<fg=red>below threshold</>';
            $this->components->twoColumnDetail(
                'Smoke pass rate',
                "{$passPercent}% ({$passed}/{$total}) — {$thresholdTag}",
            );

            $critPassed = $data['smoke']['critical_passed'];
            $critTotal = $data['smoke']['critical_total'];
            $critPercent = $critTotal > 0
                ? (int) round($critPassed / $critTotal * 100)
                : 0;
            $this->components->twoColumnDetail(
                'Critical score',
                "{$critPercent}% ({$critPassed}/{$critTotal})",
            );
        }

        if ($data['rank_safety'] !== null) {
            $verdict = $data['rank_safety']['verdict'];
            $overlaps = $data['rank_safety']['overlap_risks'];
            $this->components->twoColumnDetail(
                'Rank safety',
                "{$verdict} ({$overlaps} overlap risks)",
            );
        }

        if ($data['last_run'] !== null) {
            $ts = strtotime($data['last_run']);
            $formatted = $ts !== false ? gmdate('Y-m-d H:i', $ts) . ' UTC' : $data['last_run'];
            $this->components->twoColumnDetail('Last hygiene run', $formatted);
        }

        /** @var list<array{name: string, count: int}> $categories */
        $categories = $data['top_categories'] ?? [];

        if ($categories !== []) {
            $this->newLine();
            $this->components->info('Top categories');

            foreach ($categories as $cat) {
                $this->components->twoColumnDetail("  {$cat['name']}", (string) $cat['count']);
            }
        }

        /** @var list<string> $hints */
        $hints = $data['hints'] ?? [];

        if ($hints !== []) {
            $this->newLine();

            foreach ($hints as $hint) {
                $this->components->warn($hint);
            }
        }

        $this->newLine();
    }
}
