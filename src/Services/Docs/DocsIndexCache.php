<?php

declare(strict_types=1);

namespace BrainCLI\Services\Docs;

class DocsIndexCache
{
    public const VERSION = 2;

    public const CACHE_FILENAME = 'docs-index.json';

    public const MAX_ROLLING_STATS = 20;

    public const HEALTH_HEALTHY = 'healthy';

    public const HEALTH_STALE = 'stale';

    public const HEALTH_CORRUPT = 'corrupt';

    public const HEALTH_DISABLED = 'disabled';

    /** @var array<string, array<string, mixed>> absolutePath → CacheEntry */
    private array $entries = [];

    private bool $dirty = false;

    private ?string $cacheDir = null;

    private int $hits = 0;

    private int $misses = 0;

    private int $pruned = 0;

    private string $health = self::HEALTH_HEALTHY;

    /** @var array<int, array{timestamp: string, entries_total: int, entries_changed: int, entries_added: int, entries_removed: int, rebuild_ms: int, search_ms: int, hit: bool}> */
    private array $rollingStats = [];

    private ?string $lastBuildAt = null;

    private bool $cacheDisabled = false;

    private int $lastRebuildMs = 0;

    private int $lastSearchMs = 0;

    private int $entriesChanged = 0;

    private int $entriesAdded = 0;

    private int $entriesRemoved = 0;

    private bool $fullRebuild = false;

    public function load(string $projectRoot): void
    {
        $this->cacheDir = rtrim($projectRoot, '/') . '/.work';
        $cacheFile = $this->cacheDir . '/' . self::CACHE_FILENAME;

        $this->entries = [];
        $this->dirty = false;
        $this->hits = 0;
        $this->misses = 0;
        $this->pruned = 0;
        $this->rollingStats = [];
        $this->lastBuildAt = null;
        $this->health = self::HEALTH_HEALTHY;
        $this->lastRebuildMs = 0;
        $this->lastSearchMs = 0;
        $this->entriesChanged = 0;
        $this->entriesAdded = 0;
        $this->entriesRemoved = 0;
        $this->fullRebuild = false;

        if (! file_exists($cacheFile)) {
            $this->fullRebuild = true;

            return;
        }

        try {
            $raw = file_get_contents($cacheFile);
            if ($raw === false) {
                $this->health = self::HEALTH_CORRUPT;
                $this->fullRebuild = true;

                return;
            }

            $data = json_decode($raw, true);
            if (! is_array($data)) {
                $this->health = self::HEALTH_CORRUPT;
                $this->fullRebuild = true;

                return;
            }

            $version = $data['version'] ?? null;

            if ($version === 1) {
                $data = $this->migrateV1ToV2($data);
                $this->dirty = true;
            } elseif ($version !== self::VERSION) {
                $this->health = self::HEALTH_CORRUPT;
                $this->fullRebuild = true;

                return;
            }

            if (! is_array($data['entries'] ?? null)) {
                $this->health = self::HEALTH_CORRUPT;
                $this->fullRebuild = true;

                return;
            }

            $this->entries = $data['entries'];
            $this->lastBuildAt = $data['meta']['last_build_at'] ?? null;
            $this->rollingStats = $data['meta']['rolling_stats'] ?? [];

            if (empty($this->entries) && ! empty($data['entries'])) {
                $this->health = self::HEALTH_CORRUPT;
                $this->entries = [];
                $this->fullRebuild = true;
            }
        } catch (\Throwable) {
            $this->health = self::HEALTH_CORRUPT;
            $this->entries = [];
            $this->fullRebuild = true;
        }
    }

