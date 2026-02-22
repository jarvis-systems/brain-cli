<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Services\Compile;

use BrainCLI\Services\Compile\CompileDiff;
use BrainCLI\Tests\Support\CliOutputCapture;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CompileDiff pure logic methods.
 *
 * Uses real temp directories with controlled file content
 * to verify diff engine behavior deterministically.
 */
class CompileDiffTest extends TestCase
{
    use CliOutputCapture;

    private CompileDiff $differ;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->differ = new CompileDiff();
        $this->tempDir = $this->createTempDir();
    }

    protected function tearDown(): void
    {
        $this->cleanDirectory($this->tempDir);
    }

    // ─── compare() ──────────────────────────────────────────────────

    public function test_compare_identical_dirs_returns_no_differences(): void
    {
        $dirA = $this->tempDir . '/a';
        $dirB = $this->tempDir . '/b';
        mkdir($dirA, 0755, true);
        mkdir($dirB, 0755, true);

        file_put_contents($dirA . '/file.md', 'same content');
        file_put_contents($dirB . '/file.md', 'same content');

        $result = $this->differ->compare($dirA, $dirB);

        $this->assertSame(0, $result['summary']['added']);
        $this->assertSame(0, $result['summary']['changed']);
        $this->assertSame(0, $result['summary']['removed']);
        $this->assertSame(1, $result['summary']['unchanged']);
        $this->assertEmpty($result['files']);
    }

    public function test_compare_detects_added_file(): void
    {
        $dirA = $this->tempDir . '/a';
        $dirB = $this->tempDir . '/b';
        mkdir($dirA, 0755, true);
        mkdir($dirB, 0755, true);

        file_put_contents($dirB . '/new-file.md', 'new content');

        $result = $this->differ->compare($dirA, $dirB);

        $this->assertSame(1, $result['summary']['added']);
        $this->assertSame(0, $result['summary']['changed']);
        $this->assertSame(0, $result['summary']['removed']);
        $this->assertCount(1, $result['files']);
        $this->assertSame('added', $result['files'][0]['status']);
        $this->assertSame('new-file.md', $result['files'][0]['path']);
    }

    public function test_compare_detects_removed_file(): void
    {
        $dirA = $this->tempDir . '/a';
        $dirB = $this->tempDir . '/b';
        mkdir($dirA, 0755, true);
        mkdir($dirB, 0755, true);

        file_put_contents($dirA . '/old-file.md', 'old content');

        $result = $this->differ->compare($dirA, $dirB);

        $this->assertSame(0, $result['summary']['added']);
        $this->assertSame(0, $result['summary']['changed']);
        $this->assertSame(1, $result['summary']['removed']);
        $this->assertCount(1, $result['files']);
        $this->assertSame('removed', $result['files'][0]['status']);
    }

    public function test_compare_detects_changed_file(): void
    {
        $dirA = $this->tempDir . '/a';
        $dirB = $this->tempDir . '/b';
        mkdir($dirA, 0755, true);
        mkdir($dirB, 0755, true);

        file_put_contents($dirA . '/file.md', "line1\nline2\n");
        file_put_contents($dirB . '/file.md', "line1\nline2-modified\n");

        $result = $this->differ->compare($dirA, $dirB);

        $this->assertSame(0, $result['summary']['added']);
        $this->assertSame(1, $result['summary']['changed']);
        $this->assertSame(0, $result['summary']['removed']);
        $this->assertCount(1, $result['files']);
        $this->assertSame('changed', $result['files'][0]['status']);
        $this->assertArrayHasKey('diff', $result['files'][0]);
    }

    public function test_compare_with_relative_to_prefix(): void
    {
        $dirA = $this->tempDir . '/a';
        $dirB = $this->tempDir . '/b';
        mkdir($dirA, 0755, true);
        mkdir($dirB, 0755, true);

        file_put_contents($dirB . '/agent.md', 'content');

        $result = $this->differ->compare($dirA, $dirB, '.claude');

        $this->assertSame('.claude/agent.md', $result['files'][0]['path']);
    }

    public function test_compare_handles_subdirectories(): void
    {
        $dirA = $this->tempDir . '/a';
        $dirB = $this->tempDir . '/b';
        mkdir($dirA . '/sub', 0755, true);
        mkdir($dirB . '/sub', 0755, true);

        file_put_contents($dirA . '/sub/deep.md', 'old');
        file_put_contents($dirB . '/sub/deep.md', 'new');

        $result = $this->differ->compare($dirA, $dirB);

        $this->assertSame(1, $result['summary']['changed']);
        $this->assertStringContainsString('sub/', $result['files'][0]['path']);
    }

    public function test_compare_excludes_volatile_patterns(): void
    {
        $dirA = $this->tempDir . '/a';
        $dirB = $this->tempDir . '/b';
        mkdir($dirA, 0755, true);
        mkdir($dirB, 0755, true);

        file_put_contents($dirB . '/compile.lock', 'lock data');

        $result = $this->differ->compare($dirA, $dirB);

        $this->assertSame(0, $result['summary']['added']);
        $this->assertEmpty($result['files']);
    }

    // ─── isEmpty() ──────────────────────────────────────────────────

    public function test_is_empty_returns_true_for_no_differences(): void
    {
        $result = ['summary' => ['added' => 0, 'changed' => 0, 'removed' => 0, 'unchanged' => 5], 'files' => []];
        $this->assertTrue($this->differ->isEmpty($result));
    }

    public function test_is_empty_returns_false_when_changes_exist(): void
    {
        $result = ['summary' => ['added' => 1, 'changed' => 0, 'removed' => 0, 'unchanged' => 5], 'files' => []];
        $this->assertFalse($this->differ->isEmpty($result));
    }

    // ─── generateUnifiedDiff() via reflection ───────────────────────

    public function test_generate_unified_diff_produces_correct_markers(): void
    {
        $old = "line1\nline2\nline3";
        $new = "line1\nline2-changed\nline3";

        $result = $this->callGenerateUnifiedDiff($old, $new, 'test.md');

        $this->assertStringContainsString('--- a/test.md', $result['text']);
        $this->assertStringContainsString('+++ b/test.md', $result['text']);
        $this->assertGreaterThan(0, $result['added']);
        $this->assertGreaterThan(0, $result['removed']);
    }

    public function test_generate_unified_diff_identical_content_produces_no_markers(): void
    {
        $content = "line1\nline2\nline3";

        $result = $this->callGenerateUnifiedDiff($content, $content, 'test.md');

        $this->assertSame(0, $result['added']);
        $this->assertSame(0, $result['removed']);
    }

    // ─── Determinism ────────────────────────────────────────────────

    public function test_compare_is_deterministic_across_runs(): void
    {
        $dirA = $this->tempDir . '/a';
        $dirB = $this->tempDir . '/b';
        mkdir($dirA, 0755, true);
        mkdir($dirB, 0755, true);

        file_put_contents($dirA . '/file1.md', 'old1');
        file_put_contents($dirA . '/file2.md', 'old2');
        file_put_contents($dirB . '/file1.md', 'new1');
        file_put_contents($dirB . '/file2.md', 'old2');
        file_put_contents($dirB . '/file3.md', 'added');

        $run1 = $this->differ->compare($dirA, $dirB);
        $run2 = $this->differ->compare($dirA, $dirB);

        $this->assertSame($run1, $run2);
    }

    // ─── Helpers ────────────────────────────────────────────────────

    /**
     * @return array{text: string, added: int, removed: int, truncated: bool}
     */
    private function callGenerateUnifiedDiff(string $old, string $new, string $filename): array
    {
        $method = new \ReflectionMethod($this->differ, 'generateUnifiedDiff');

        /** @var array{text: string, added: int, removed: int, truncated: bool} */
        return $method->invoke($this->differ, $old, $new, $filename);
    }
}
