<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Services;

use BrainCLI\Services\CompileLock;
use PHPUnit\Framework\TestCase;

class CompileLockTest extends TestCase
{
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

    // ── Helpers ─────────────────────────────────────────────────────────

    private function createFakeBrainProject(): string
    {
        $root = $this->tempDir . '/project-' . uniqid();
        $nodeDir = $root . '/.brain/node';
        mkdir($nodeDir, 0755, true);
        file_put_contents($nodeDir . '/Brain.php', '<?php');

        return $root;
    }

    private function cleanDirectory(string $dir): void
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
                $this->cleanDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