    /**
     * @param  array<string, mixed>  $v1Data
     * @return array<string, mixed>
     */
    private function migrateV1ToV2(array $v1Data): array
    {
        return [
            'version' => self::VERSION,
            'generated_at' => $v1Data['generated_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
            'entries' => $v1Data['entries'] ?? [],
            'meta' => [
                'schema_version' => 2,
                'last_build_at' => $v1Data['generated_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
                'rolling_stats' => [],
            ],
        ];
    }

    public function save(): void
    {
        if (! $this->dirty || $this->cacheDir === null) {
            return;
        }

        if (! is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        $data = [
            'version' => self::VERSION,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'entries' => $this->entries,
            'meta' => [
                'schema_version' => 2,
                'last_build_at' => $this->lastBuildAt ?? gmdate('Y-m-d\TH:i:s\Z'),
                'rolling_stats' => $this->rollingStats,
            ],
        ];

        file_put_contents(
            $this->cacheDir . '/' . self::CACHE_FILENAME,
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );

        $this->dirty = false;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lookup(string $absolutePath): ?array
    {
        if ($this->cacheDisabled) {
            $this->misses++;

            return null;
        }

        $entry = $this->entries[$absolutePath] ?? null;

        if ($entry !== null) {
            $this->hits++;
        } else {
            $this->misses++;
        }

        return $entry;
    }

    /**
     * @param  array<string, mixed>|null  $cached
     */
    public function isStale(string $absolutePath, ?array $cached): bool
    {
        if ($cached === null) {
            return true;
        }

        if (! file_exists($absolutePath)) {
            return true;
        }

        $currentMtime = filemtime($absolutePath);
        $currentSize = filesize($absolutePath);

        $cachedMtime = $cached['mtime'] ?? null;
        $cachedSize = $cached['size'] ?? null;

        if ($currentMtime === $cachedMtime && $currentSize === $cachedSize) {
            return false;
        }

        if ($currentSize !== $cachedSize) {
            return true;
        }

        $currentHash = substr(md5_file($absolutePath) ?: '', 0, 8);
        $cachedHash = $cached['content_hash'] ?? '';

        if ($currentHash === $cachedHash) {
            $this->entries[$absolutePath]['mtime'] = $currentMtime;
            $this->dirty = true;
            $this->entriesChanged++;

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function store(string $absolutePath, array $metadata): void
    {
        $isNew = ! isset($this->entries[$absolutePath]);

        $metadata['mtime'] = filemtime($absolutePath);
        $metadata['size'] = filesize($absolutePath);

        if (! isset($metadata['content_hash'])) {
            $metadata['content_hash'] = substr(md5_file($absolutePath) ?: '', 0, 8);
        }

        $this->entries[$absolutePath] = $metadata;
        $this->dirty = true;

        if ($isNew) {
            $this->entriesAdded++;
        } else {
            $this->entriesChanged++;
        }
    }

    /**
     * @param  array<int, string>  $activeFiles
     */
    public function prune(array $activeFiles): void
    {
        $activeSet = array_flip($activeFiles);
        $toRemove = [];

        foreach ($this->entries as $path => $entry) {
            if (! isset($activeSet[$path])) {
                $toRemove[] = $path;
            }
        }

        foreach ($toRemove as $path) {
            unset($this->entries[$path]);
        }

        if (! empty($toRemove)) {
            $this->pruned += count($toRemove);
            $this->entriesRemoved += count($toRemove);
            $this->dirty = true;
        }
    }

    public function setDisabled(bool $disabled): void
    {
        $this->cacheDisabled = $disabled;
        if ($disabled) {
            $this->health = self::HEALTH_DISABLED;
        }
    }

    public function isDisabled(): bool
    {
        return $this->cacheDisabled;
    }

    public function setRebuildTime(int $ms): void
    {
        $this->lastRebuildMs = $ms;
    }

    public function setSearchTime(int $ms): void
    {
        $this->lastSearchMs = $ms;
    }

    public function recordSearchRun(bool $hit): void
    {
        $record = [
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'entries_total' => count($this->entries),
            'entries_changed' => $this->entriesChanged,
            'entries_added' => $this->entriesAdded,
            'entries_removed' => $this->entriesRemoved,
            'rebuild_ms' => $this->lastRebuildMs,
            'search_ms' => $this->lastSearchMs,
            'hit' => $hit,
        ];

        $this->rollingStats[] = $record;

        if (count($this->rollingStats) > self::MAX_ROLLING_STATS) {
            $this->rollingStats = array_slice($this->rollingStats, -self::MAX_ROLLING_STATS);
        }

        $this->lastBuildAt = $record['timestamp'];
        $this->dirty = true;

        $this->entriesChanged = 0;
        $this->entriesAdded = 0;
        $this->entriesRemoved = 0;
        $this->lastRebuildMs = 0;
        $this->lastSearchMs = 0;
    }

    public function getHealth(): string
    {
        return $this->health;
    }

    public function recoverFromCorruption(): void
    {
        $this->entries = [];
        $this->rollingStats = [];
        $this->health = self::HEALTH_HEALTHY;
        $this->dirty = true;
        $this->fullRebuild = true;
    }

    public function isFullRebuild(): bool
    {
        return $this->fullRebuild;
    }

    /**
     * @return array{total: int, hits: int, misses: int, pruned: int}
     */
    public function getStats(): array
    {
        return [
            'total' => count($this->entries),
            'hits' => $this->hits,
            'misses' => $this->misses,
            'pruned' => $this->pruned,
        ];
    }

    /**
     * @return array{cache_hit: bool, entries_total: int, entries_changed: int, entries_added: int, entries_removed: int, rebuild_ms: int, search_ms: int, hit_rate: float, health: string, last_build_at: string|null}
     */
    public function getDetailedStats(): array
    {
        $totalRuns = count($this->rollingStats);
        $hitRuns = 0;

        foreach ($this->rollingStats as $run) {
            if ($run['hit'] ?? false) {
                $hitRuns++;
            }
        }

        $hitRate = $totalRuns > 0 ? round($hitRuns / $totalRuns, 2) : 0.0;

        $lastRun = ! empty($this->rollingStats) ? end($this->rollingStats) : null;

        return [
            'cache_hit' => $this->hits > 0 && $this->misses === 0 && ! $this->fullRebuild,
            'entries_total' => count($this->entries),
            'entries_changed' => $lastRun['entries_changed'] ?? 0,
            'entries_added' => $lastRun['entries_added'] ?? 0,
            'entries_removed' => $lastRun['entries_removed'] ?? 0,
            'rebuild_ms' => $lastRun['rebuild_ms'] ?? 0,
            'search_ms' => $lastRun['search_ms'] ?? 0,
            'hit_rate' => $hitRate,
            'health' => $this->health,
            'last_build_at' => $this->lastBuildAt,
        ];
    }

    /**
     * @return array{status: string, entries: int, last_build: string|null, avg_rebuild_ms: float, avg_search_ms: float, hit_rate: float, determinism_check: bool, recommendations: array<int, string>}
     */
    public function getHealthReport(): array
    {
        $totalRuns = count($this->rollingStats);
        $hitRuns = 0;
        $totalRebuildMs = 0;
        $totalSearchMs = 0;

        foreach ($this->rollingStats as $run) {
            if ($run['hit'] ?? false) {
                $hitRuns++;
            }
            $totalRebuildMs += $run['rebuild_ms'] ?? 0;
            $totalSearchMs += $run['search_ms'] ?? 0;
        }

        $avgRebuildMs = $totalRuns > 0 ? round($totalRebuildMs / $totalRuns, 1) : 0.0;
        $avgSearchMs = $totalRuns > 0 ? round($totalSearchMs / $totalRuns, 1) : 0.0;
        $hitRate = $totalRuns > 0 ? round($hitRuns / $totalRuns, 2) : 0.0;

        $recommendations = [];

        if ($avgSearchMs > 100) {
            $recommendations[] = 'Average search time exceeds 100ms target. Consider clearing cache.';
        }

        if ($hitRate < 0.95 && $totalRuns >= 5) {
            $recommendations[] = 'Hit rate below 95%. Check for frequent file modifications.';
        }

        if ($this->health === self::HEALTH_CORRUPT) {
            $recommendations[] = 'Cache was corrupted and recovered. Verify search results.';
        }

        $determinismCheck = $this->checkDeterminism();

        if (! $determinismCheck) {
            $recommendations[] = 'Determinism check failed. Same queries may produce different ordering.';
        }

        return [
            'status' => $this->health,
            'entries' => count($this->entries),
            'last_build' => $this->lastBuildAt,
            'avg_rebuild_ms' => $avgRebuildMs,
            'avg_search_ms' => $avgSearchMs,
            'hit_rate' => $hitRate,
            'determinism_check' => $determinismCheck,
            'recommendations' => $recommendations,
        ];
    }

    private function checkDeterminism(): bool
    {
        if (count($this->entries) < 2) {
            return true;
        }

        $paths = array_keys($this->entries);
        $sorted = $paths;
        sort($sorted);

        return $paths === $sorted;
    }

    public function clear(): void
    {
        $this->entries = [];
        $this->rollingStats = [];
        $this->health = self::HEALTH_HEALTHY;
        $this->dirty = true;
        $this->fullRebuild = true;
        $this->lastBuildAt = null;
    }
}
