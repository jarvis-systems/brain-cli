<?php

declare(strict_types=1);

namespace BrainCLI\Services\Docs;

class DocsIndexCache
{
    public const VERSION = 1;

    public const CACHE_FILENAME = 'docs-index.json';

    /** @var array<string, array<string, mixed>> absolutePath → CacheEntry */
    private array $entries = [];

    private bool $dirty = false;

    private ?string $cacheDir = null;

    private int $hits = 0;

    private int $misses = 0;

    private int $pruned = 0;

    public function load(string $projectRoot): void
    {
        $this->cacheDir = rtrim($projectRoot, '/') . '/.work';
        $cacheFile = $this->cacheDir . '/' . self::CACHE_FILENAME;

        $this->entries = [];
        $this->dirty = false;
        $this->hits = 0;
        $this->misses = 0;
        $this->pruned = 0;

        if (! file_exists($cacheFile)) {
            return;
        }

        try {
            $raw = file_get_contents($cacheFile);
            if ($raw === false) {
                return;
            }

            $data = json_decode($raw, true);
            if (! is_array($data)) {
                return;
            }

            if (($data['version'] ?? null) !== self::VERSION) {
                return;
            }

            if (! is_array($data['entries'] ?? null)) {
                return;
            }

            $this->entries = $data['entries'];
        } catch (\Throwable) {
            $this->entries = [];
        }
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

        // Size same but mtime differs — check content hash
        $currentHash = substr(md5_file($absolutePath) ?: '', 0, 8);
        $cachedHash = $cached['content_hash'] ?? '';

        if ($currentHash === $cachedHash) {
            // Content unchanged, just update mtime in cache
            $this->entries[$absolutePath]['mtime'] = $currentMtime;
            $this->dirty = true;

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function store(string $absolutePath, array $metadata): void
    {
        $metadata['mtime'] = filemtime($absolutePath);
        $metadata['size'] = filesize($absolutePath);

        if (! isset($metadata['content_hash'])) {
            $metadata['content_hash'] = substr(md5_file($absolutePath) ?: '', 0, 8);
        }

        $this->entries[$absolutePath] = $metadata;
        $this->dirty = true;
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
            $this->dirty = true;
        }
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
}
