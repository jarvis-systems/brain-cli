<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Services\Docs;

use BrainCLI\Services\Docs\DocsDirectoryResolver;
use BrainCLI\Tests\Support\CliOutputCapture;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DocsDirectoryResolver — .docs/ directory discovery.
 *
 * Verifies default mode (root .docs/ only) and global mode
 * (recursive discovery at depth 1-3 with exclusions).
 */
class DocsDirectoryResolverTest extends TestCase
{
    use CliOutputCapture;

    protected DocsDirectoryResolver $resolver;

    protected string $tmpDir;

    protected function setUp(): void
    {
        $this->resolver = new DocsDirectoryResolver();
        $this->tmpDir = sys_get_temp_dir() . '/docs_resolver_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->cleanDirectory($this->tmpDir);
    }

    public function test_resolve_default_returns_root_docs_only(): void
    {
        mkdir($this->tmpDir . '/.docs', 0755, true);

        $result = $this->resolver->resolve(false, $this->tmpDir);

        $this->assertCount(1, $result);
        $this->assertSame('.docs', $result[0]['prefix']);
        $this->assertSame($this->tmpDir . DS . '.docs', $result[0]['dir']);
    }

    public function test_resolve_default_returns_empty_when_no_docs_dir(): void
    {
        $result = $this->resolver->resolve(false, $this->tmpDir);

        $this->assertCount(0, $result);
    }

    public function test_resolve_global_finds_nested_docs_dirs(): void
    {
        mkdir($this->tmpDir . '/.docs', 0755, true);
        mkdir($this->tmpDir . '/frontend/.docs', 0755, true);
        mkdir($this->tmpDir . '/backend/.docs', 0755, true);

        $result = $this->resolver->resolve(true, $this->tmpDir);

        $this->assertCount(3, $result);

        $prefixes = array_column($result, 'prefix');
        $this->assertContains('.docs', $prefixes);
        $this->assertContains('backend/.docs', $prefixes);
        $this->assertContains('frontend/.docs', $prefixes);
    }

    public function test_resolve_global_excludes_vendor(): void
    {
        mkdir($this->tmpDir . '/.docs', 0755, true);
        mkdir($this->tmpDir . '/vendor/package/.docs', 0755, true);

        $result = $this->resolver->resolve(true, $this->tmpDir);

        $this->assertCount(1, $result);
        $this->assertSame('.docs', $result[0]['prefix']);
    }

    public function test_resolve_global_excludes_node_modules(): void
    {
        mkdir($this->tmpDir . '/.docs', 0755, true);
        mkdir($this->tmpDir . '/node_modules/pkg/.docs', 0755, true);

        $result = $this->resolver->resolve(true, $this->tmpDir);

        $this->assertCount(1, $result);
        $this->assertSame('.docs', $result[0]['prefix']);
    }

    public function test_resolve_global_excludes_dot_git(): void
    {
        mkdir($this->tmpDir . '/.docs', 0755, true);
        mkdir($this->tmpDir . '/.git/hooks/.docs', 0755, true);

        $result = $this->resolver->resolve(true, $this->tmpDir);

        $this->assertCount(1, $result);
        $this->assertSame('.docs', $result[0]['prefix']);
    }

    public function test_resolve_global_respects_depth_limit(): void
    {
        mkdir($this->tmpDir . '/.docs', 0755, true);
        // Depth 2 — should be found
        mkdir($this->tmpDir . '/packages/auth/.docs', 0755, true);
        // Depth 3 — should be found
        mkdir($this->tmpDir . '/packages/auth/sub/.docs', 0755, true);
        // Depth 4 — should NOT be found (exceeds max depth 3)
        mkdir($this->tmpDir . '/packages/auth/sub/deep/.docs', 0755, true);

        $result = $this->resolver->resolve(true, $this->tmpDir);

        $prefixes = array_column($result, 'prefix');
        $this->assertContains('.docs', $prefixes);
        $this->assertContains('packages/auth/.docs', $prefixes);
        $this->assertContains('packages/auth/sub/.docs', $prefixes);
        $this->assertNotContains('packages/auth/sub/deep/.docs', $prefixes);
    }

    public function test_resolve_global_includes_root_docs_first(): void
    {
        mkdir($this->tmpDir . '/.docs', 0755, true);
        mkdir($this->tmpDir . '/alpha/.docs', 0755, true);

        $result = $this->resolver->resolve(true, $this->tmpDir);

        $this->assertCount(2, $result);
        $this->assertSame('.docs', $result[0]['prefix']);
    }

    public function test_resolve_global_sorted_by_prefix(): void
    {
        mkdir($this->tmpDir . '/.docs', 0755, true);
        mkdir($this->tmpDir . '/zebra/.docs', 0755, true);
        mkdir($this->tmpDir . '/alpha/.docs', 0755, true);
        mkdir($this->tmpDir . '/middle/.docs', 0755, true);

        $result = $this->resolver->resolve(true, $this->tmpDir);

        $prefixes = array_column($result, 'prefix');
        $this->assertSame(['.docs', 'alpha/.docs', 'middle/.docs', 'zebra/.docs'], $prefixes);
    }

    public function test_resolve_global_without_root_docs_still_finds_subdirs(): void
    {
        // Root .docs/ does NOT exist
        mkdir($this->tmpDir . '/subproject/.docs', 0755, true);

        $result = $this->resolver->resolve(true, $this->tmpDir);

        $this->assertCount(1, $result);
        $this->assertSame('subproject/.docs', $result[0]['prefix']);
    }

    public function test_resolve_global_returns_empty_when_no_docs_anywhere(): void
    {
        mkdir($this->tmpDir . '/src', 0755, true);
        mkdir($this->tmpDir . '/app', 0755, true);

        $result = $this->resolver->resolve(true, $this->tmpDir);

        $this->assertCount(0, $result);
    }

    public function test_resolve_global_excludes_all_package_dirs(): void
    {
        mkdir($this->tmpDir . '/.docs', 0755, true);
        mkdir($this->tmpDir . '/vendor/pkg/.docs', 0755, true);
        mkdir($this->tmpDir . '/node_modules/pkg/.docs', 0755, true);
        mkdir($this->tmpDir . '/.idea/.docs', 0755, true);
        mkdir($this->tmpDir . '/storage/app/.docs', 0755, true);
        mkdir($this->tmpDir . '/dist/.docs', 0755, true);
        mkdir($this->tmpDir . '/build/.docs', 0755, true);

        $result = $this->resolver->resolve(true, $this->tmpDir);

        $this->assertCount(1, $result);
        $this->assertSame('.docs', $result[0]['prefix']);
    }

    public function test_resolve_global_dir_values_are_absolute_paths(): void
    {
        mkdir($this->tmpDir . '/.docs', 0755, true);
        mkdir($this->tmpDir . '/sub/.docs', 0755, true);

        $result = $this->resolver->resolve(true, $this->tmpDir);

        foreach ($result as $entry) {
            $this->assertTrue(
                is_dir($entry['dir']),
                "Directory '{$entry['dir']}' should exist",
            );
            $this->assertStringStartsWith('/', $entry['dir']);
        }
    }
}
