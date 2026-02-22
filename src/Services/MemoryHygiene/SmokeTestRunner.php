<?php

declare(strict_types=1);

namespace BrainCLI\Services\MemoryHygiene;

use BrainCLI\Services\Mcp\McpStdioClient;

/**
 * Executes compaction survival smoke tests against vector memory.
 *
 * Runs each probe query via MCP search_memories and evaluates
 * whether the expected memory appears at rank 1 above the similarity floor.
 */
class SmokeTestRunner
{
    protected const DEFAULT_SEARCH_LIMIT = 5;

    public function __construct(
        protected McpStdioClient $client,
    ) {
    }

    /**
     * Run all probes and return structured smoke test results.
     *
     * @param  array<string, mixed>  $probeSet  Decoded probe-set.json
     * @return array<string, mixed> Results matching smoke-results.json schema
     */
    public function run(array $probeSet): array
    {
        $probes = $probeSet['probes'] ?? [];
        $floor = (float) ($probeSet['similarity_floor'] ?? 0.40);
        $threshold = (int) ($probeSet['pass_threshold'] ?? 12);

        $results = [];
        $passed = 0;
        $failed = 0;
        $criticalPassed = 0;
        $criticalTotal = 0;

        foreach ($probes as $probe) {
            $searchResults = $this->client->call('search_memories', [
                'query' => $probe['query'],
                'limit' => self::DEFAULT_SEARCH_LIMIT,
            ]);

            // Normalize: response may have 'memories' key or be a flat list
            $memories = $searchResults['memories'] ?? $searchResults;

            if (! is_array($memories) || ! array_is_list($memories)) {
                $memories = [];
            }

            $result = $this->evaluateProbe($probe, $memories, $floor);
            $results[] = $result;

            if ($result['status'] === 'PASS') {
                $passed++;
            } else {
                $failed++;
            }

            if ($probe['critical'] ?? false) {
                $criticalTotal++;
                if ($result['status'] === 'PASS') {
                    $criticalPassed++;
                }
            }
        }

        $passRate = count($probes) > 0 ? round($passed / count($probes), 3) : 0;

        return [
            'run_date' => gmdate('Y-m-d\TH:i:s\Z'),
            'version' => $probeSet['version'] ?? '1.0.0',
            'total_probes' => count($probes),
            'passed' => $passed,
            'failed' => $failed,
            'pass_rate' => $passRate,
            'threshold' => $threshold / count($probes),
            'threshold_met' => $passed >= $threshold,
            'critical_pass_rate' => $criticalTotal > 0 ? round($criticalPassed / $criticalTotal, 3) : 1.0,
            'critical_passed' => $criticalPassed,
            'critical_total' => $criticalTotal,
            'results' => $results,
        ];
    }

    /**
     * Evaluate a single probe against search results.
     *
     * Pure logic — no MCP calls. Fully testable.
     *
     * @param  array<string, mixed>  $probe  Probe definition from probe-set.json
     * @param  list<array<string, mixed>>  $searchResults  MCP search results (top N)
     * @param  float  $floor  Minimum similarity threshold
     * @return array<string, mixed> Evaluation result
     */
    public function evaluateProbe(array $probe, array $searchResults, float $floor = 0.40): array
    {
        $topResult = $searchResults[0] ?? null;
        $topSimilarity = $topResult !== null ? (float) ($topResult['similarity'] ?? 0) : 0;
        $topResultId = $topResult !== null ? ($topResult['id'] ?? null) : null;
        $expectedId = $probe['expected_memory_id'] ?? null;
        $isCritical = $probe['critical'] ?? false;

        $status = 'FAIL';

        if ($expectedId !== null) {
            // Deterministic evaluation: expected memory must be at top AND above floor
            if ($topResultId === $expectedId && $topSimilarity >= $floor) {
                $status = 'PASS';
            }
        } else {
            // No expected ID (BASELINE_FAIL probes): similarity above floor = PASS
            if ($topSimilarity >= $floor) {
                $status = 'PASS';
            }
        }

        return [
            'id' => $probe['id'] ?? '',
            'domain' => $probe['domain'] ?? '',
            'critical' => $isCritical,
            'status' => $status,
            'top_similarity' => $topSimilarity,
            'top_result_id' => $topResultId,
            'expected_memory_id' => $expectedId,
        ];
    }
}
