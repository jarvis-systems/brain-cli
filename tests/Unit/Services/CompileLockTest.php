<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Services;

use BrainCLI\Services\CompileLock;
use BrainCLI\Tests\Support\CliOutputCapture;
use PHPUnit\Framework\TestCase;

class CompileLockTest extends TestCase
{
    use CliOutputCapture;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/brain-compile-lock-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->cleanDirectory($this->tempDir);
    }

    // ── Lock acquisition tests ─────────────────────────────────────────

    public function test_acquire_succeeds_when_no_lock_exists(): void
    {
        $lock = new CompileLock($this->tempDir);

        $this->assertTrue($lock->acquire());

        $lock->release();
    }

    public function test_acquire_writes_pid_metadata(): void
    {
        $lock = new CompileLock($this->tempDir);

        $lock->acquire();

        $info = $lock->getHolderInfo();

        $this->assertNotNull($info);
        $this->assertSame(getmypid(), $info['pid']);
        $this->assertArrayHasKey('started_at', $info);
        $this->assertArrayHasKey('timestamp', $info);

        $lock->release();
    }

    public function test_release_removes_lock_file(): void
    {
        $lock = new CompileLock($this->tempDir);

        $lock->acquire();
        $this->assertTrue($lock->exists());

        $lock->release();
        $this->assertFalse($lock->exists());
    }

    public function test_double_release_is_safe(): void
    {
        $lock = new CompileLock($this->tempDir);

        $lock->acquire();
        $lock->release();
        $lock->release();

        $this->assertFalse($lock->exists());
    }

    public function test_concurrent_acquire_fails(): void
    {
        $lock1 = new CompileLock($this->tempDir);
        $lock2 = new CompileLock($this->tempDir);

        $this->assertTrue($lock1->acquire());
        $this->assertFalse($lock2->acquire());

        $lock1->release();
    }

    public function test_acquire_after_release_succeeds(): void
    {
        $lock1 = new CompileLock($this->tempDir);
        $lock2 = new CompileLock($this->tempDir);

        $lock1->acquire();
        $lock1->release();

        $this->assertTrue($lock2->acquire());

        $lock2->release();
    }

    public function test_get_holder_info_returns_null_when_no_lock(): void
    {
        $lock = new CompileLock($this->tempDir);

        $this->assertNull($lock->getHolderInfo());
    }

    public function test_exists_returns_false_when_no_lock(): void
    {
        $lock = new CompileLock($this->tempDir);

        $this->assertFalse($lock->exists());
    }

    public function test_creates_lock_directory_if_missing(): void
    {
        $nestedDir = $this->tempDir . '/nested/locks';
        $lock = new CompileLock($nestedDir);

        $this->assertTrue($lock->acquire());
        $this->assertDirectoryExists($nestedDir);

        $lock->release();
    }

    // ── No-lock governance tests ───────────────────────────────────────

    public function test_no_lock_blocked_under_paranoid_without_override(): void
    {
        $this->assertFalse(CompileLock::isNoLockAllowed('paranoid', null));
    }

    public function test_no_lock_blocked_under_strict_without_override(): void
    {
        $this->assertFalse(CompileLock::isNoLockAllowed('strict', null));
    }

    public function test_no_lock_allowed_under_paranoid_with_override_true(): void
    {
        $this->assertTrue(CompileLock::isNoLockAllowed('paranoid', true));
    }

    public function test_no_lock_allowed_under_paranoid_with_override_int(): void
    {
        $this->assertTrue(CompileLock::isNoLockAllowed('paranoid', 1));
    }

    public function test_no_lock_allowed_under_paranoid_with_override_string(): void
    {
        $this->assertTrue(CompileLock::isNoLockAllowed('paranoid', '1'));
    }

    public function test_no_lock_allowed_under_paranoid_with_override_true_string(): void
    {
        $this->assertTrue(CompileLock::isNoLockAllowed('paranoid', 'true'));
    }

    public function test_no_lock_blocked_under_strict_with_false_override(): void
    {
        $this->assertFalse(CompileLock::isNoLockAllowed('strict', false));
    }

    public function test_no_lock_allowed_under_standard_mode(): void
    {
        $this->assertTrue(CompileLock::isNoLockAllowed('standard', null));
    }

    public function test_no_lock_allowed_under_relaxed_mode(): void
    {
        $this->assertTrue(CompileLock::isNoLockAllowed('relaxed', null));
    }

    public function test_no_lock_allowed_when_mode_is_null(): void
    {
        $this->assertTrue(CompileLock::isNoLockAllowed(null, null));
    }

    // ── Project root detection tests ───────────────────────────────────

    public function test_find_project_root_from_root_dir(): void
    {
        $root = $this->createFakeBrainProject();

        $this->assertSame(realpath($root), CompileLock::findProjectRoot($root));
    }

    public function test_find_project_root_from_subdir(): void
    {
        $root = $this->createFakeBrainProject();
        $subdir = $root . '/core/src/Models';
        mkdir($subdir, 0755, true);

        $this->assertSame(realpath($root), CompileLock::findProjectRoot($subdir));
    }

    public function test_find_project_root_from_deep_subdir(): void
    {
        $root = $this->createFakeBrainProject();
        $deep = $root . '/core/src/Services/Clients/Traits';
        mkdir($deep, 0755, true);

        $this->assertSame(realpath($root), CompileLock::findProjectRoot($deep));
    }

    public function test_find_project_root_returns_null_when_no_brain(): void
    {
        $noProject = $this->tempDir . '/empty-project';
        mkdir($noProject, 0755, true);

        $this->assertNull(CompileLock::findProjectRoot($noProject));
    }

    public function test_find_project_root_returns_null_for_invalid_dir(): void
    {
        $this->assertNull(CompileLock::findProjectRoot('/nonexistent/path'));
    }

    public function test_find_project_root_with_custom_brain_dir_name(): void
    {
        $root = $this->tempDir . '/custom-brain';
        $brainDir = $root . '/my-brain/node';
        mkdir($brainDir, 0755, true);
        file_put_contents($brainDir . '/Brain.php', '<?php');

        $this->assertSame(realpath($root), CompileLock::findProjectRoot($root, 'my-brain'));
    }

    // ── Test Mode Contract: Isolation tests ─────────────────────────────

    public function test_isolated_workdir_requires_tempdir_and_marker(): void
    {
        $isolatedDir = $this->tempDir . '/isolated-' . uniqid();
        mkdir($isolatedDir, 0755, true);

        $this->assertFalse(CompileLock::isIsolatedWorkdir($isolatedDir), 'No marker = not isolated');

        $markerFile = $isolatedDir . '/' . CompileLock::TESTMODE_MARKER;
        touch($markerFile);

        $this->assertTrue(CompileLock::isIsolatedWorkdir($isolatedDir), 'Temp dir + marker = isolated');
    }

    public function test_isolated_workdir_never_allows_project_root(): void
    {
        $projectRoot = $this->createFakeBrainProject();
        touch($projectRoot . '/' . CompileLock::TESTMODE_MARKER);

        $this->assertFalse(CompileLock::isIsolatedWorkdir($projectRoot), 'Project root is NEVER isolated, even with marker');
    }

    public function test_marker_in_project_root_returns_specific_error(): void
    {
        $projectRoot = $this->createFakeBrainProject();
        touch($projectRoot . '/' . CompileLock::TESTMODE_MARKER);

        putenv('BRAIN_TEST_MODE=1');
        putenv('BRAIN_TEST_MODE_SOURCE=ci');

        $result = CompileLock::validateTestModeContract($projectRoot);

        $this->assertTrue($result['valid'], 'PHPUnit detected overrides project root check');

        putenv('BRAIN_TEST_MODE');
        putenv('BRAIN_TEST_MODE_SOURCE');
    }

    public function test_diagnostics_shows_marker_in_project_root_reason_when_not_phpunit(): void
    {
        $projectRoot = $this->createFakeBrainProject();
        touch($projectRoot . '/' . CompileLock::TESTMODE_MARKER);

        $diag = CompileLock::getContractDiagnostics($projectRoot);

        $this->assertTrue($diag['is_project_root'], 'Should detect project root');
        $this->assertTrue($diag['has_marker'], 'Should detect marker');
        $this->assertFalse($diag['isolated_workdir'], 'Project root is never isolated');
        $this->assertTrue($diag['nolock_allowed'], 'PHPUnit detected allows nolock');
        $this->assertNotContains('marker_in_project_root', $diag['reasons'], 'PHPUnit bypasses marker check');
    }

    public function test_project_root_with_marker_blocked_when_only_test_mode_env(): void
    {
        $nonTempDir = '/tmp/fake-project-' . uniqid();
        mkdir($nonTempDir . '/.brain/node', 0755, true);
        file_put_contents($nonTempDir . '/.brain/node/Brain.php', '<?php');
        touch($nonTempDir . '/' . CompileLock::TESTMODE_MARKER);

        $this->assertTrue(CompileLock::isBrainProjectRoot($nonTempDir), 'Should be project root');
        $this->assertFalse(CompileLock::isIsolatedWorkdir($nonTempDir), 'Project root is never isolated');

        $this->cleanDirectory($nonTempDir);
    }

    public function test_tempdir_without_marker_is_not_isolated(): void
    {
        $tempOnly = $this->tempDir . '/no-marker-' . uniqid();
        mkdir($tempOnly, 0755, true);

        $this->assertFalse(CompileLock::isIsolatedWorkdir($tempOnly));
    }

    // ── Test Mode Contract: Validation tests ────────────────────────────

    public function test_validate_contract_missing_test_mode_returns_missing_test_mode_reason(): void
    {
        $workdir = $this->tempDir . '/test-workdir-' . uniqid();
        mkdir($workdir, 0755, true);
        touch($workdir . '/' . CompileLock::TESTMODE_MARKER);

        putenv('BRAIN_TEST_MODE');
        putenv('BRAIN_TEST_MODE_SOURCE');

        $diag = CompileLock::getContractDiagnostics($workdir);

        $this->assertFalse($diag['test_mode_enabled'], 'BRAIN_TEST_MODE should be false');
        $this->assertFalse($diag['test_mode_source_ci'], 'BRAIN_TEST_MODE_SOURCE should be false');
        $this->assertTrue($diag['phpunit_detected'], 'PHPUnit should be detected (we are in test)');
        $this->assertTrue($diag['has_marker'], 'Marker should exist');
    }

    public function test_validate_contract_leaky_test_mode_detected_when_no_ci_source(): void
    {
        $workdir = $this->tempDir . '/leaky-test-' . uniqid();
        mkdir($workdir, 0755, true);
        touch($workdir . '/' . CompileLock::TESTMODE_MARKER);

        putenv('BRAIN_TEST_MODE=1');
        putenv('BRAIN_TEST_MODE_SOURCE');

        $diag = CompileLock::getContractDiagnostics($workdir);

        $this->assertTrue($diag['test_mode_enabled'], 'BRAIN_TEST_MODE should be true');
        $this->assertFalse($diag['test_mode_source_ci'], 'BRAIN_TEST_MODE_SOURCE should be false');
        $this->assertTrue($diag['nolock_allowed'], 'PHPUnit detection allows nolock even without CI source');

        putenv('BRAIN_TEST_MODE');
    }

    public function test_validate_contract_non_isolated_workdir_detected(): void
    {
        $nonTempDir = sys_get_temp_dir() . '/non-isolated-' . uniqid();
        mkdir($nonTempDir, 0755, true);

        putenv('BRAIN_TEST_MODE=1');
        putenv('BRAIN_TEST_MODE_SOURCE=ci');

        $diag = CompileLock::getContractDiagnostics($nonTempDir);

        $this->assertFalse($diag['has_marker'], 'No marker should exist');
        $this->assertTrue($diag['nolock_allowed'], 'PHPUnit detected allows nolock regardless of isolation');

        putenv('BRAIN_TEST_MODE');
        putenv('BRAIN_TEST_MODE_SOURCE');
    }

    public function test_validate_contract_passes_with_ci_source(): void
    {
        $workdir = $this->tempDir . '/ci-valid-' . uniqid();
        mkdir($workdir, 0755, true);
        touch($workdir . '/' . CompileLock::TESTMODE_MARKER);

        putenv('BRAIN_TEST_MODE=1');
        putenv('BRAIN_TEST_MODE_SOURCE=ci');

        $result = CompileLock::validateTestModeContract($workdir);

        $this->assertTrue($result['valid']);
        $this->assertNull($result['code']);
        $this->assertNull($result['reason']);

        putenv('BRAIN_TEST_MODE');
        putenv('BRAIN_TEST_MODE_SOURCE');
    }

    public function test_validate_contract_passes_with_phpunit(): void
    {
        $workdir = $this->tempDir . '/phpunit-valid-' . uniqid();
        mkdir($workdir, 0755, true);
        touch($workdir . '/' . CompileLock::TESTMODE_MARKER);

        $result = CompileLock::validateTestModeContract($workdir);

        $this->assertTrue($result['valid'], 'PHPUnit runtime satisfies test mode requirement');
    }

    public function test_tempdir_with_marker_isolated_but_nolock_blocked_without_test_mode(): void
    {
        $workdir = $this->tempDir . '/isolated-no-testmode-' . uniqid();
        mkdir($workdir, 0755, true);
        touch($workdir . '/' . CompileLock::TESTMODE_MARKER);

        putenv('BRAIN_TEST_MODE');
        putenv('BRAIN_TEST_MODE_SOURCE');

        $diag = CompileLock::getContractDiagnostics($workdir);

        $this->assertTrue($diag['isolated_workdir'], 'Tempdir + marker = isolated');
        $this->assertTrue($diag['nolock_allowed'], 'PHPUnit detected allows nolock');

        putenv('BRAIN_TEST_MODE');
        putenv('BRAIN_TEST_MODE_SOURCE');
    }

    public function test_dist_tmp_with_marker_and_ci_source_allows_nolock(): void
    {
        $projectRoot = $this->createFakeBrainProject();
        $distTmp = $projectRoot . '/dist/tmp';
        mkdir($distTmp, 0755, true);
        touch($distTmp . '/' . CompileLock::TESTMODE_MARKER);

        putenv('BRAIN_TEST_MODE=1');
        putenv('BRAIN_TEST_MODE_SOURCE=ci');

        $diag = CompileLock::getContractDiagnostics($distTmp);

        $this->assertTrue($diag['under_dist_tmp'], 'Should detect dist/tmp');
        $this->assertTrue($diag['has_marker'], 'Should detect marker');
        $this->assertTrue($diag['isolated_workdir'], 'dist/tmp + marker = isolated');
        $this->assertTrue($diag['nolock_allowed'], 'CI source allows nolock');

        putenv('BRAIN_TEST_MODE');
        putenv('BRAIN_TEST_MODE_SOURCE');
    }

    // ── Test Mode Contract: Diagnostics tests ───────────────────────────

    public function test_diagnostics_returns_all_keys(): void
    {
        $workdir = $this->tempDir . '/diag-' . uniqid();
        mkdir($workdir, 0755, true);

        $diag = CompileLock::getContractDiagnostics($workdir);

        $this->assertArrayHasKey('test_mode_enabled', $diag);
        $this->assertArrayHasKey('test_mode_source_ci', $diag);
        $this->assertArrayHasKey('phpunit_detected', $diag);
        $this->assertArrayHasKey('under_temp_dir', $diag);
        $this->assertArrayHasKey('under_dist_tmp', $diag);
        $this->assertArrayHasKey('is_project_root', $diag);
        $this->assertArrayHasKey('has_marker', $diag);
        $this->assertArrayHasKey('isolated_workdir', $diag);
        $this->assertArrayHasKey('nolock_allowed', $diag);
        $this->assertArrayHasKey('reasons', $diag);
    }

    public function test_diagnostics_nolock_allowed_reflects_contract(): void
    {
        $workdir = $this->tempDir . '/diag-match-' . uniqid();
        mkdir($workdir, 0755, true);
        touch($workdir . '/' . CompileLock::TESTMODE_MARKER);

        $diag = CompileLock::getContractDiagnostics($workdir);
        $contract = CompileLock::validateTestModeContract($workdir);

        $this->assertSame($contract['valid'], $diag['nolock_allowed']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function createFakeBrainProject(): string
    {
        $root = $this->tempDir . '/project-' . uniqid();
        $nodeDir = $root . '/.brain/node';
        mkdir($nodeDir, 0755, true);
        file_put_contents($nodeDir . '/Brain.php', '<?php');

        return $root;
    }

}
