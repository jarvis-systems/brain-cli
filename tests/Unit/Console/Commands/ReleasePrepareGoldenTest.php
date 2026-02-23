<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console\Commands;

use BrainCLI\Services\Release\ReleasePrepareRunner;
use BrainCLI\Tests\Support\CliOutputCapture;
use PHPUnit\Framework\TestCase;

/**
 * Golden parity tests for ReleasePrepareCommand and ReleasePrepareRunner.
 *
 * Validates JSON output schema stability, human output key lines,
 * and pack directory filesystem structure.
 */
class ReleasePrepareGoldenTest extends TestCase
{
    use CliOutputCapture;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = $this->createTempDir();

        // Create fixture composer.json files
        file_put_contents(
            $this->tempDir . '/composer.json',
            (string) json_encode(['name' => 'jarvis-brain/node', 'version' => 'v0.2.2']),
        );

        mkdir($this->tempDir . '/core', 0755, true);
        file_put_contents(
            $this->tempDir . '/core/composer.json',
            (string) json_encode(['name' => 'jarvis-brain/core', 'version' => 'v0.2.2']),
        );

        mkdir($this->tempDir . '/cli', 0755, true);
        file_put_contents(
            $this->tempDir . '/cli/composer.json',
            (string) json_encode(['name' => 'jarvis-brain/cli', 'version' => 'v0.2.2']),
        );
    }

    protected function tearDown(): void
    {
        $this->cleanDirectory($this->tempDir);
    }

    // ─── JSON Schema Golden Test ────────────────────────────────────

    public function test_json_schema_has_all_required_keys(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);
        $result = $runner->prepare('v0.3.0', false);

        $requiredKeys = [
            'version',
            'status',
            'target_version',
            'current_version',
            'timestamp',
            'duration_ms',
            'readiness_overall',
            'compile_diff_status',
            'evidence_collected',
            'evidence',
            'validation',
            'pack_dir',
            'versions',
        ];

        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $result, "Missing required key: {$key}");
        }
    }

    public function test_json_schema_versions_structure(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);
        $result = $runner->prepare('v0.3.0', false);

        $this->assertArrayHasKey('versions', $result);

        $repos = ['node', 'core', 'cli'];

        foreach ($repos as $repo) {
            $this->assertArrayHasKey($repo, $result['versions'], "Missing repo: {$repo}");
            $this->assertArrayHasKey('path', $result['versions'][$repo]);
            $this->assertArrayHasKey('version', $result['versions'][$repo]);
            $this->assertArrayHasKey('tag', $result['versions'][$repo]);
        }
    }

    public function test_json_schema_validation_structure(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);
        $result = $runner->prepare('v0.3.0', false);

        $this->assertArrayHasKey('validation', $result);
        $this->assertArrayHasKey('valid', $result['validation']);
        $this->assertArrayHasKey('reason', $result['validation']);
    }

    public function test_json_status_without_evidence_is_evidence_only(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);
        $result = $runner->prepare('v0.3.0', false);

        $this->assertSame('evidence_only', $result['status']);
        $this->assertFalse($result['evidence_collected']);
        $this->assertNull($result['readiness_overall']);
        $this->assertNull($result['compile_diff_status']);
    }

    public function test_json_target_and_current_versions(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);
        $result = $runner->prepare('v0.3.0', false);

        $this->assertSame('v0.3.0', $result['target_version']);
        $this->assertSame('v0.2.2', $result['current_version']);
    }

    public function test_json_timestamp_is_iso8601(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);
        $result = $runner->prepare('v0.3.0', false);

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $result['timestamp'],
        );
    }

    public function test_json_pack_dir_is_relative(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);
        $result = $runner->prepare('v0.3.0', false);

        $this->assertSame('.work/releases/v0.3.0', $result['pack_dir']);
        $this->assertStringNotContainsString($this->tempDir, $result['pack_dir']);
    }

    public function test_json_schema_is_deterministic(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);

        $run1 = $runner->prepare('v0.3.0', false);
        $run2 = $runner->prepare('v0.3.0', false);

        // Remove non-deterministic fields
        unset($run1['timestamp'], $run1['duration_ms']);
        unset($run2['timestamp'], $run2['duration_ms']);

        $this->assertSame($run1, $run2);
    }

    // ─── Human Output Golden Test ───────────────────────────────────

    public function test_human_output_source_has_key_sections(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/ReleasePrepareCommand.php'
        ) ?: '';

        $this->assertStringContainsString('Release Preparation', $source);
        $this->assertStringContainsString('Detected versions', $source);
        $this->assertStringContainsString('Evidence', $source);
        $this->assertStringContainsString('twoColumnDetail', $source);
    }

    public function test_human_output_renders_status_badges(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/ReleasePrepareCommand.php'
        ) ?: '';

        $this->assertStringContainsString("'ready'", $source);
        $this->assertStringContainsString("'blocked'", $source);
        $this->assertStringContainsString("'evidence_only'", $source);
    }

    public function test_human_output_shows_pack_path(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/ReleasePrepareCommand.php'
        ) ?: '';

        $this->assertStringContainsString("'Pack'", $source);
        $this->assertStringContainsString('next-steps.md', $source);
    }

    // ─── Filesystem Pack Tree Golden Test ───────────────────────────

    public function test_pack_directory_created_with_expected_files(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);
        $result = $runner->prepare('v0.3.0', false);

        $packDir = $this->tempDir . '/' . $result['pack_dir'];
        $this->assertDirectoryExists($packDir);

        $expectedFiles = [
            'manifest.json',
            'versions.json',
            'readiness.json',
            'compile-diff.json',
            'next-steps.md',
        ];

        foreach ($expectedFiles as $file) {
            $this->assertFileExists($packDir . '/' . $file, "Missing pack file: {$file}");
        }
    }

    public function test_pack_json_files_are_valid_json(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);
        $result = $runner->prepare('v0.3.0', false);

        $packDir = $this->tempDir . '/' . $result['pack_dir'];

        $jsonFiles = [
            'manifest.json',
            'versions.json',
            'readiness.json',
            'compile-diff.json',
        ];

        foreach ($jsonFiles as $file) {
            $path = $packDir . '/' . $file;
            $content = file_get_contents($path);
            $this->assertIsString($content, "Cannot read {$file}");

            json_decode($content);
            $this->assertSame(
                JSON_ERROR_NONE,
                json_last_error(),
                "Invalid JSON in {$file}: " . json_last_error_msg(),
            );
        }
    }

    public function test_pack_manifest_has_stable_schema(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);
        $result = $runner->prepare('v0.3.0', false);

        $packDir = $this->tempDir . '/' . $result['pack_dir'];
        $manifest = json_decode((string) file_get_contents($packDir . '/manifest.json'), true);

        $this->assertIsArray($manifest);

        $requiredKeys = ['version', 'target', 'current', 'status', 'timestamp'];

        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $manifest, "Manifest missing key: {$key}");
        }

        $this->assertSame('1.2.0', $manifest['version']);
        $this->assertSame('v0.3.0', $manifest['target']);
        $this->assertSame('v0.2.2', $manifest['current']);
    }

    public function test_pack_versions_json_mirrors_result(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);
        $result = $runner->prepare('v0.3.0', false);

        $packDir = $this->tempDir . '/' . $result['pack_dir'];
        $versions = json_decode((string) file_get_contents($packDir . '/versions.json'), true);

        $this->assertIsArray($versions);
        $this->assertSame($result['versions'], $versions);
    }

    public function test_pack_next_steps_is_not_empty(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);
        $result = $runner->prepare('v0.3.0', false);

        $packDir = $this->tempDir . '/' . $result['pack_dir'];
        $content = (string) file_get_contents($packDir . '/next-steps.md');

        $this->assertNotEmpty(trim($content));
        $this->assertStringContainsString('# Release v0.3.0', $content);
        $this->assertStringContainsString('quality gates', $content);
    }

    public function test_pack_no_extra_files(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);
        $result = $runner->prepare('v0.3.0', false);

        $packDir = $this->tempDir . '/' . $result['pack_dir'];

        $files = array_values(array_diff(
            (array) scandir($packDir),
            ['.', '..'],
        ));

        sort($files);

        $expected = [
            'compile-diff.json',
            'manifest.json',
            'next-steps.md',
            'readiness.json',
            'versions.json',
        ];

        $this->assertSame($expected, $files);
    }

    // ─── Apply Mode Golden Tests ──────────────────────────────────

    public function test_apply_result_has_apply_specific_keys(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);

        // Apply mode — evidence will be null (no brain CLI in temp dir), but bumps happen
        $result = $runner->apply('v0.3.0', false);

        $this->assertArrayHasKey('applied', $result);
        $this->assertArrayHasKey('apply_plan', $result);
        $this->assertTrue($result['applied']);
        $this->assertIsArray($result['apply_plan']);
    }

    public function test_apply_bumps_versions_in_fixtures(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);
        $result = $runner->apply('v0.3.0', false);

        // Verify files were changed
        $node = json_decode((string) file_get_contents($this->tempDir . '/composer.json'), true);
        $core = json_decode((string) file_get_contents($this->tempDir . '/core/composer.json'), true);
        $cli = json_decode((string) file_get_contents($this->tempDir . '/cli/composer.json'), true);

        $this->assertSame('v0.3.0', $node['version']);
        $this->assertSame('v0.3.0', $core['version']);
        $this->assertSame('v0.3.0', $cli['version']);
    }

    public function test_apply_creates_apply_plan_json_in_pack(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);
        $result = $runner->apply('v0.3.0', false);

        $packDir = $this->tempDir . '/' . $result['pack_dir'];
        $planPath = $packDir . '/apply-plan.json';

        $this->assertFileExists($planPath);

        $plan = json_decode((string) file_get_contents($planPath), true);
        $this->assertIsArray($plan);
        $this->assertArrayHasKey('changes', $plan);
        $this->assertArrayHasKey('git_commands', $plan);
        $this->assertSame('v0.3.0', $plan['target']);
    }

    public function test_apply_status_is_applied(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);
        $result = $runner->apply('v0.3.0', false);

        $this->assertSame('applied', $result['status']);
    }

    public function test_apply_validation_failed_on_bad_version(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);
        $result = $runner->apply('not-semver', false);

        $this->assertSame('validation_failed', $result['status']);
        $this->assertFalse($result['applied']);
        $this->assertNull($result['apply_plan']);
    }

    public function test_apply_validation_failed_on_downgrade(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);
        $result = $runner->apply('v0.1.0', false);

        $this->assertSame('validation_failed', $result['status']);
        $this->assertFalse($result['applied']);
    }

    public function test_dry_run_result_has_no_apply_keys(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);
        $result = $runner->prepare('v0.3.0', false);

        // Dry-run should NOT have apply-specific keys
        $this->assertArrayNotHasKey('applied', $result);
        $this->assertArrayNotHasKey('apply_plan', $result);
    }

    public function test_apply_next_steps_mentions_post_apply(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);
        $result = $runner->apply('v0.3.0', false);

        $packDir = $this->tempDir . '/' . $result['pack_dir'];
        $content = (string) file_get_contents($packDir . '/next-steps.md');

        $this->assertStringContainsString('post-apply', $content);
        $this->assertStringContainsString('git diff', $content);
    }

    // ─── Evidence Meta Golden Tests ─────────────────────────────────

    public function test_evidence_meta_present_in_result(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);
        $result = $runner->prepare('v0.3.0', false);

        $this->assertArrayHasKey('evidence', $result);
        $this->assertIsArray($result['evidence']);
        $this->assertArrayHasKey('readiness', $result['evidence']);
        $this->assertArrayHasKey('compile_diff', $result['evidence']);
    }

    public function test_evidence_skipped_without_evidence_flag(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);
        $result = $runner->prepare('v0.3.0', false);

        $this->assertSame('skipped', $result['evidence']['readiness']['status']);
        $this->assertSame('skipped', $result['evidence']['compile_diff']['status']);
        $this->assertNotNull($result['evidence']['readiness']['reason']);
        $this->assertNotNull($result['evidence']['compile_diff']['reason']);
    }

    public function test_evidence_missing_when_commands_unavailable(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);
        // With evidence=true but no brain CLI in temp dir → commands return null → missing
        $result = $runner->prepare('v0.3.0', true);

        $this->assertSame('missing', $result['evidence']['readiness']['status']);
        $this->assertSame('missing', $result['evidence']['compile_diff']['status']);
        $this->assertStringContainsString('readiness:check', (string) $result['evidence']['readiness']['reason']);
        $this->assertStringContainsString('compile --diff', (string) $result['evidence']['compile_diff']['reason']);
    }

    public function test_evidence_reasons_are_deterministic(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);

        $run1 = $runner->prepare('v0.3.0', false);
        $run2 = $runner->prepare('v0.3.0', false);

        $this->assertSame($run1['evidence'], $run2['evidence']);
    }

    public function test_evidence_meta_structure_has_status_and_reason(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);
        $result = $runner->prepare('v0.3.0', false);

        foreach (['readiness', 'compile_diff'] as $source) {
            $meta = $result['evidence'][$source];
            $this->assertArrayHasKey('status', $meta, "Evidence {$source} missing 'status'");
            $this->assertArrayHasKey('reason', $meta, "Evidence {$source} missing 'reason'");
            $this->assertContains($meta['status'], ['present', 'missing', 'skipped']);
        }
    }

    public function test_evidence_in_manifest_json(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);
        $result = $runner->prepare('v0.3.0', false);

        $packDir = $this->tempDir . '/' . $result['pack_dir'];
        $manifest = json_decode((string) file_get_contents($packDir . '/manifest.json'), true);

        $this->assertIsArray($manifest);
        $this->assertArrayHasKey('evidence', $manifest);
        $this->assertArrayHasKey('readiness', $manifest['evidence']);
        $this->assertArrayHasKey('compile_diff', $manifest['evidence']);
    }

    public function test_apply_result_has_evidence_meta(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);
        $result = $runner->apply('v0.3.0', false);

        $this->assertArrayHasKey('evidence', $result);
        $this->assertSame('skipped', $result['evidence']['readiness']['status']);
        $this->assertSame('skipped', $result['evidence']['compile_diff']['status']);
    }

    public function test_apply_validation_failed_has_evidence_meta(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);
        $result = $runner->apply('not-semver', false);

        $this->assertArrayHasKey('evidence', $result);
        $this->assertSame('skipped', $result['evidence']['readiness']['status']);
    }

    // ─── Command Source Inspection ──────────────────────────────────

    public function test_command_returns_ok_on_success_path(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/ReleasePrepareCommand.php'
        ) ?: '';

        // v1 is read-only; the executeCommand success path returns OK
        $this->assertStringContainsString('return OK;', $source);

        // Runner result never causes ERROR — status is in JSON output only
        $this->assertStringNotContainsString("result['overall'] === 'FAIL'", $source);
    }

    public function test_runner_source_has_expected_status_values(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Services/Release/ReleasePrepareRunner.php'
        ) ?: '';

        $this->assertStringContainsString("'ready'", $source);
        $this->assertStringContainsString("'blocked'", $source);
        $this->assertStringContainsString("'evidence_only'", $source);
        $this->assertStringContainsString("'applied'", $source);
        $this->assertStringContainsString("'validation_failed'", $source);
    }

    public function test_command_has_apply_option(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/ReleasePrepareCommand.php'
        ) ?: '';

        $this->assertStringContainsString('--apply', $source);
    }

    public function test_command_source_has_evidence_section(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/ReleasePrepareCommand.php'
        ) ?: '';

        $this->assertStringContainsString('renderEvidenceSection', $source);
        $this->assertStringContainsString("'present'", $source);
        $this->assertStringContainsString("'missing'", $source);
        $this->assertStringContainsString("'skipped'", $source);
    }

    public function test_runner_source_has_evidence_meta_builder(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Services/Release/ReleasePrepareRunner.php'
        ) ?: '';

        $this->assertStringContainsString('buildEvidenceMeta', $source);
        $this->assertStringContainsString('buildSingleEvidenceMeta', $source);
    }

    public function test_command_returns_exit_2_for_validation_failure(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/ReleasePrepareCommand.php'
        ) ?: '';

        $this->assertStringContainsString('return 2;', $source);
        $this->assertStringContainsString("'validation_failed'", $source);
        $this->assertStringContainsString("'blocked'", $source);
    }
}
