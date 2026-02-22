<?php

declare(strict_types=1);

namespace BrainCLI\Services\Compile;

/**
 * Deterministic diff engine for compile output artifacts.
 *
 * Compares two directory trees (backup vs current) and produces
 * a structured diff result with per-file status and unified diffs.
 */
class CompileDiff
{
    /**
     * Maximum lines per unified diff to prevent output explosion.
     */
    private const MAX_DIFF_LINES = 200;

    /**
     * Files/patterns excluded from diff (volatile, non-deterministic).
     *
     * @var list<string>
     */
    private const EXCLUDED_PATTERNS = [
        '.phpunit.cache',
        '.phpstan',
        'compile.lock',
    ];

    /**
     * Compare backup (before compile) with current (after compile).
     *
     * @return array{summary: array{added: int, changed: int, removed: int, unchanged: int}, files: list<array{path: string, status: string, diff?: string, lines_added?: int, lines_removed?: int}>}
     */
    public function compare(string $backupDir, string $currentDir, string $relativeTo = ''): array
    {
        $backupFiles = $this->scanFiles($backupDir);
        $currentFiles = $this->scanFiles($currentDir);

        $allPaths = array_unique(array_merge(
            array_keys($backupFiles),
            array_keys($currentFiles),
        ));
        sort($allPaths);

        $files = [];
        $added = 0;
        $changed = 0;
        $removed = 0;
        $unchanged = 0;

        foreach ($allPaths as $relativePath) {
            if ($this->isExcluded($relativePath)) {
                continue;
            }

            $inBackup = isset($backupFiles[$relativePath]);
            $inCurrent = isset($currentFiles[$relativePath]);

            $displayPath = $relativeTo !== '' ? $relativeTo . '/' . $relativePath : $relativePath;

            if (! $inBackup && $inCurrent) {
                $added++;
                $entry = ['path' => $displayPath, 'status' => 'added'];

                if ($this->isTextFile($currentDir . '/' . $relativePath)) {
                    $content = file_get_contents($currentDir . '/' . $relativePath);
                    $lineCount = $content !== false ? substr_count($content, "\n") + 1 : 0;
                    $entry['lines_added'] = $lineCount;
                    $entry['lines_removed'] = 0;
                }

                $files[] = $entry;
            } elseif ($inBackup && ! $inCurrent) {
                $removed++;
                $files[] = ['path' => $displayPath, 'status' => 'removed'];
            } elseif ($inBackup && $inCurrent) {
                $backupContent = file_get_contents($backupDir . '/' . $relativePath);
                $currentContent = file_get_contents($currentDir . '/' . $relativePath);

                if ($backupContent === $currentContent) {
                    $unchanged++;

                    continue;
                }

                $changed++;
                $entry = ['path' => $displayPath, 'status' => 'changed'];

                if ($this->isTextFile($currentDir . '/' . $relativePath)) {
                    $diff = $this->generateUnifiedDiff(
                        $backupContent ?: '',
                        $currentContent ?: '',
                        $relativePath,
                    );
                    $entry['diff'] = $diff['text'];
                    $entry['lines_added'] = $diff['added'];
                    $entry['lines_removed'] = $diff['removed'];
                    $entry['truncated'] = $diff['truncated'];
                } else {
                    $entry['diff'] = sprintf(
                        'Binary: %s -> %s',
                        $this->formatSize(strlen($backupContent ?: '')),
                        $this->formatSize(strlen($currentContent ?: '')),
                    );
                }

                $files[] = $entry;
            }
        }

        return [
            'summary' => [
                'added' => $added,
                'changed' => $changed,
                'removed' => $removed,
                'unchanged' => $unchanged,
            ],
            'files' => $files,
        ];
    }

    /**
     * Check if a diff result indicates no differences.
     */
    public function isEmpty(array $result): bool
    {
        $summary = $result['summary'] ?? [];

        return ($summary['added'] ?? 0) === 0
            && ($summary['changed'] ?? 0) === 0
            && ($summary['removed'] ?? 0) === 0;
    }

