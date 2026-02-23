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

    // ─── --json flag: clean output contract ─────────────────────────

    public function test_json_option_exists_in_signature(): void
    {
        $this->assertStringContainsString('{--json', $this->source);
    }

    public function test_json_mode_guards_progress_messages(): void
    {
        // All $this->components->info() calls should be guarded by !option('json')
        // Count info() calls and guards
        $infoCount = substr_count($this->source, "\$this->components->info(");
        $guardCount = substr_count($this->source, "! \$this->option('json')");

        // Every info() call should have a corresponding json guard
        // (error messages via $this->components->error() are NOT guarded — they go to stderr)
        $this->assertGreaterThan(0, $guardCount, 'No json guards found');
        $this->assertGreaterThanOrEqual($infoCount, $guardCount, 'Not all info() calls are guarded by json check');
    }

    public function test_json_output_summary_always_emitted(): void
    {
        // outputSummary() must NOT be guarded — it's the JSON payload
        $this->assertStringContainsString('$this->outputSummary(', $this->source);
    }
}
