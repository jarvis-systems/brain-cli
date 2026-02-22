<?php

declare(strict_types=1);

namespace BrainCLI\Services\MemoryHygiene;

use BrainCLI\Services\Mcp\McpStdioClient;

/**
 * Checks rank safety: verifies new canonicals cannot outrank critical anchors.
 *
 * Evaluates top-5 search results per probe, tagging each result
 * as anchor/canonical, and computing safety margins.
 */
class RankSafetyChecker
{
    protected const OVERLAP_THRESHOLD = 0.01;

    protected const SEARCH_LIMIT = 5;

    public function __construct(
        protected McpStdioClient $client,
    ) {
    }

    /**
     * Run rank safety checks for applicable probes.
     *
     * @param  array<string, mixed>  $probeSet  Decoded probe-set.json
     * @param  list<int>  $canonicalIds  IDs of canonical memories
     * @param  list<int>  $anchorIds  IDs of anchor memories
     * @return array<string, mixed> Results matching rank-safety-results.json schema
     */
    public function check(array $probeSet, array $canonicalIds, array $anchorIds): array
    {
        $probes = $probeSet['probes'] ?? [];
        $results = [];
        $criticalPassed = 0;
        $criticalTotal = 0;
        $overlapRisks = 0;

        foreach ($probes as $probe) {
            $expectedAnchorId = $probe['expected_memory_id'] ?? null;

            $searchResults = $this->client->call('search_memories', [
                'query' => $probe['query'],
                'limit' => self::SEARCH_LIMIT,
            ]);

            $memories = $searchResults['memories'] ?? $searchResults;

            if (! is_array($memories) || ! array_is_list($memories)) {
                $memories = [];
            }

            $top5 = $this->buildTop5($memories, $canonicalIds, $anchorIds);

            $result = $this->evaluateRankSafety(
                $probe,
                $top5,
                $expectedAnchorId,
                $canonicalIds,
                $anchorIds,
            );

            $results[] = $result;

            if ($probe['critical'] ?? false) {
                $criticalTotal++;
                if ($result['status'] === 'PASS' || $result['status'] === 'SAFE') {
                    $criticalPassed++;
                }
            }

            if ($result['overlap_risk'] ?? false) {
                $overlapRisks++;
            }
        }

        $verdict = $overlapRisks === 0 ? 'ALL_CLEAR' : 'OVERLAP_DETECTED';

        return [
            'run_date' => gmdate('Y-m-d\TH:i:s\Z'),
            'version' => $probeSet['version'] ?? '1.0.0',
            'overlap_threshold' => self::OVERLAP_THRESHOLD,
            'probes_checked' => count($results),
            'critical_probes_passed' => $criticalPassed,
            'critical_probes_total' => $criticalTotal,
            'overlap_risks_detected' => $overlapRisks,
            'verdict' => $verdict,
            'canonical_ids_checked' => $canonicalIds,
            'anchor_ids_protected' => $anchorIds,
            'results' => $results,
        ];
    }

    /**
     * Evaluate rank safety for a single probe.
     *
     * Pure logic — no MCP calls. Fully testable.
     *
     * @param  array<string, mixed>  $probe  Probe definition
     * @param  list<array<string, mixed>>  $top5  Tagged top-5 results
     * @param  int|null  $expectedAnchorId  Expected anchor at position 1
     * @param  list<int>  $canonicalIds  Canonical memory IDs
     * @param  list<int>  $anchorIds  Anchor memory IDs
     * @return array<string, mixed> Evaluation result
     */
    public function evaluateRankSafety(
        array $probe,
        array $top5,
        ?int $expectedAnchorId,
        array $canonicalIds,
        array $anchorIds,
    ): array {
        $isCritical = $probe['critical'] ?? false;
        $topResult = $top5[0] ?? null;
        $anchorSimilarity = null;
        $closestCanonicalSimilarity = null;
        $overlapRisk = false;
        $anchorMargin = null;
        $verdict = 'SAFE';

        // Find anchor similarity in top-5
        if ($expectedAnchorId !== null) {
            foreach ($top5 as $entry) {
                if (($entry['id'] ?? null) === $expectedAnchorId) {
                    $anchorSimilarity = (float) ($entry['similarity'] ?? 0);
                    break;
                }
            }
        }

        // Find closest canonical in top-5
        foreach ($top5 as $entry) {
            if ($entry['is_canonical'] ?? false) {
                $sim = (float) ($entry['similarity'] ?? 0);
                if ($closestCanonicalSimilarity === null || $sim > $closestCanonicalSimilarity) {
                    $closestCanonicalSimilarity = $sim;
                }
            }
        }

        // Compute margin if both anchor and canonical found
        if ($anchorSimilarity !== null && $closestCanonicalSimilarity !== null) {
            $anchorMargin = round($anchorSimilarity - $closestCanonicalSimilarity, 3);

            if ($anchorMargin <= 0) {
                $verdict = 'OVERLAP_FAIL';
                $overlapRisk = true;
            } elseif ($anchorMargin <= self::OVERLAP_THRESHOLD) {
                $verdict = 'OVERLAP_RISK';
                $overlapRisk = true;
            }
        }

        // Check if anchor is at position 1 when expected
        $anchorAtTop = false;
        if ($expectedAnchorId !== null && $topResult !== null) {
            $anchorAtTop = ($topResult['id'] ?? null) === $expectedAnchorId;
        }

        // Determine status
        $status = 'PASS';
        if ($expectedAnchorId !== null && ! $anchorAtTop) {
            $status = 'BASELINE_FAIL';
        }

        return [
            'id' => $probe['id'] ?? '',
            'domain' => $probe['domain'] ?? '',
            'critical' => $isCritical,
            'expected_anchor_id' => $expectedAnchorId,
            'status' => $status,
            'top5' => $top5,
            'overlap_risk' => $overlapRisk,
            'anchor_margin' => $anchorMargin,
            'verdict' => $verdict,
        ];
    }

    /**
     * Build tagged top-5 list from search results.
     *
     * @param  list<array<string, mixed>>  $memories  Raw search results
     * @param  list<int>  $canonicalIds
     * @param  list<int>  $anchorIds
     * @return list<array<string, mixed>>
     */
    protected function buildTop5(array $memories, array $canonicalIds, array $anchorIds): array
    {
        $top5 = [];

        foreach (array_slice($memories, 0, 5) as $rank => $memory) {
            $id = $memory['id'] ?? null;

            $top5[] = [
                'rank' => $rank + 1,
                'id' => $id,
                'similarity' => (float) ($memory['similarity'] ?? 0),
                'is_anchor' => $id !== null && in_array($id, $anchorIds, true),
                'is_canonical' => $id !== null && in_array($id, $canonicalIds, true),
            ];
        }

        return $top5;
    }
}
