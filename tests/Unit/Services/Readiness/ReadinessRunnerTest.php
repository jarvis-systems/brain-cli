<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Services\Readiness;

use BrainCLI\Services\Readiness\ReadinessRunner;
use PHPUnit\Framework\TestCase;

/**
 * Reflection tests for ReadinessRunner pure logic methods.
 *
 * Tests computeOverall(), parsePhpUnitOutput(), and parseDocsOutput()
 * via reflection without spawning subprocesses.
 */
class ReadinessRunnerTest extends TestCase
{
    private ReadinessRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new ReadinessRunner('/tmp/test-project');
    }

    // ─── computeOverall() ───────────────────────────────────────────

    public function test_compute_overall_all_pass(): void
    {
        $checks = [
            'a' => ['status' => 'PASS', 'duration_ms' => 10, 'details' => []],
            'b' => ['status' => 'PASS', 'duration_ms' => 20, 'details' => []],
            'c' => ['status' => 'PASS', 'duration_ms' => 30, 'details' => []],
        ];

        $result = $this->callComputeOverall($checks);
        $this->assertSame('PASS', $result);
    }

    public function test_compute_overall_with_fail(): void
    {
        $checks = [
            'a' => ['status' => 'PASS', 'duration_ms' => 10, 'details' => []],
            'b' => ['status' => 'FAIL', 'duration_ms' => 20, 'details' => []],
            'c' => ['status' => 'WARN', 'duration_ms' => 30, 'details' => []],
        ];

        $result = $this->callComputeOverall($checks);
        $this->assertSame('FAIL', $result);
    }

    public function test_compute_overall_with_warn(): void
    {
        $checks = [
            'a' => ['status' => 'PASS', 'duration_ms' => 10, 'details' => []],
            'b' => ['status' => 'WARN', 'duration_ms' => 20, 'details' => []],
            'c' => ['status' => 'PASS', 'duration_ms' => 30, 'details' => []],
        ];

        $result = $this->callComputeOverall($checks);
        $this->assertSame('WARN', $result);
    }

    public function test_compute_overall_neutral_counts_as_pass(): void
    {
        $checks = [
            'a' => ['status' => 'PASS', 'duration_ms' => 10, 'details' => []],
            'b' => ['status' => 'NEUTRAL', 'duration_ms' => 0, 'details' => []],
            'c' => ['status' => 'SKIP', 'duration_ms' => 0, 'details' => []],
        ];

        $result = $this->callComputeOverall($checks);
        $this->assertSame('PASS', $result);
    }

    // ─── parsePhpUnitOutput() ───────────────────────────────────────

    public function test_parse_phpunit_output_extracts_counts(): void
    {
        $output = "OK (273 tests, 645 assertions)\n";

        $result = $this->callParsePhpUnitOutput($output);

        $this->assertSame(273, $result['tests']);
        $this->assertSame(645, $result['assertions']);
        $this->assertSame(0, $result['failures']);
        $this->assertSame(0, $result['errors']);
    }

    public function test_parse_phpunit_output_handles_failures(): void
    {
        // PHPUnit failure format: "FAILURES!\nTests: 100, Assertions: 200, Failures: 3."
        $output = "FAILURES!\n100 tests, 200 assertions, 3 failures, 1 errors.\n";

        $result = $this->callParsePhpUnitOutput($output);

        $this->assertSame(100, $result['tests']);
        $this->assertSame(200, $result['assertions']);
        $this->assertSame(3, $result['failures']);
        $this->assertSame(1, $result['errors']);
    }

    // ─── parseDocsOutput() ──────────────────────────────────────────

    public function test_parse_docs_output_extracts_summary(): void
    {
        $json = json_encode([
            'total' => 91,
            'valid' => 91,
            'invalid' => 0,
            'warnings' => 0,
        ]);

        $result = $this->callParseDocsOutput((string) $json);

        $this->assertSame(91, $result['total']);
        $this->assertSame(91, $result['valid']);
        $this->assertSame(0, $result['invalid']);
        $this->assertSame(0, $result['warnings']);
    }

    // ─── checkMemoryStatus() ────────────────────────────────────────

    public function test_readiness_includes_memory_status_section(): void
    {
        $checks = [
            'repo_health' => ['status' => 'PASS', 'duration_ms' => 1, 'details' => []],
            'memory_status' => ['status' => 'NEUTRAL', 'duration_ms' => 0, 'details' => ['memory_status' => 'ok']],
        ];

        // memory_status is always NEUTRAL, so overall should not be affected
        $result = $this->callComputeOverall($checks);
        $this->assertSame('PASS', $result);
    }

    public function test_no_data_memory_status_does_not_flip_overall_fail(): void
    {
        $checks = [
            'repo_health' => ['status' => 'PASS', 'duration_ms' => 1, 'details' => []],
            'phpstan_core' => ['status' => 'PASS', 'duration_ms' => 10, 'details' => []],
            'memory_status' => ['status' => 'NEUTRAL', 'duration_ms' => 0, 'details' => ['memory_status' => 'no_data']],
        ];

        $result = $this->callComputeOverall($checks);
        $this->assertSame('PASS', $result, 'memory_status with no_data must not cause FAIL');
    }

    // ─── memory_hygiene: infra classification ──────────────────────

    public function test_mcp_unavailable_classifies_as_infra(): void
    {
        $method = new \ReflectionMethod($this->runner, 'classifyMcpInfraError');

        // Known MCP infrastructure error patterns
        $this->assertSame(
            'MCP config not found (.mcp.json missing)',
            $method->invoke($this->runner, '  ERROR  .mcp.json not found in project root'),
        );

        $this->assertSame(
            'MCP server connection failed',
            $method->invoke($this->runner, '  ERROR  MCP error: Connection refused'),
        );

        $this->assertSame(
            'MCP server binary not found on PATH',
            $method->invoke($this->runner, '  ERROR  npx not found on PATH. Install it first.'),
        );
    }

    public function test_real_failure_does_not_classify_as_infra(): void
    {
        $method = new \ReflectionMethod($this->runner, 'classifyMcpInfraError');

        // Non-infra errors must return null (not classified as infra)
        $this->assertNull($method->invoke($this->runner, 'Some other error'));
        $this->assertNull($method->invoke($this->runner, 'Consolidation blocked: smoke tests below threshold'));
        $this->assertNull($method->invoke($this->runner, ''));
    }

    public function test_mcp_infra_warn_does_not_block_overall(): void
    {
        // memory_hygiene = WARN (infra) + all others = PASS → overall = WARN (not FAIL)
        $checks = [
            'repo_health' => ['status' => 'PASS', 'duration_ms' => 1, 'details' => []],
            'phpstan_core' => ['status' => 'PASS', 'duration_ms' => 10, 'details' => []],
            'phpstan_cli' => ['status' => 'PASS', 'duration_ms' => 10, 'details' => []],
            'phpunit_core' => ['status' => 'PASS', 'duration_ms' => 10, 'details' => []],
            'phpunit_cli' => ['status' => 'PASS', 'duration_ms' => 10, 'details' => []],
            'docs_validation' => ['status' => 'PASS', 'duration_ms' => 10, 'details' => []],
            'composer_audit_core' => ['status' => 'PASS', 'duration_ms' => 10, 'details' => []],
            'composer_audit_cli' => ['status' => 'PASS', 'duration_ms' => 10, 'details' => []],
            'memory_hygiene' => [
                'status' => 'WARN',
                'duration_ms' => 100,
                'details' => ['mode' => 'infra_unavailable', 'error_kind' => 'infra', 'reason' => 'MCP config not found'],
            ],
            'memory_status' => ['status' => 'NEUTRAL', 'duration_ms' => 0, 'details' => []],
        ];

        $result = $this->callComputeOverall($checks);

        $this->assertSame('WARN', $result, 'MCP infra failure must produce WARN overall, not FAIL');
    }

    public function test_real_hygiene_fail_still_blocks_overall(): void
    {
        // memory_hygiene = FAIL (real regression) → overall = FAIL (still blocks release)
        $checks = [
            'repo_health' => ['status' => 'PASS', 'duration_ms' => 1, 'details' => []],
            'phpstan_core' => ['status' => 'PASS', 'duration_ms' => 10, 'details' => []],
            'phpstan_cli' => ['status' => 'PASS', 'duration_ms' => 10, 'details' => []],
            'phpunit_core' => ['status' => 'PASS', 'duration_ms' => 10, 'details' => []],
            'phpunit_cli' => ['status' => 'PASS', 'duration_ms' => 10, 'details' => []],
            'docs_validation' => ['status' => 'PASS', 'duration_ms' => 10, 'details' => []],
            'composer_audit_core' => ['status' => 'PASS', 'duration_ms' => 10, 'details' => []],
            'composer_audit_cli' => ['status' => 'PASS', 'duration_ms' => 10, 'details' => []],
            'memory_hygiene' => [
                'status' => 'FAIL',
                'duration_ms' => 5000,
                'details' => ['mode' => 'evaluated', 'threshold_met' => false, 'pass_rate' => 0.3],
            ],
            'memory_status' => ['status' => 'NEUTRAL', 'duration_ms' => 0, 'details' => []],
        ];

        $result = $this->callComputeOverall($checks);

        $this->assertSame('FAIL', $result, 'Real hygiene regression must produce FAIL overall');
    }

    // ─── Helpers ────────────────────────────────────────────────────

    /**
     * @param  array<string, array{status: string, duration_ms: int, details: array<string, mixed>}>  $checks
     */
    private function callComputeOverall(array $checks): string
    {
        $method = new \ReflectionMethod($this->runner, 'computeOverall');

        /** @var string */
        return $method->invoke($this->runner, $checks);
    }

    /**
     * @return array{tests: int, assertions: int, failures: int, errors: int}
     */
    private function callParsePhpUnitOutput(string $output): array
    {
        $method = new \ReflectionMethod($this->runner, 'parsePhpUnitOutput');

        /** @var array{tests: int, assertions: int, failures: int, errors: int} */
        return $method->invoke($this->runner, $output);
    }

    /**
     * @return array{total: int, valid: int, invalid: int, warnings: int}
     */
    private function callParseDocsOutput(string $output): array
    {
        $method = new \ReflectionMethod($this->runner, 'parseDocsOutput');

        /** @var array{total: int, valid: int, invalid: int, warnings: int} */
        return $method->invoke($this->runner, $output);
    }
}
