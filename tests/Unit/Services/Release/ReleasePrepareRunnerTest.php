<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Services\Release;

use BrainCLI\Services\Release\ReleasePrepareRunner;
use PHPUnit\Framework\TestCase;

/**
 * Reflection tests for ReleasePrepareRunner pure logic methods.
 *
 * Tests version detection, validation, semver parsing, and pack generation
 * via reflection and temp dir fixtures without spawning subprocesses.
 */
class ReleasePrepareRunnerTest extends TestCase
{
    private ReleasePrepareRunner $runner;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/brain-release-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->runner = new ReleasePrepareRunner($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    // ─── detectVersions() ───────────────────────────────────────────

    public function test_detect_versions_reads_three_composer_json(): void
    {
        // Create fixture composer.json files
        file_put_contents(
            $this->tempDir . '/composer.json',
            (string) json_encode(['version' => 'v0.2.2']),
        );

        mkdir($this->tempDir . '/core', 0755, true);
        file_put_contents(
            $this->tempDir . '/core/composer.json',
            (string) json_encode(['version' => 'v0.2.2']),
        );

        mkdir($this->tempDir . '/cli', 0755, true);
        file_put_contents(
            $this->tempDir . '/cli/composer.json',
            (string) json_encode(['version' => 'v0.2.2']),
        );

        $result = $this->callDetectVersions();

        $this->assertArrayHasKey('node', $result);
        $this->assertArrayHasKey('core', $result);
        $this->assertArrayHasKey('cli', $result);

        $this->assertSame('v0.2.2', $result['node']['version']);
        $this->assertSame('v0.2.2', $result['core']['version']);
        $this->assertSame('v0.2.2', $result['cli']['version']);

        $this->assertSame('composer.json', $result['node']['path']);
        $this->assertSame('core/composer.json', $result['core']['path']);
        $this->assertSame('cli/composer.json', $result['cli']['path']);
    }

    public function test_detect_versions_handles_missing_core(): void
    {
        // Only create root composer.json — core and cli missing
        file_put_contents(
            $this->tempDir . '/composer.json',
            (string) json_encode(['version' => 'v0.2.2']),
        );

        $result = $this->callDetectVersions();

        $this->assertSame('v0.2.2', $result['node']['version']);
        $this->assertNull($result['core']['version']);
        $this->assertNull($result['cli']['version']);
    }

    // ─── validateVersion() ──────────────────────────────────────────

    public function test_validate_version_rejects_non_semver(): void
    {
        $versions = [
            'node' => ['path' => 'composer.json', 'version' => 'v0.2.2', 'tag' => null],
        ];

        $result = $this->callValidateVersion('not-a-version', $versions);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Invalid semver', (string) $result['reason']);
    }

    public function test_validate_version_rejects_downgrade(): void
    {
        $versions = [
            'node' => ['path' => 'composer.json', 'version' => 'v0.2.2', 'tag' => null],
        ];

        $result = $this->callValidateVersion('v0.1.0', $versions);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('not greater than', (string) $result['reason']);
    }

    public function test_validate_version_accepts_valid_bump(): void
    {
        $versions = [
            'node' => ['path' => 'composer.json', 'version' => 'v0.2.2', 'tag' => null],
        ];

        $result = $this->callValidateVersion('v0.3.0', $versions);

        $this->assertTrue($result['valid']);
        $this->assertNull($result['reason']);
    }

    // ─── generatePack() ─────────────────────────────────────────────

    public function test_generate_pack_creates_manifest(): void
    {
        $versions = [
            'node' => ['path' => 'composer.json', 'version' => 'v0.2.2', 'tag' => 'v0.2.2'],
            'core' => ['path' => 'core/composer.json', 'version' => 'v0.2.2', 'tag' => 'v0.2.2'],
            'cli' => ['path' => 'cli/composer.json', 'version' => 'v0.2.2', 'tag' => 'v0.2.2'],
        ];

        $packDir = $this->callGeneratePack('v0.3.0', 'v0.2.2', $versions, null, null);

        $manifestPath = $this->tempDir . '/' . $packDir . '/manifest.json';
        $this->assertFileExists($manifestPath);

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $this->assertIsArray($manifest);
        $this->assertSame('v0.3.0', $manifest['target']);
        $this->assertSame('v0.2.2', $manifest['current']);
        $this->assertArrayHasKey('status', $manifest);
        $this->assertArrayHasKey('timestamp', $manifest);
    }

    public function test_generate_pack_creates_next_steps(): void
    {
        $versions = [
            'node' => ['path' => 'composer.json', 'version' => 'v0.2.2', 'tag' => 'v0.2.2'],
        ];

        $packDir = $this->callGeneratePack('v0.3.0', 'v0.2.2', $versions, null, null);

        $nextStepsPath = $this->tempDir . '/' . $packDir . '/next-steps.md';
        $this->assertFileExists($nextStepsPath);

        $content = (string) file_get_contents($nextStepsPath);
        $this->assertStringContainsString('v0.3.0', $content);
        $this->assertStringContainsString('v0.2.2', $content);
        $this->assertStringContainsString('Next Steps', $content);
    }

    public function test_generate_pack_includes_versions_json(): void
    {
        $versions = [
            'node' => ['path' => 'composer.json', 'version' => 'v0.2.2', 'tag' => 'v0.2.2'],
            'core' => ['path' => 'core/composer.json', 'version' => 'v0.2.2', 'tag' => null],
            'cli' => ['path' => 'cli/composer.json', 'version' => 'v0.2.2', 'tag' => 'v0.2.2'],
        ];

        $packDir = $this->callGeneratePack('v0.3.0', 'v0.2.2', $versions, null, null);

        $versionsPath = $this->tempDir . '/' . $packDir . '/versions.json';
        $this->assertFileExists($versionsPath);

        $decoded = json_decode((string) file_get_contents($versionsPath), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('node', $decoded);
        $this->assertArrayHasKey('core', $decoded);
        $this->assertArrayHasKey('cli', $decoded);
        $this->assertSame('v0.2.2', $decoded['node']['version']);
    }

    // ─── applyVersionBumps() ───────────────────────────────────────

    public function test_apply_bumps_all_three_versions(): void
    {
        $this->createComposerFixtures('v0.2.2');

        $changes = $this->callApplyVersionBumps('v0.3.0', 'v0.2.2');

        // Verify files were changed
        $node = json_decode((string) file_get_contents($this->tempDir . '/composer.json'), true);
        $core = json_decode((string) file_get_contents($this->tempDir . '/core/composer.json'), true);
        $cli = json_decode((string) file_get_contents($this->tempDir . '/cli/composer.json'), true);

        $this->assertSame('v0.3.0', $node['version']);
        $this->assertSame('v0.3.0', $core['version']);
        $this->assertSame('v0.3.0', $cli['version']);

        // Should have 4 changes: 3 versions + 1 cli constraint
        $this->assertCount(4, $changes);
    }

    public function test_apply_updates_cli_core_constraint(): void
    {
        $this->createComposerFixtures('v0.2.2');

        $this->callApplyVersionBumps('v0.3.0', 'v0.2.2');

        $cli = json_decode((string) file_get_contents($this->tempDir . '/cli/composer.json'), true);

        $this->assertSame('^v0.3.0', $cli['require']['jarvis-brain/core']);
    }

    public function test_apply_only_touches_composer_json_files(): void
    {
        $this->createComposerFixtures('v0.2.2');

        // Create an extra file that should NOT be touched
        file_put_contents($this->tempDir . '/README.md', '# Test');

        $this->callApplyVersionBumps('v0.3.0', 'v0.2.2');

        $this->assertSame('# Test', file_get_contents($this->tempDir . '/README.md'));
    }

    public function test_apply_skips_missing_composer_json(): void
    {
        // Only create root — core and cli missing
        file_put_contents(
            $this->tempDir . '/composer.json',
            (string) json_encode(['version' => 'v0.2.2']),
        );

        $changes = $this->callApplyVersionBumps('v0.3.0', 'v0.2.2');

        // Only node version bumped
        $this->assertCount(1, $changes);
        $this->assertSame('composer.json', $changes[0]['file']);
    }

    public function test_apply_changes_record_before_and_after(): void
    {
        $this->createComposerFixtures('v0.2.2');

        $changes = $this->callApplyVersionBumps('v0.3.0', 'v0.2.2');

        foreach ($changes as $change) {
            $this->assertArrayHasKey('file', $change);
            $this->assertArrayHasKey('field', $change);
            $this->assertArrayHasKey('before', $change);
            $this->assertArrayHasKey('after', $change);
        }

        $versionChanges = array_filter($changes, fn (array $c): bool => $c['field'] === 'version');

        foreach ($versionChanges as $change) {
            $this->assertSame('v0.2.2', $change['before']);
            $this->assertSame('v0.3.0', $change['after']);
        }
    }

    // ─── writeApplyPlan() ───────────────────────────────────────────

    public function test_write_apply_plan_creates_valid_json(): void
    {
        $this->createComposerFixtures('v0.2.2');

        $packDir = $this->callGeneratePack(
            'v0.3.0',
            'v0.2.2',
            ['node' => ['path' => 'composer.json', 'version' => 'v0.2.2', 'tag' => null]],
            null,
            null,
        );

        $changes = [
            ['file' => 'composer.json', 'field' => 'version', 'before' => 'v0.2.2', 'after' => 'v0.3.0'],
        ];

        $this->callWriteApplyPlan($packDir, $changes, 'v0.3.0', 'v0.2.2');

        $planPath = $this->tempDir . '/' . $packDir . '/apply-plan.json';
        $this->assertFileExists($planPath);

        $plan = json_decode((string) file_get_contents($planPath), true);
        $this->assertIsArray($plan);
        $this->assertArrayHasKey('changes', $plan);
        $this->assertArrayHasKey('git_commands', $plan);
        $this->assertArrayHasKey('target', $plan);
        $this->assertSame('v0.3.0', $plan['target']);
        $this->assertNotEmpty($plan['git_commands']);
    }

    public function test_apply_plan_git_commands_include_tag(): void
    {
        $this->createComposerFixtures('v0.2.2');

        $packDir = $this->callGeneratePack(
            'v0.3.0',
            'v0.2.2',
            ['node' => ['path' => 'composer.json', 'version' => 'v0.2.2', 'tag' => null]],
            null,
            null,
        );

        $this->callWriteApplyPlan($packDir, [], 'v0.3.0', 'v0.2.2');

        $plan = json_decode(
            (string) file_get_contents($this->tempDir . '/' . $packDir . '/apply-plan.json'),
            true,
        );

        $gitCommands = implode("\n", $plan['git_commands'] ?? []);
        $this->assertStringContainsString('git tag', $gitCommands);
        $this->assertStringContainsString('v0.3.0', $gitCommands);
        $this->assertStringContainsString('git push', $gitCommands);
    }

    // ─── computeStatus() for apply ─────────────────────────────────

    public function test_compute_status_blocked_on_readiness_fail(): void
    {
        $readiness = ['overall' => 'FAIL', 'checks' => []];
        $result = $this->callComputeStatus($readiness, null, true);
        $this->assertSame('blocked', $result);
    }

    public function test_compute_status_ready_on_readiness_pass(): void
    {
        $readiness = ['overall' => 'PASS', 'checks' => []];
        $result = $this->callComputeStatus($readiness, null, true);
        $this->assertSame('ready', $result);
    }

    // ─── buildEvidenceMeta() ─────────────────────────────────────────

    public function test_evidence_meta_skipped_when_not_requested(): void
    {
        $result = $this->callBuildEvidenceMeta(null, null, false);

        $this->assertSame('skipped', $result['readiness']['status']);
        $this->assertSame('skipped', $result['compile_diff']['status']);
        $this->assertNotNull($result['readiness']['reason']);
        $this->assertNotNull($result['compile_diff']['reason']);
    }

    public function test_evidence_meta_missing_when_data_null(): void
    {
        $result = $this->callBuildEvidenceMeta(null, null, true);

        $this->assertSame('missing', $result['readiness']['status']);
        $this->assertSame('missing', $result['compile_diff']['status']);
        $this->assertStringContainsString('readiness:check', (string) $result['readiness']['reason']);
        $this->assertStringContainsString('compile --diff', (string) $result['compile_diff']['reason']);
    }

    public function test_evidence_meta_present_when_data_available(): void
    {
        $readiness = ['overall' => 'PASS', 'checks' => []];
        $compileDiff = ['has_diff' => false];

        $result = $this->callBuildEvidenceMeta($readiness, $compileDiff, true);

        $this->assertSame('present', $result['readiness']['status']);
        $this->assertSame('present', $result['compile_diff']['status']);
        $this->assertNull($result['readiness']['reason']);
        $this->assertNull($result['compile_diff']['reason']);
    }

    public function test_evidence_meta_mixed_present_and_missing(): void
    {
        $readiness = ['overall' => 'PASS', 'checks' => []];

        $result = $this->callBuildEvidenceMeta($readiness, null, true);

        $this->assertSame('present', $result['readiness']['status']);
        $this->assertSame('missing', $result['compile_diff']['status']);
    }

    // ─── Helpers ────────────────────────────────────────────────────

    private function createComposerFixtures(string $version): void
    {
        file_put_contents(
            $this->tempDir . '/composer.json',
            (string) json_encode(['name' => 'jarvis-brain/node', 'version' => $version]),
        );

        if (! is_dir($this->tempDir . '/core')) {
            mkdir($this->tempDir . '/core', 0755, true);
        }

        file_put_contents(
            $this->tempDir . '/core/composer.json',
            (string) json_encode(['name' => 'jarvis-brain/core', 'version' => $version]),
        );

        if (! is_dir($this->tempDir . '/cli')) {
            mkdir($this->tempDir . '/cli', 0755, true);
        }

        file_put_contents(
            $this->tempDir . '/cli/composer.json',
            (string) json_encode([
                'name' => 'jarvis-brain/cli',
                'version' => $version,
                'require' => ['jarvis-brain/core' => '^' . $version],
            ]),
        );
    }

    /**
     * @return array<string, array{path: string, version: string|null, tag: string|null}>
     */
    private function callDetectVersions(): array
    {
        $method = new \ReflectionMethod($this->runner, 'detectVersions');

        /** @var array<string, array{path: string, version: string|null, tag: string|null}> */
        return $method->invoke($this->runner);
    }

    /**
     * @param  array<string, array{path: string, version: string|null, tag: string|null}>  $versions
     * @return array{valid: bool, reason: string|null}
     */
    private function callValidateVersion(string $targetVersion, array $versions): array
    {
        $method = new \ReflectionMethod($this->runner, 'validateVersion');

        /** @var array{valid: bool, reason: string|null} */
        return $method->invoke($this->runner, $targetVersion, $versions);
    }

    /**
     * @param  array<string, array{path: string, version: string|null, tag: string|null}>  $versions
     * @param  array<string, mixed>|null  $readiness
     * @param  array<string, mixed>|null  $compileDiff
     */
    private function callGeneratePack(
        string $targetVersion,
        string $currentVersion,
        array $versions,
        ?array $readiness,
        ?array $compileDiff,
    ): string {
        $method = new \ReflectionMethod($this->runner, 'generatePack');

        /** @var string */
        return $method->invoke($this->runner, $targetVersion, $currentVersion, $versions, $readiness, $compileDiff);
    }

    /**
     * @return array<int, array{file: string, field: string, before: string|null, after: string}>
     */
    private function callApplyVersionBumps(string $targetVersion, string $currentVersion): array
    {
        $method = new \ReflectionMethod($this->runner, 'applyVersionBumps');

        /** @var array<int, array{file: string, field: string, before: string|null, after: string}> */
        return $method->invoke($this->runner, $targetVersion, $currentVersion);
    }

    /**
     * @param  array<int, array{file: string, field: string, before: string|null, after: string}>  $changes
     */
    private function callWriteApplyPlan(
        string $packDir,
        array $changes,
        string $targetVersion,
        string $currentVersion,
    ): void {
        $method = new \ReflectionMethod($this->runner, 'writeApplyPlan');
        $method->invoke($this->runner, $packDir, $changes, $targetVersion, $currentVersion);
    }

    /**
     * @param  array<string, mixed>|null  $readiness
     * @param  array<string, mixed>|null  $compileDiff
     */
    private function callComputeStatus(?array $readiness, ?array $compileDiff, bool $evidenceCollected): string
    {
        $method = new \ReflectionMethod($this->runner, 'computeStatus');

        /** @var string */
        return $method->invoke($this->runner, $readiness, $compileDiff, $evidenceCollected);
    }

    /**
     * @param  array<string, mixed>|null  $readiness
     * @param  array<string, mixed>|null  $compileDiff
     * @return array{readiness: array{status: string, reason: string|null}, compile_diff: array{status: string, reason: string|null}}
     */
    private function callBuildEvidenceMeta(?array $readiness, ?array $compileDiff, bool $requested): array
    {
        $method = new \ReflectionMethod($this->runner, 'buildEvidenceMeta');

        /** @var array{readiness: array{status: string, reason: string|null}, compile_diff: array{status: string, reason: string|null}} */
        return $method->invoke($this->runner, $readiness, $compileDiff, $requested);
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