    /**
     * Scan a directory recursively and return relative path => true map.
     *
     * @return array<string, true>
     */
    protected function scanFiles(string $dir): array
    {
        $files = [];

        if (! is_dir($dir)) {
            return $files;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile()) {
                $relativePath = ltrim(
                    str_replace($dir, '', $file->getPathname()),
                    DIRECTORY_SEPARATOR . '/',
                );
                // Normalize to forward slashes for cross-platform determinism
                $relativePath = str_replace('\\', '/', $relativePath);
                $files[$relativePath] = true;
            }
        }

        return $files;
    }

    /**
     * Generate a unified diff between two strings.
     *
     * @return array{text: string, added: int, removed: int, truncated: bool}
     */
    protected function generateUnifiedDiff(string $old, string $new, string $filename): array
    {
        $oldLines = explode("\n", $old);
        $newLines = explode("\n", $new);

        $diff = $this->computeDiff($oldLines, $newLines);

        $added = 0;
        $removed = 0;
        $outputLines = [];
        $truncated = false;

        $outputLines[] = "--- a/{$filename}";
        $outputLines[] = "+++ b/{$filename}";

        foreach ($diff as $line) {
            if (count($outputLines) >= self::MAX_DIFF_LINES) {
                $truncated = true;

                break;
            }

            $outputLines[] = $line;

            if (str_starts_with($line, '+') && ! str_starts_with($line, '+++')) {
                $added++;
            } elseif (str_starts_with($line, '-') && ! str_starts_with($line, '---')) {
                $removed++;
            }
        }

        if ($truncated) {
            $outputLines[] = '... (truncated, ' . count($diff) . ' total diff lines)';
        }

        return [
            'text' => implode("\n", $outputLines),
            'added' => $added,
            'removed' => $removed,
            'truncated' => $truncated,
        ];
    }

    /**
     * Compute line-level diff using a simple LCS-based approach.
     *
     * @param  list<string>  $old
     * @param  list<string>  $new
     * @return list<string>
     */
    protected function computeDiff(array $old, array $new): array
    {
        $oldCount = count($old);
        $newCount = count($new);

        // Build LCS table
        $lcs = [];
        for ($i = 0; $i <= $oldCount; $i++) {
            for ($j = 0; $j <= $newCount; $j++) {
                if ($i === 0 || $j === 0) {
                    $lcs[$i][$j] = 0;
                } elseif ($old[$i - 1] === $new[$j - 1]) {
                    $lcs[$i][$j] = $lcs[$i - 1][$j - 1] + 1;
                } else {
                    $lcs[$i][$j] = max($lcs[$i - 1][$j], $lcs[$i][$j - 1]);
                }
            }
        }

        // Backtrack to produce diff
        $result = [];
        $i = $oldCount;
        $j = $newCount;

        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $old[$i - 1] === $new[$j - 1]) {
                array_unshift($result, ' ' . $old[$i - 1]);
                $i--;
                $j--;
            } elseif ($j > 0 && ($i === 0 || $lcs[$i][$j - 1] >= $lcs[$i - 1][$j])) {
                array_unshift($result, '+' . $new[$j - 1]);
                $j--;
            } elseif ($i > 0) {
                array_unshift($result, '-' . $old[$i - 1]);
                $i--;
            }
        }

        return $result;
    }

    /**
     * Check if a file is a text file by extension.
     */
    private function isTextFile(string $path): bool
    {
        $textExtensions = ['md', 'json', 'yaml', 'yml', 'toml', 'xml', 'txt', 'php', 'js', 'ts'];
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, $textExtensions, true);
    }

    /**
     * Check if a relative path matches an excluded pattern.
     */
    private function isExcluded(string $relativePath): bool
    {
        foreach (self::EXCLUDED_PATTERNS as $pattern) {
            if (str_contains($relativePath, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Format file size for display.
     */
    private function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . 'B';
        }

        return round($bytes / 1024, 1) . 'KB';
    }
}
