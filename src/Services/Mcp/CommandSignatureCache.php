<?php

declare(strict_types=1);

namespace BrainCLI\Services\Mcp;

/**
 * Deterministic cache for extracted command signatures.
 *
 * Location:
 * - BRAIN_TEST_MODE=1 → dist/tmp/mcp-signatures.json
 * - else → memory/mcp-signatures.json
 *
 * Key: command class name + file mtime
 * Value: extracted options/arguments + types (stable-sorted JSON)
 */
final class CommandSignatureCache
{
    private const TEST_MODE_ENV = 'BRAIN_TEST_MODE';
    private const CACHE_FILENAME = 'mcp-signatures.json';

    private static ?array $cacheData = null;
    private static ?string $cachePath = null;
    private static bool $dirty = false;

    /**
     * Get cached signature data if valid.
     *
     * @param  class-string  $commandClass
     * @return array{options: array<string, array{type: string, has_default: bool, default?: mixed}>, arguments: array<string, array{type: string, has_default: bool, default?: mixed}>}|null
     */
    public static function get(string $commandClass, int $mtime): ?array
    {
        $key = self::makeKey($commandClass, $mtime);
        $data = self::loadCache();

        if (! isset($data[$key])) {
            return null;
        }

        $entry = $data[$key];

        if (! isset($entry['options']) || ! isset($entry['arguments'])) {
            return null;
        }

        return [
            'options' => $entry['options'],
            'arguments' => $entry['arguments'],
        ];
    }

    /**
     * Store signature data in cache.
     *
     * @param  class-string  $commandClass
     * @param  array{options: array<string, array{type: string, has_default: bool, default?: mixed}>, arguments: array<string, array{type: string, has_default: bool, default?: mixed}>}  $data
     */
    public static function set(string $commandClass, int $mtime, array $data): void
    {
        $key = self::makeKey($commandClass, $mtime);
        $cache = self::loadCache();

        $cache[$key] = [
            'class' => $commandClass,
            'mtime' => $mtime,
            'options' => $data['options'],
            'arguments' => $data['arguments'],
        ];

        self::$cacheData = $cache;
        self::$dirty = true;
    }

    /**
     * Persist cache to disk (atomic write).
     */
    public static function persist(): void
    {
        if (! self::$dirty || self::$cacheData === null) {
            return;
        }

        $path = self::getCachePath();
        $dir = dirname($path);

        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $tempPath = $path . '.tmp.' . getmypid();

        $json = json_encode(
            self::sortCacheData(self::$cacheData),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );

        if ($json === false) {
            return;
        }

        if (@file_put_contents($tempPath, $json) === false) {
            @unlink($tempPath);

            return;
        }

        if (! @rename($tempPath, $path)) {
            @unlink($tempPath);

            return;
        }

        self::$dirty = false;
    }

    /**
     * Clear cache (for testing).
     */
    public static function clear(): void
    {
        self::$cacheData = null;
        self::$dirty = false;
        self::$cachePath = null;

        $path = self::getCachePath();

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Get cache file path.
     */
    private static function getCachePath(): string
    {
        if (self::$cachePath !== null) {
            return self::$cachePath;
        }

        $isTestMode = getenv(self::TEST_MODE_ENV) === '1' || getenv(self::TEST_MODE_ENV) === 'true';

        if ($isTestMode) {
            $baseDir = defined('DIST_PATH') ? DIST_PATH : getcwd() . '/dist';
            self::$cachePath = $baseDir . '/tmp/' . self::CACHE_FILENAME;
        } else {
            $baseDir = defined('MEMORY_PATH') ? MEMORY_PATH : getcwd() . '/memory';
            self::$cachePath = $baseDir . '/' . self::CACHE_FILENAME;
        }

        return self::$cachePath;
    }

    /**
     * Make cache key from class name and mtime.
     */
    private static function makeKey(string $commandClass, int $mtime): string
    {
        return $commandClass . '::' . $mtime;
    }

    /**
     * Load cache from disk.
     */
    private static function loadCache(): array
    {
        if (self::$cacheData !== null) {
            return self::$cacheData;
        }

        $path = self::getCachePath();

        if (! is_file($path)) {
            self::$cacheData = [];

            return self::$cacheData;
        }

        $content = @file_get_contents($path);

        if ($content === false) {
            self::$cacheData = [];

            return self::$cacheData;
        }

        $data = json_decode($content, true);

        if (! is_array($data)) {
            self::$cacheData = [];

            return self::$cacheData;
        }

        self::$cacheData = $data;

        return self::$cacheData;
    }

    /**
     * Sort cache data for deterministic output.
     */
    private static function sortCacheData(array $data): array
    {
        ksort($data);

        foreach ($data as $key => $entry) {
            if (isset($entry['options']) && is_array($entry['options'])) {
                ksort($data[$key]['options']);
            }

            if (isset($entry['arguments']) && is_array($entry['arguments'])) {
                ksort($data[$key]['arguments']);
            }
        }

        return $data;
    }
}
