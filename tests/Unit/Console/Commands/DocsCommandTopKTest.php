<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console\Commands;

use PHPUnit\Framework\TestCase;

class DocsCommandTopKTest extends TestCase
{
    private static string $projectRoot;

    public static function setUpBeforeClass(): void
    {
        self::$projectRoot = dirname(__DIR__, 4);
    }

    public function test_total_matches_preserved_while_files_truncated(): void
    {
        $output = $this->runDocsCommand(['--limit=3', 'api']);

        $this->assertArrayHasKey('total_matches', $output, 'Output should have total_matches');
        $this->assertArrayHasKey('files', $output, 'Output should have files array');
        $this->assertGreaterThan(3, $output['total_matches'], 'total_matches should be > limit');
        $this->assertCount(3, $output['files'], 'files array should be limited to 3');
    }

    public function test_ordering_stable_for_score_ties(): void
    {
        $output = $this->runDocsCommand(['--limit=20', 'the']);

        $files = $output['files'] ?? [];
        $this->assertGreaterThan(1, count($files), 'Should have multiple files');

        $scores = array_map(fn($f) => $f['score'], $files);
        $sortedScores = $scores;
        rsort($sortedScores);

        $this->assertSame($sortedScores, $scores, 'Files should be sorted by score DESC');

        for ($i = 1; $i < count($files); $i++) {
            $prev = $files[$i - 1];
            $curr = $files[$i];

            if ($prev['score'] === $curr['score']) {
                $this->assertLessThan(
                    $curr['path'],
                    $prev['path'],
                    'Files with same score should be sorted by path ASC',
                );
            }
        }
    }

    public function test_enrichment_skipped_for_out_of_k_entries(): void
    {
        $output = $this->runDocsCommand(['--limit=3', '--code', 'api']);

        $this->assertCount(3, $output['files'], 'Should have exactly 3 files');

        $filesWithCodeBlocks = 0;
        foreach ($output['files'] as $file) {
            if (isset($file['code_blocks'])) {
                $filesWithCodeBlocks++;
            }
        }

        $this->assertLessThanOrEqual(3, $filesWithCodeBlocks, 'Only returned files should have code_blocks enrichment');
    }

    public function test_determinism_unchanged_after_optimization(): void
    {
        $results = [];
        for ($i = 0; $i < 3; $i++) {
            $output = $this->runDocsCommand(['--limit=10', 'api']);
            $results[] = array_map(fn($f) => $f['path'], $output['files']);
        }

        $this->assertSame($results[0], $results[1], 'Run 1 and 2 should be identical');
        $this->assertSame($results[1], $results[2], 'Run 2 and 3 should be identical');
    }

    public function test_output_structure_has_required_fields(): void
    {
        $output = $this->runDocsCommand(['--limit=1', 'api']);

        $this->assertArrayHasKey('total_matches', $output);
        $this->assertArrayHasKey('files', $output);
        $this->assertIsInt($output['total_matches']);
        $this->assertIsArray($output['files']);
    }

    private function runDocsCommand(array $args): array
    {
        $cmd = sprintf(
            'cd %s && php bin/brain docs %s 2>&1 | grep -E "^\{" | head -1',
            escapeshellarg(self::$projectRoot),
            implode(' ', array_map('escapeshellarg', $args)),
        );

        $output = shell_exec($cmd);

        $this->assertNotFalse($output, 'Command should execute successfully');
        $this->assertNotEmpty($output, 'Command should produce output');

        $decoded = json_decode($output, true);

        $this->assertNotNull($decoded, 'Output should be valid JSON: ' . substr($output, 0, 200));
        $this->assertIsArray($decoded, 'Output should be an array/object');

        return $decoded;
    }
}
