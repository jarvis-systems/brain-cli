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

    // ─── compare() hash fields ────────────────────────────────────

    public function test_compare_added_file_has_hash_after_only(): void
    {
        $dirA = $this->tempDir . '/a';
        $dirB = $this->tempDir . '/b';
        mkdir($dirA, 0755, true);
        mkdir($dirB, 0755, true);

        file_put_contents($dirB . '/new.md', 'content');

        $result = $this->differ->compare($dirA, $dirB);

        $this->assertNull($result['files'][0]['hash_before']);
        $this->assertNotNull($result['files'][0]['hash_after']);
        $this->assertSame(12, strlen($result['files'][0]['hash_after']));
    }

    public function test_compare_changed_file_has_both_hashes(): void
    {
        $dirA = $this->tempDir . '/a';
        $dirB = $this->tempDir . '/b';
        mkdir($dirA, 0755, true);
        mkdir($dirB, 0755, true);

        file_put_contents($dirA . '/file.md', 'old');
        file_put_contents($dirB . '/file.md', 'new');

        $result = $this->differ->compare($dirA, $dirB);

        $this->assertNotNull($result['files'][0]['hash_before']);
        $this->assertNotNull($result['files'][0]['hash_after']);
        $this->assertNotSame($result['files'][0]['hash_before'], $result['files'][0]['hash_after']);
    }

    // ─── toJsonSchema() ─────────────────────────────────────────────

    public function test_to_json_schema_no_diff_returns_status_no_diff(): void
    {
        $diff = [
            'summary' => ['added' => 0, 'changed' => 0, 'removed' => 0, 'unchanged' => 5],
            'files' => [],
        ];

        $schema = $this->differ->toJsonSchema($diff);

        $this->assertSame('no_diff', $schema['status']);
        $this->assertSame(0, $schema['exit_code']);
        $this->assertSame(0, $schema['added']);
        $this->assertSame(0, $schema['changed']);
        $this->assertSame(0, $schema['removed']);
        $this->assertSame(5, $schema['unchanged']);
        $this->assertEmpty($schema['files']);
    }

    public function test_to_json_schema_with_diff_returns_status_diff(): void
    {
        $diff = [
            'summary' => ['added' => 1, 'changed' => 1, 'removed' => 0, 'unchanged' => 3],
            'files' => [
                [
                    'path' => '.claude/agents/test.md',
                    'status' => 'added',
                    'hash_before' => null,
                    'hash_after' => 'abc123def456',
                    'lines_added' => 10,
                    'lines_removed' => 0,
                ],
                [
                    'path' => '.claude/CLAUDE.md',
                    'status' => 'changed',
                    'hash_before' => '111222333444',
                    'hash_after' => '555666777888',
                    'diff' => '--- a/CLAUDE.md\n+++ b/CLAUDE.md',
                    'lines_added' => 3,
                    'lines_removed' => 2,
                    'truncated' => false,
                ],
            ],
        ];

        $schema = $this->differ->toJsonSchema($diff);

        $this->assertSame('diff', $schema['status']);
        $this->assertSame(2, $schema['exit_code']);
        $this->assertSame(1, $schema['added']);
        $this->assertSame(1, $schema['changed']);
        $this->assertCount(2, $schema['files']);

        // Verify diff text is stripped from JSON schema
        $this->assertArrayNotHasKey('diff', $schema['files'][0]);
        $this->assertArrayNotHasKey('diff', $schema['files'][1]);
        $this->assertArrayNotHasKey('truncated', $schema['files'][1]);

        // Verify hashes preserved
        $this->assertNull($schema['files'][0]['hash_before']);
        $this->assertSame('abc123def456', $schema['files'][0]['hash_after']);
        $this->assertSame('111222333444', $schema['files'][1]['hash_before']);
    }

    public function test_to_json_schema_files_have_stable_ordering(): void
    {
        $diff = [
            'summary' => ['added' => 0, 'changed' => 2, 'removed' => 0, 'unchanged' => 0],
            'files' => [
                ['path' => 'a.md', 'status' => 'changed', 'hash_before' => 'aaa', 'hash_after' => 'bbb', 'lines_added' => 1, 'lines_removed' => 1],
                ['path' => 'z.md', 'status' => 'changed', 'hash_before' => 'ccc', 'hash_after' => 'ddd', 'lines_added' => 2, 'lines_removed' => 0],
            ],
        ];

        $schema1 = $this->differ->toJsonSchema($diff);
        $schema2 = $this->differ->toJsonSchema($diff);

        // Stable ordering across calls
        $this->assertSame($schema1, $schema2);
        $this->assertSame('a.md', $schema1['files'][0]['path']);
        $this->assertSame('z.md', $schema1['files'][1]['path']);
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
