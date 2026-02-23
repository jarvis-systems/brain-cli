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

        $this->assertSame('1.3.0', $manifest['version']);
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

    // ─── JSON stdout purity ────────────────────────────────────────

    public function test_json_mode_auto_suggest_does_not_pollute_stdout(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/ReleasePrepareCommand.php'
        ) ?: '';

        // Auto-suggest info message must be guarded by --human check
        // to prevent stdout pollution in JSON mode
        $this->assertStringContainsString("option('human')", $source);

        // The info() call and the human guard must be near each other
        $infoPos = strpos($source, 'Auto-suggested target version');
        $this->assertNotFalse($infoPos, 'Auto-suggest message not found');

        // The human guard must come before the info() call
        $guardPos = strrpos(substr($source, 0, $infoPos), "option('human')");
        $this->assertNotFalse($guardPos, 'Human guard must precede auto-suggest info');
    }

    public function test_json_output_path_has_no_info_calls(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/ReleasePrepareCommand.php'
        ) ?: '';

        // The JSON output path (else branch of --human check) must only contain
        // json_encode output, not any $this->components->info() calls
        $jsonOutputPos = strpos($source, 'json_encode(');
        $this->assertNotFalse($jsonOutputPos, 'json_encode call not found');

        // After the json_encode block and before return, no unguarded info() calls
        $afterJson = substr($source, $jsonOutputPos);
        $returnPos = strpos($afterJson, 'return OK;');
        $this->assertNotFalse($returnPos, 'return OK not found after json_encode');

        $betweenJsonAndReturn = substr($afterJson, 0, $returnPos);
        $this->assertStringNotContainsString('components->info(', $betweenJsonAndReturn);
    }

    // ─── Help examples ─────────────────────────────────────────────

    public function test_command_has_help_examples(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/ReleasePrepareCommand.php'
        ) ?: '';

        $this->assertStringContainsString('function getHelp()', $source);
        $this->assertStringContainsString('Examples:', $source);
        $this->assertStringContainsString('--evidence', $source);
        $this->assertStringContainsString('--apply', $source);
    }

    // ─── Lock Sync Evidence Golden Tests ──────────────────────────

    public function test_evidence_meta_includes_lock_sync(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);
        $result = $runner->prepare('v0.3.0', false);

        $this->assertArrayHasKey('lock_sync', $result['evidence']);
    }

    public function test_lock_sync_skipped_without_evidence(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);
        $result = $runner->prepare('v0.3.0', false);

        $lockSync = $result['evidence']['lock_sync'];
        $this->assertSame('skipped', $lockSync['status']);
        $this->assertNotNull($lockSync['reason']);
        $this->assertArrayHasKey('repos', $lockSync);
    }

    public function test_lock_sync_structure_has_repos(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);
        $result = $runner->prepare('v0.3.0', true);

        $lockSync = $result['evidence']['lock_sync'];
        $this->assertArrayHasKey('status', $lockSync);
        $this->assertArrayHasKey('reason', $lockSync);
        $this->assertArrayHasKey('repos', $lockSync);
        $this->assertIsArray($lockSync['repos']);
    }

    public function test_lock_sync_repos_skip_without_lock_file(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);
        $result = $runner->prepare('v0.3.0', true);

        $lockSync = $result['evidence']['lock_sync'];

        // Temp fixtures have no composer.lock → all repos should be 'skip'
        foreach ($lockSync['repos'] as $name => $info) {
            $this->assertSame('skip', $info['status'], "Repo {$name} should be 'skip' without lock file");
            $this->assertSame('No composer.lock', $info['reason']);
        }
    }

    public function test_lock_sync_in_manifest_json(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);
        $result = $runner->prepare('v0.3.0', false);

        $packDir = $this->tempDir . '/' . $result['pack_dir'];
        $manifest = json_decode((string) file_get_contents($packDir . '/manifest.json'), true);

        $this->assertIsArray($manifest);
        $this->assertArrayHasKey('evidence', $manifest);
        $this->assertArrayHasKey('lock_sync', $manifest['evidence']);
    }

    public function test_lock_sync_detects_drift_with_stale_lock(): void
    {
        // Create a composer.lock with wrong content-hash for cli
        $cliLock = (string) json_encode([
            '_readme' => ['This file is auto-generated'],
            'content-hash' => str_repeat('a', 32),
            'packages' => [],
            'packages-dev' => [],
        ]);
        file_put_contents($this->tempDir . '/cli/composer.lock', $cliLock);

        $runner = new ReleasePrepareRunner($this->tempDir);
        $result = $runner->prepare('v0.3.0', true);

        $lockSync = $result['evidence']['lock_sync'];

        // CLI should report warn (content-hash mismatch)
        $this->assertSame('warn', $lockSync['repos']['cli']['status']);
        $this->assertNotNull($lockSync['repos']['cli']['reason']);

        // Overall should be warn
        $this->assertSame('warn', $lockSync['status']);
    }

    public function test_apply_post_bump_includes_lock_sync(): void
    {
        $runner = new ReleasePrepareRunner($this->tempDir);
        $result = $runner->apply('v0.3.0', false);

        // Even without evidence flag, apply always runs lock sync post-bump
        $this->assertArrayHasKey('lock_sync', $result['evidence']);
        $lockSync = $result['evidence']['lock_sync'];
        $this->assertArrayHasKey('status', $lockSync);
        $this->assertArrayHasKey('repos', $lockSync);
    }

    public function test_runner_source_has_lock_sync_check(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Services/Release/ReleasePrepareRunner.php'
        ) ?: '';

        $this->assertStringContainsString('checkLockSync', $source);
        $this->assertStringContainsString('extractLockDriftReason', $source);
        $this->assertStringContainsString('composer validate', $source);
        $this->assertStringContainsString("'lock_sync'", $source);
    }

    public function test_command_renders_lock_sync_in_evidence(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/ReleasePrepareCommand.php'
        ) ?: '';

        $this->assertStringContainsString("'lock_sync'", $source);
        $this->assertStringContainsString("'Lock sync'", $source);
        $this->assertStringContainsString("'warn'", $source);
    }
}
