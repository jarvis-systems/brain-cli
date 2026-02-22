<?php

declare(strict_types=1);

namespace BrainCLI\Services\Memory;

/**
 * Reads cached memory-hygiene artifacts and computes status.
 *
 * Pure service — no console or MCP dependency. Reads JSON artifacts
 * from .work/memory-hygiene/ and returns a structured status array.
 */
class MemoryStatusCollector
{
    private const VERSION = '1.0.0';

    public function __construct(
        private readonly string $artifactDir,
    ) {
    }

    /**
     * Collect memory status from cached artifacts.
     *
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $ledger = $this->readArtifact('ledger.json');
        $smoke = $this->readArtifact('smoke-results.json');
        $rankSafety = $this->readArtifact('rank-safety-results.json');

        $status = $this->computeStatus($ledger, $smoke, $rankSafety);
        $staleness = $this->computeStaleness($ledger);
        $hints = $this->buildHints($status, $smoke, $staleness);

        if ($status === 'no_data' || $status === 'stale') {
            return [
                'version' => self::VERSION,
                'status' => $status,
                'namespace' => $ledger['namespace'] ?? null,
                'counts' => null,
                'health' => null,
                'smoke' => null,
                'rank_safety' => null,
                'last_run' => $ledger['snapshot_date'] ?? null,
                'top_categories' => [],
                'hints' => $hints,
            ];
        }

        $totalProbes = (int) ($smoke['total_probes'] ?? 0);
        $passed = (int) ($smoke['passed'] ?? 0);
        $criticalTotal = $this->countCriticalProbes($smoke);
        $criticalPassed = $this->countCriticalPassed($smoke);

        return [
            'version' => self::VERSION,
            'status' => $status,
            'namespace' => $ledger['namespace'] ?? null,
            'counts' => [
                'total_memories' => (int) ($ledger['total_memories'] ?? 0),
                'active_memories' => (int) ($ledger['consolidation']['active_memories'] ?? $ledger['total_memories'] ?? 0),
                'canonical_tags' => (int) ($ledger['canonical_tags'] ?? 0),
                'unique_tags' => (int) ($ledger['unique_tags'] ?? 0),
            ],
            'health' => $ledger['health_status'] ?? 'Unknown',
            'smoke' => [
                'pass_rate' => (float) ($smoke['pass_rate'] ?? 0),
                'critical_pass_rate' => $criticalTotal > 0
                    ? round($criticalPassed / $criticalTotal, 3)
                    : 0.0,
                'threshold_met' => $smoke['threshold_met'] ?? false,
                'passed' => $passed,
                'total' => $totalProbes,
                'critical_passed' => $criticalPassed,
                'critical_total' => $criticalTotal,
            ],
            'rank_safety' => [
                'verdict' => $rankSafety['verdict'] ?? 'UNKNOWN',
                'overlap_risks' => (int) ($rankSafety['overlap_risks_detected'] ?? 0),
            ],
            'last_run' => $ledger['snapshot_date'] ?? null,
            'top_categories' => $this->extractTopCategories($ledger),
            'hints' => $hints,
        ];
    }

    /**
     * Read a JSON artifact file.
     *
     * @return array<string, mixed>|null
     */
    protected function readArtifact(string $filename): ?array
    {
        $path = rtrim($this->artifactDir, '/') . '/' . $filename;

        if (! file_exists($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false || $contents === '') {
            return null;
        }

        $data = json_decode($contents, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Compute overall status from artifacts.
     *
     * @param  array<string, mixed>|null  $ledger
     * @param  array<string, mixed>|null  $smoke
     * @param  array<string, mixed>|null  $rankSafety
     * @return string 'ok'|'stale'|'no_data'
     */
    protected function computeStatus(?array $ledger, ?array $smoke, ?array $rankSafety): string
    {
        if ($ledger === null && $smoke === null && $rankSafety === null) {
            return 'no_data';
        }

        if ($ledger !== null && ((int) ($ledger['total_memories'] ?? 0)) === 0) {
            return 'no_data';
        }

        if ($ledger === null || $smoke === null || $rankSafety === null) {
            return 'stale';
        }

        return 'ok';
    }

    /**
     * Build action hint strings.
     *
     * @param  array<string, mixed>|null  $smoke
     * @return list<string>
     */
    protected function buildHints(string $status, ?array $smoke, ?string $staleness): array
    {
        $hints = [];

        if ($status === 'no_data') {
            $hints[] = "Run 'brain memory:hygiene' to generate fresh artifacts";

            return $hints;
        }

        if ($status === 'stale') {
            $hints[] = "Run 'brain memory:hygiene' to generate fresh artifacts";
        }

        if ($staleness === 'stale_7d') {
            $hints[] = 'Artifacts are over 7 days old — re-run memory:hygiene urgently';
        } elseif ($staleness === 'stale_24h') {
            $hints[] = 'Artifacts are over 24h old — consider re-running memory:hygiene';
        }

        if ($smoke !== null && isset($smoke['pass_rate'])) {
            $passRate = (float) $smoke['pass_rate'];

            if ($passRate < 0.8) {
                $hints[] = 'Smoke pass rate below 80% — check .docs/runbooks/ for remediation';
            }
        }

        return $hints;
    }

    /**
     * Extract top N categories from ledger.
     *
     * @param  array<string, mixed>|null  $ledger
     * @return list<array{name: string, count: int}>
     */
    protected function extractTopCategories(?array $ledger, int $limit = 5): array
    {
        if ($ledger === null || ! isset($ledger['categories']) || ! is_array($ledger['categories'])) {
            return [];
        }

        $categories = $ledger['categories'];
        arsort($categories);

        $result = [];

        foreach (array_slice($categories, 0, $limit, true) as $name => $count) {
            $result[] = [
                'name' => (string) $name,
                'count' => (int) $count,
            ];
        }

        return $result;
    }

    /**
     * Compute staleness based on snapshot_date.
     *
     * @param  array<string, mixed>|null  $ledger
     * @return string|null null (fresh), 'stale_24h', 'stale_7d'
     */
    protected function computeStaleness(?array $ledger): ?string
    {
        if ($ledger === null || ! isset($ledger['snapshot_date'])) {
            return null;
        }

        $snapshotTime = strtotime($ledger['snapshot_date']);

        if ($snapshotTime === false) {
            return null;
        }

        $age = time() - $snapshotTime;

        if ($age > 7 * 86400) {
            return 'stale_7d';
        }

        if ($age > 86400) {
            return 'stale_24h';
        }

        return null;
    }

    /**
     * Count critical probes total from smoke results.
     *
     * @param  array<string, mixed>|null  $smoke
     */
    private function countCriticalProbes(?array $smoke): int
    {
        if ($smoke === null || ! isset($smoke['results']) || ! is_array($smoke['results'])) {
            return (int) ($smoke['critical_total'] ?? 0);
        }

        $count = 0;

        foreach ($smoke['results'] as $result) {
            if (isset($result['critical']) && $result['critical'] === true) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Count critical probes passed from smoke results.
     *
     * @param  array<string, mixed>|null  $smoke
     */
    private function countCriticalPassed(?array $smoke): int
    {
        if ($smoke === null || ! isset($smoke['results']) || ! is_array($smoke['results'])) {
            return (int) ($smoke['critical_passed'] ?? 0);
        }

        $count = 0;

        foreach ($smoke['results'] as $result) {
            if (
                isset($result['critical'], $result['status'])
                && $result['critical'] === true
                && $result['status'] === 'PASS'
            ) {
                $count++;
            }
        }

        return $count;
    }
}
