<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Services\MemoryHygiene;

use BrainCLI\Services\MemoryHygiene\SmokeTestRunner;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SmokeTestRunner::evaluateProbe() pure logic.
 *
 * No MCP calls, no mocks — tests evaluation logic directly.
 */
class SmokeTestRunnerTest extends TestCase
{
    private SmokeTestRunner $runner;

    protected function setUp(): void
    {
        // Constructor requires McpStdioClient but evaluateProbe() doesn't use it.
        // Use reflection to create instance without real client dependency.
        $reflection = new \ReflectionClass(SmokeTestRunner::class);
        $this->runner = $reflection->newInstanceWithoutConstructor();
    }

    public function test_probe_passes_when_expected_memory_at_top_above_floor(): void
    {
        $probe = [
            'id' => 'P01',
            'domain' => 'compile-safety',
            'expected_memory_id' => 276,
            'critical' => true,
        ];

        $searchResults = [
            ['id' => 276, 'similarity' => 0.743],
            ['id' => 228, 'similarity' => 0.462],
        ];

        $result = $this->runner->evaluateProbe($probe, $searchResults, 0.40);

        $this->assertSame('PASS', $result['status']);
        $this->assertSame(276, $result['top_result_id']);
        $this->assertSame(0.743, $result['top_similarity']);
    }

    public function test_probe_fails_when_expected_memory_not_at_top(): void
    {
        $probe = [
            'id' => 'P04',
            'domain' => 'static-analysis',
            'expected_memory_id' => 999,
            'critical' => false,
        ];

        $searchResults = [
            ['id' => 120, 'similarity' => 0.500],
            ['id' => 999, 'similarity' => 0.450],
        ];

        $result = $this->runner->evaluateProbe($probe, $searchResults, 0.40);

        $this->assertSame('FAIL', $result['status']);
        $this->assertSame(120, $result['top_result_id']);
    }

    public function test_probe_fails_below_similarity_floor(): void
    {
        $probe = [
            'id' => 'P08',
            'domain' => 'bridge-pattern',
            'expected_memory_id' => 83,
            'critical' => false,
        ];

        $searchResults = [
            ['id' => 83, 'similarity' => 0.35],
        ];

        $result = $this->runner->evaluateProbe($probe, $searchResults, 0.40);

        $this->assertSame('FAIL', $result['status']);
    }

    public function test_probe_without_expected_id_passes_above_floor(): void
    {
        $probe = [
            'id' => 'P04',
            'domain' => 'static-analysis',
            'expected_memory_id' => null,
            'critical' => false,
        ];

        $searchResults = [
            ['id' => 120, 'similarity' => 0.500],
        ];

        $result = $this->runner->evaluateProbe($probe, $searchResults, 0.40);

        $this->assertSame('PASS', $result['status']);
    }

    public function test_probe_fails_with_empty_search_results(): void
    {
        $probe = [
            'id' => 'P01',
            'domain' => 'compile-safety',
            'expected_memory_id' => 276,
            'critical' => true,
        ];

        $result = $this->runner->evaluateProbe($probe, [], 0.40);

        $this->assertSame('FAIL', $result['status']);
        $this->assertEquals(0, $result['top_similarity']);
        $this->assertNull($result['top_result_id']);
        $this->assertTrue($result['critical']);
    }

    public function test_probe_without_expected_id_fails_with_empty_results(): void
    {
        $probe = [
            'id' => 'P04',
            'domain' => 'static-analysis',
            'expected_memory_id' => null,
            'critical' => false,
        ];

        $result = $this->runner->evaluateProbe($probe, [], 0.40);

        $this->assertSame('FAIL', $result['status']);
        $this->assertEquals(0, $result['top_similarity']);
        $this->assertNull($result['top_result_id']);
    }

    public function test_critical_flag_propagated_to_result(): void
    {
        $criticalProbe = [
            'id' => 'P01',
            'domain' => 'compile-safety',
            'expected_memory_id' => 276,
            'critical' => true,
        ];

        $nonCriticalProbe = [
            'id' => 'P04',
            'domain' => 'static-analysis',
            'expected_memory_id' => null,
            'critical' => false,
        ];

        $searchResults = [
            ['id' => 276, 'similarity' => 0.743],
        ];

        $criticalResult = $this->runner->evaluateProbe($criticalProbe, $searchResults, 0.40);
        $nonCriticalResult = $this->runner->evaluateProbe($nonCriticalProbe, $searchResults, 0.40);

        $this->assertTrue($criticalResult['critical']);
        $this->assertFalse($nonCriticalResult['critical']);
    }
}
