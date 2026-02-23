<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Services\Docs;

use BrainCLI\Services\Docs\DocsIndexCache;
use PHPUnit\Framework\TestCase;

class DocsIndexCacheTest extends TestCase
{
    private string $tempDir;

    private DocsIndexCache $cache;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/docs-cache-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->cache = new DocsIndexCache();
    }

    protected function tearDown(): void
    {
        $this->cleanDirectory($this->tempDir);
    }

    // --- load() tests ---

    public function test_load_creates_empty_state_when_no_cache_file(): void
    {
        $this->cache->load($this->tempDir);

        $stats = $this->cache->getStats();
        $this->assertSame(0, $stats['total']);
    }

    public function test_load_creates_empty_state_when_corrupt_json(): void
    {
        $workDir = $this->tempDir . '/.work';
        mkdir($workDir, 0755, true);
        file_put_contents($workDir . '/docs-index.json', 'not valid json{{{');

        $this->cache->load($this->tempDir);

        $stats = $this->cache->getStats();
        $this->assertSame(0, $stats['total']);
    }

    public function test_load_creates_empty_state_when_wrong_version(): void
    {
        $workDir = $this->tempDir . '/.work';
        mkdir($workDir, 0755, true);
        file_put_contents($workDir . '/docs-index.json', json_encode([
            'version' => 999,
            'entries' => ['/some/path' => ['yaml' => []]],
        ]));

        $this->cache->load($this->tempDir);

        $stats = $this->cache->getStats();
        $this->assertSame(0, $stats['total']);
    }

    public function test_load_restores_entries_from_valid_cache(): void
    {
        $workDir = $this->tempDir . '/.work';
        mkdir($workDir, 0755, true);
        file_put_contents($workDir . '/docs-index.json', json_encode([
            'version' => DocsIndexCache::VERSION,
            'generated_at' => '2026-01-01T00:00:00Z',
            'entries' => [
                '/path/to/file.md' => [
                    'mtime' => 1000000,
                    'size' => 100,
                    'content_hash' => 'abcd1234',
                    'yaml' => ['name' => 'Test'],
                ],
            ],
        ]));

        $this->cache->load($this->tempDir);

        $stats = $this->cache->getStats();
        $this->assertSame(1, $stats['total']);
    }

    // --- save() tests ---

    public function test_save_creates_work_directory_if_missing(): void
    {
        $subDir = $this->tempDir . '/subproject';
        mkdir($subDir, 0755, true);
        $this->cache->load($subDir);

        // Create a file so we can store something
        $filePath = $subDir . '/test.md';
        file_put_contents($filePath, '# Test');

        $this->cache->store($filePath, ['yaml' => ['name' => 'Test']]);
        $this->cache->save();

        $this->assertDirectoryExists($subDir . '/.work');
        $this->assertFileExists($subDir . '/.work/docs-index.json');
    }

    public function test_save_writes_valid_json_with_version(): void
    {
        $this->cache->load($this->tempDir);

        $filePath = $this->tempDir . '/test.md';
        file_put_contents($filePath, '# Test');

        $this->cache->store($filePath, ['yaml' => ['name' => 'Test']]);
        $this->cache->save();

        $cacheFile = $this->tempDir . '/.work/docs-index.json';
        $data = json_decode(file_get_contents($cacheFile), true);

        $this->assertSame(DocsIndexCache::VERSION, $data['version']);
        $this->assertArrayHasKey('entries', $data);
    }

    public function test_save_skipped_when_not_dirty(): void
    {
        $workDir = $this->tempDir . '/.work';
        mkdir($workDir, 0755, true);
        $cacheFile = $workDir . '/docs-index.json';
        file_put_contents($cacheFile, json_encode([
            'version' => DocsIndexCache::VERSION,
            'entries' => [],
        ]));

        $this->cache->load($this->tempDir);
        $mtimeBefore = filemtime($cacheFile);

        // Sleep to ensure mtime would differ if file were written
        usleep(10000);
        $this->cache->save();

        clearstatcache();
        $this->assertSame($mtimeBefore, filemtime($cacheFile));
    }

    public function test_save_includes_generated_at_timestamp(): void
    {
        $this->cache->load($this->tempDir);

        $filePath = $this->tempDir . '/test.md';
        file_put_contents($filePath, '# Test');

        $this->cache->store($filePath, ['yaml' => []]);
        $this->cache->save();

        $data = json_decode(file_get_contents($this->tempDir . '/.work/docs-index.json'), true);
        $this->assertArrayHasKey('generated_at', $data);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $data['generated_at']);
    }

    // --- lookup() tests ---

    public function test_lookup_returns_null_for_unknown_path(): void
    {
        $this->cache->load($this->tempDir);

        $result = $this->cache->lookup('/nonexistent/path.md');

        $this->assertNull($result);
    }

    public function test_lookup_returns_cached_entry(): void
    {
        $this->cache->load($this->tempDir);

        $filePath = $this->tempDir . '/test.md';
        file_put_contents($filePath, '# Hello');

        $this->cache->store($filePath, [
            'yaml' => ['name' => 'Hello'],
            'source' => 'local',
        ]);

        $entry = $this->cache->lookup($filePath);

        $this->assertNotNull($entry);
        $this->assertSame(['name' => 'Hello'], $entry['yaml']);
        $this->assertSame('local', $entry['source']);
    }

    // --- isStale() tests ---

    public function test_is_stale_true_for_null_entry(): void
    {
        $this->assertTrue($this->cache->isStale('/any/path.md', null));
    }

    public function test_is_stale_false_when_mtime_and_size_match(): void
    {
        $filePath = $this->tempDir . '/test.md';
        file_put_contents($filePath, '# Test content');

        $cached = [
            'mtime' => filemtime($filePath),
            'size' => filesize($filePath),
            'content_hash' => substr(md5_file($filePath) ?: '', 0, 8),
        ];

        $this->assertFalse($this->cache->isStale($filePath, $cached));
    }

    public function test_is_stale_true_when_size_differs(): void
    {
        $filePath = $this->tempDir . '/test.md';
        file_put_contents($filePath, '# New longer content here');

        $cached = [
            'mtime' => filemtime($filePath),
            'size' => 5, // different size
            'content_hash' => 'xxxxxxxx',
        ];

        $this->assertTrue($this->cache->isStale($filePath, $cached));
    }

    public function test_is_stale_true_when_mtime_differs_and_hash_differs(): void
    {
        $filePath = $this->tempDir . '/test.md';
        file_put_contents($filePath, '# Changed content');

        $cached = [
            'mtime' => filemtime($filePath) - 100,
            'size' => filesize($filePath),
            'content_hash' => 'different',
        ];

        $this->assertTrue($this->cache->isStale($filePath, $cached));
    }

    public function test_is_stale_false_when_mtime_differs_but_hash_same(): void
    {
        $this->cache->load($this->tempDir);

        $filePath = $this->tempDir . '/test.md';
        file_put_contents($filePath, '# Same content');

        $currentHash = substr(md5_file($filePath) ?: '', 0, 8);

        // Store with old mtime but same hash
        $cached = [
            'mtime' => filemtime($filePath) - 100,
            'size' => filesize($filePath),
            'content_hash' => $currentHash,
        ];

        $this->assertFalse($this->cache->isStale($filePath, $cached));
    }

    // --- store() tests ---

    public function test_store_marks_dirty(): void
    {
        $this->cache->load($this->tempDir);

        $filePath = $this->tempDir . '/test.md';
        file_put_contents($filePath, '# Test');

        $this->cache->store($filePath, ['yaml' => []]);

        // Verify dirty by checking that save actually writes
        $this->cache->save();
        $this->assertFileExists($this->tempDir . '/.work/docs-index.json');
    }

    public function test_store_overwrites_existing_entry(): void
    {
        $this->cache->load($this->tempDir);

        $filePath = $this->tempDir . '/test.md';
        file_put_contents($filePath, '# First');

        $this->cache->store($filePath, ['yaml' => ['name' => 'First']]);
        $this->cache->store($filePath, ['yaml' => ['name' => 'Second']]);

        $entry = $this->cache->lookup($filePath);
        $this->assertSame(['name' => 'Second'], $entry['yaml']);

        $stats = $this->cache->getStats();
        $this->assertSame(1, $stats['total']);
    }

    // --- prune() tests ---

    public function test_prune_removes_absent_files(): void
    {
        $this->cache->load($this->tempDir);

        $filePath1 = $this->tempDir . '/keep.md';
        $filePath2 = $this->tempDir . '/remove.md';
        file_put_contents($filePath1, '# Keep');
        file_put_contents($filePath2, '# Remove');

        $this->cache->store($filePath1, ['yaml' => []]);
        $this->cache->store($filePath2, ['yaml' => []]);

        $this->cache->prune([$filePath1]);

        $this->assertNotNull($this->cache->lookup($filePath1));
        // lookup for removed path should return null (but increments misses for second call)
        $cache2 = new DocsIndexCache();
        $cache2->load($this->tempDir);

        // Save first to persist prune, then reload
        $this->cache->save();
        $cache2->load($this->tempDir);
        $this->assertNull($cache2->lookup($filePath2));
    }

    public function test_prune_keeps_active_files(): void
    {
        $this->cache->load($this->tempDir);

        $filePath = $this->tempDir . '/active.md';
        file_put_contents($filePath, '# Active');

        $this->cache->store($filePath, ['yaml' => ['name' => 'Active']]);
        $this->cache->prune([$filePath]);

        $entry = $this->cache->lookup($filePath);
        $this->assertNotNull($entry);
        $this->assertSame(['name' => 'Active'], $entry['yaml']);
    }

    public function test_prune_not_dirty_when_nothing_removed(): void
    {
        $workDir = $this->tempDir . '/.work';
        mkdir($workDir, 0755, true);

        $filePath = $this->tempDir . '/test.md';
        file_put_contents($filePath, '# Test');

        file_put_contents($workDir . '/docs-index.json', json_encode([
            'version' => DocsIndexCache::VERSION,
            'entries' => [
                $filePath => [
                    'mtime' => filemtime($filePath),
                    'size' => filesize($filePath),
                    'content_hash' => 'abcd1234',
                    'yaml' => [],
                ],
            ],
        ]));

        $this->cache->load($this->tempDir);

        $mtimeBefore = filemtime($workDir . '/docs-index.json');
        usleep(10000);

        $this->cache->prune([$filePath]);
        $this->cache->save();

        clearstatcache();
        $this->assertSame($mtimeBefore, filemtime($workDir . '/docs-index.json'));
    }

    // --- getStats() tests ---

    public function test_get_stats_returns_counters(): void
    {
        $this->cache->load($this->tempDir);

        $filePath = $this->tempDir . '/test.md';
        file_put_contents($filePath, '# Test');
        $this->cache->store($filePath, ['yaml' => []]);

        // 1 hit
        $this->cache->lookup($filePath);
        // 1 miss
        $this->cache->lookup('/nonexistent');

        $stats = $this->cache->getStats();

        $this->assertSame(1, $stats['total']);
        $this->assertSame(1, $stats['hits']);
        $this->assertSame(1, $stats['misses']);
        $this->assertSame(0, $stats['pruned']);
    }

    // --- full cycle test ---

    public function test_full_cycle_load_store_save_reload(): void
    {
        // Phase 1: Create files and populate cache
        $this->cache->load($this->tempDir);

        $file1 = $this->tempDir . '/doc1.md';
        $file2 = $this->tempDir . '/doc2.md';
        file_put_contents($file1, "---\nname: Doc1\ndescription: First doc\n---\n# Doc1");
        file_put_contents($file2, "---\nname: Doc2\ndescription: Second doc\n---\n# Doc2");

        $this->cache->store($file1, [
            'yaml' => ['name' => 'Doc1', 'description' => 'First doc'],
            'auto_name' => null,
            'auto_description' => null,
            'source' => 'local',
            'trust' => ['level' => 'high', 'reason' => 'Local project documentation'],
            'content_hash' => substr(md5_file($file1) ?: '', 0, 8),
        ]);

        $this->cache->store($file2, [
            'yaml' => ['name' => 'Doc2', 'description' => 'Second doc'],
            'auto_name' => null,
            'auto_description' => null,
            'source' => 'local',
            'trust' => ['level' => 'high', 'reason' => 'Local project documentation'],
            'content_hash' => substr(md5_file($file2) ?: '', 0, 8),
        ]);

        $this->cache->save();

        // Phase 2: Reload from disk
        $cache2 = new DocsIndexCache();
        $cache2->load($this->tempDir);

        $stats = $cache2->getStats();
        $this->assertSame(2, $stats['total']);

        // Verify entries survived serialization
        $entry1 = $cache2->lookup($file1);
        $this->assertNotNull($entry1);
        $this->assertSame('Doc1', $entry1['yaml']['name']);
        $this->assertSame('local', $entry1['source']);

        $entry2 = $cache2->lookup($file2);
        $this->assertNotNull($entry2);
        $this->assertSame('Doc2', $entry2['yaml']['name']);

        // Verify isStale returns false for unchanged files
        $this->assertFalse($cache2->isStale($file1, $entry1));
        $this->assertFalse($cache2->isStale($file2, $entry2));

        // Phase 3: Modify one file, verify staleness
        file_put_contents($file1, "---\nname: Doc1 Updated\n---\n# Doc1 Updated Content");
        clearstatcache();

        $this->assertTrue($cache2->isStale($file1, $entry1));
        $this->assertFalse($cache2->isStale($file2, $entry2));
    }

    // --- helpers ---

    private function cleanDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
