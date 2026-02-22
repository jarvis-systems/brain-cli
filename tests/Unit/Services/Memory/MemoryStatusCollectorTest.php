<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Services\Memory;

use BrainCLI\Services\Memory\MemoryStatusCollector;
use BrainCLI\Tests\Support\CliOutputCapture;
use PHPUnit\Framework\TestCase;

class MemoryStatusCollectorTest extends TestCase
{
    use CliOutputCapture;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/brain-memory-status-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->cleanDirectory($this->tempDir);
    }

    public function test_collect_returns_ok_when_all_artifacts_present(): void
    {
        $this->writeLedger(['total_memories' => 50, 'categories' => ['code' => 30], 'snapshot_date' => gmdate('Y-m-d\TH:i:s\Z')]);
        $this->writeSmoke(['pass_rate' => 1.0, 'threshold_met' => true, 'total_probes' => 10, 'passed' => 10, 'results' => []]);
        $this->writeRankSafety(['verdict' => 'ALL_CLEAR', 'overlap_risks_detected' => 0]);

        $collector = new MemoryStatusCollector($this->tempDir);
        $result = $collector->collect();

        $this->assertSame('ok', $result['status']);
        $this->assertIsArray($result['counts']);
        $this->assertSame(50, $result['counts']['total_memories']);
    }

    public function test_collect_returns_stale_when_ledger_missing(): void
    {
        $this->writeSmoke(['pass_rate' => 1.0, 'threshold_met' => true, 'total_probes' => 5, 'passed' => 5, 'results' => []]);
        $this->writeRankSafety(['verdict' => 'ALL_CLEAR', 'overlap_risks_detected' => 0]);

        $collector = new MemoryStatusCollector($this->tempDir);
        $result = $collector->collect();

        $this->assertSame('stale', $result['status']);
    }

    public function test_collect_returns_stale_when_smoke_missing(): void
    {
        $this->writeLedger(['total_memories' => 50, 'snapshot_date' => gmdate('Y-m-d\TH:i:s\Z')]);
        $this->writeRankSafety(['verdict' => 'ALL_CLEAR', 'overlap_risks_detected' => 0]);

        $collector = new MemoryStatusCollector($this->tempDir);
        $result = $collector->collect();

        $this->assertSame('stale', $result['status']);
    }

    public function test_collect_returns_no_data_when_no_artifacts(): void
    {
        $collector = new MemoryStatusCollector($this->tempDir);
        $result = $collector->collect();

        $this->assertSame('no_data', $result['status']);
        $this->assertNull($result['counts']);
        $this->assertNull($result['smoke']);
    }

    public function test_collect_returns_no_data_when_total_memories_zero(): void
    {
        $this->writeLedger(['total_memories' => 0, 'snapshot_date' => gmdate('Y-m-d\TH:i:s\Z')]);
        $this->writeSmoke(['pass_rate' => 0, 'threshold_met' => false, 'total_probes' => 0, 'passed' => 0, 'results' => []]);
        $this->writeRankSafety(['verdict' => 'NO_DATA', 'overlap_risks_detected' => 0]);

        $collector = new MemoryStatusCollector($this->tempDir);
        $result = $collector->collect();

        $this->assertSame('no_data', $result['status']);
    }

    public function test_extract_top_categories_limits_to_five(): void
    {
        $this->writeLedger([
            'total_memories' => 100,
            'snapshot_date' => gmdate('Y-m-d\TH:i:s\Z'),
            'categories' => [
                'a' => 50, 'b' => 40, 'c' => 30,
                'd' => 20, 'e' => 10, 'f' => 5, 'g' => 1,
            ],
        ]);
        $this->writeSmoke(['pass_rate' => 1.0, 'threshold_met' => true, 'total_probes' => 5, 'passed' => 5, 'results' => []]);
        $this->writeRankSafety(['verdict' => 'ALL_CLEAR', 'overlap_risks_detected' => 0]);

        $collector = new MemoryStatusCollector($this->tempDir);
        $result = $collector->collect();

        $this->assertCount(5, $result['top_categories']);
        $this->assertSame('a', $result['top_categories'][0]['name']);
        $this->assertSame(50, $result['top_categories'][0]['count']);
    }

    public function test_extract_top_categories_handles_empty_ledger(): void
    {
        $collector = new MemoryStatusCollector($this->tempDir);
        $result = $collector->collect();

        $this->assertSame([], $result['top_categories']);
    }

    public function test_build_hints_includes_run_hygiene_for_no_data(): void
    {
        $collector = new MemoryStatusCollector($this->tempDir);
        $result = $collector->collect();

        $this->assertSame('no_data', $result['status']);
        $this->assertNotEmpty($result['hints']);
        $this->assertStringContainsString('memory:hygiene', $result['hints'][0]);
    }

    public function test_build_hints_includes_runbook_for_low_score(): void
    {
        $this->writeLedger(['total_memories' => 50, 'snapshot_date' => gmdate('Y-m-d\TH:i:s\Z')]);
        $this->writeSmoke(['pass_rate' => 0.5, 'threshold_met' => false, 'total_probes' => 10, 'passed' => 5, 'results' => []]);
        $this->writeRankSafety(['verdict' => 'ALL_CLEAR', 'overlap_risks_detected' => 0]);

        $collector = new MemoryStatusCollector($this->tempDir);
        $result = $collector->collect();

        $found = false;

        foreach ($result['hints'] as $hint) {
            if (str_contains($hint, 'runbook')) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'Expected hints to reference runbook for low pass rate');
    }

    public function test_compute_staleness_returns_null_when_fresh(): void
    {
        $this->writeLedger([
            'total_memories' => 50,
            'snapshot_date' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        $this->writeSmoke(['pass_rate' => 1.0, 'threshold_met' => true, 'total_probes' => 5, 'passed' => 5, 'results' => []]);
        $this->writeRankSafety(['verdict' => 'ALL_CLEAR', 'overlap_risks_detected' => 0]);

        $collector = new MemoryStatusCollector($this->tempDir);
        $result = $collector->collect();

        // Fresh artifacts should have no staleness hints
        $stalenessHints = array_filter(
            $result['hints'],
            fn (string $h) => str_contains($h, 'stale') || str_contains($h, 'old'),
        );

        $this->assertEmpty($stalenessHints);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeLedger(array $data): void
    {
        $this->writeJson('ledger.json', $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeSmoke(array $data): void
    {
        $this->writeJson('smoke-results.json', $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeRankSafety(array $data): void
    {
        $this->writeJson('rank-safety-results.json', $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeJson(string $filename, array $data): void
    {
        file_put_contents(
            $this->tempDir . '/' . $filename,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }
}
