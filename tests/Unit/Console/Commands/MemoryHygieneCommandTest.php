<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console\Commands;

use PHPUnit\Framework\TestCase;

/**
 * Source inspection tests for MemoryHygieneCommand.
 *
 * Verifies command structure, options, and integration patterns
 * without spawning processes or MCP servers.
 */
class MemoryHygieneCommandTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/MemoryHygieneCommand.php'
        ) ?: '';
    }

    public function test_command_signature_is_memory_hygiene(): void
    {
        $this->assertStringContainsString("'memory:hygiene", $this->source);
    }

    public function test_consolidate_option_exists(): void
    {
        $this->assertStringContainsString('--consolidate', $this->source);
    }

    public function test_yes_option_exists(): void
    {
        $this->assertStringContainsString('--yes', $this->source);
    }

    public function test_handle_uses_command_kernel(): void
    {
        $this->assertStringContainsString('CommandKernel::run(', $this->source);
    }

    public function test_output_dir_is_work_memory_hygiene(): void
    {
        $this->assertStringContainsString('.work/memory-hygiene', $this->source);
    }

    public function test_no_data_mode_checks_total_memories(): void
    {
        $this->assertStringContainsString('total_memories', $this->source);
    }

    public function test_no_data_mode_sets_no_data_status(): void
    {
        $this->assertStringContainsString("'no_data'", $this->source);
    }

    public function test_no_data_mode_has_handle_method(): void
    {
        $this->assertStringContainsString('handleNoData', $this->source);
    }

    public function test_no_data_smoke_results_include_skipped_count(): void
    {
        $this->assertStringContainsString("'skipped'", $this->source);
    }

    public function test_no_data_rank_safety_verdict_is_no_data(): void
    {
        $this->assertStringContainsString("'NO_DATA'", $this->source);
    }

    public function test_no_data_overrides_health_status(): void
    {
        // Verify health_status is explicitly set to NO_DATA for empty stores
        $this->assertStringContainsString("['health_status'] = 'NO_DATA'", $this->source);
    }

    // ─── Help examples ────────────────────────────────────────────

    public function test_command_has_help_examples(): void
    {
        $this->assertStringContainsString('function getHelp()', $this->source);
        $this->assertStringContainsString('Examples:', $this->source);
        $this->assertStringContainsString('brain memory:hygiene', $this->source);
        $this->assertStringContainsString('--consolidate', $this->source);
    }

    // ─── Output mode contract (JSON default, --human opt-in) ──────

    public function test_json_option_exists_in_signature(): void
    {
        $this->assertStringContainsString('{--json', $this->source);
    }

    public function test_human_option_exists_in_signature(): void
    {
        $this->assertStringContainsString('{--human', $this->source);
    }

    public function test_default_mode_is_json(): void
    {
        // Progress messages must be guarded by option('human'), NOT !option('json').
        // This ensures default (no flags) produces clean JSON output.
        $this->assertStringNotContainsString(
            "! \$this->option('json')",
            $this->source,
            'Default mode must be JSON — guards should use option(\'human\'), not !option(\'json\')',
        );
    }

    public function test_human_mode_guards_progress_messages(): void
    {
        // All $this->components->info() calls must be guarded by option('human')
        $infoCount = substr_count($this->source, "\$this->components->info(");
        $humanGuardCount = substr_count($this->source, "\$this->option('human')");

        // Every info() call should have a corresponding human guard
        // (error messages via $this->components->error() are NOT guarded — they go to stderr)
        $this->assertGreaterThan(0, $humanGuardCount, 'No human guards found');
        $this->assertGreaterThanOrEqual($infoCount, $humanGuardCount, 'Not all info() calls are guarded by human check');
    }

    public function test_json_output_summary_always_emitted(): void
    {
        // outputSummary() must NOT be guarded — it's the JSON payload in both modes
        $this->assertStringContainsString('$this->outputSummary(', $this->source);

        // Verify outputSummary is not inside any conditional block by checking
        // it's preceded by a comment, not an if-statement
        $pos = strpos($this->source, '$this->outputSummary(');
        $this->assertNotFalse($pos);

        $preceding = substr($this->source, max(0, $pos - 80), 80);
        $this->assertStringNotContainsString("if (", $preceding, 'outputSummary() must not be inside a conditional');
    }

    public function test_no_negated_json_option_check(): void
    {
        // Regression lock: the v0.3.x pattern `! $this->option('json')` must not reappear.
        // v0.4.0 uses `$this->option('human')` for all progress guards.
        $this->assertSame(
            0,
            substr_count($this->source, "! \$this->option('json')"),
            'Negated json option check found — use option(\'human\') instead',
        );
    }
}
