<?php

declare(strict_types=1);

namespace BrainCLI\Services\Docs;

class FreshnessResolver
{
    protected const BUCKET_FRESH = 7;

    protected const BUCKET_RECENT = 30;

    protected const BUCKET_AGING = 90;

    /** @var array<string, array<string, int>> directory → [absolutePath → unixTimestamp] */
    private array $gitLookups = [];

    private ?int $now = null;

    public function setNow(?int $timestamp): void
    {
        $this->now = $timestamp;
    }

    /**
     * @return array{modified_at: string|null, days_ago: int, bucket: string}
     */
    public function resolve(string $absolutePath, string $directory): array
    {
        $this->warmDirectory($directory);

        $timestamp = $this->gitLookups[$directory][$absolutePath] ?? null;

        if ($timestamp === null && file_exists($absolutePath)) {
            $timestamp = filemtime($absolutePath) ?: null;
        }

        if ($timestamp === null) {
            return [
                'modified_at' => null,
                'days_ago' => 0,
                'bucket' => 'stale',
            ];
        }

        $now = $this->now ?? time();
        $daysAgo = max(0, (int) floor(($now - $timestamp) / 86400));

        return [
            'modified_at' => gmdate('Y-m-d\TH:i:s\Z', $timestamp),
            'days_ago' => $daysAgo,
            'bucket' => $this->computeBucket($daysAgo),
        ];
    }

    public function warmDirectory(string $directory): void
    {
        if (isset($this->gitLookups[$directory])) {
            return;
        }

        $this->gitLookups[$directory] = $this->buildGitLookup($directory);
    }

    /**
     * @return array<string, int>
     */
    protected function buildGitLookup(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        [$exitCode, $repoRootOut] = $this->exec('git rev-parse --show-toplevel', $directory);

        if ($exitCode !== 0) {
            return [];
        }

        $repoRoot = rtrim($repoRootOut);

        [$exitCode, $logOutput] = $this->exec(
            'git log --format="COMMIT %ct" --name-only --diff-filter=ACMR -- .',
            $directory,
        );

        if ($exitCode !== 0) {
            return [];
        }

        $lookup = [];
        $currentTimestamp = null;

        foreach (explode("\n", $logOutput) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, 'COMMIT ')) {
                $currentTimestamp = (int) substr($line, 7);

                continue;
            }

            if ($currentTimestamp === null) {
                continue;
            }

            $absolutePath = $repoRoot . '/' . $line;

            if (! isset($lookup[$absolutePath])) {
                $lookup[$absolutePath] = $currentTimestamp;
            }
        }

        return $lookup;
    }

    protected function computeBucket(int $daysAgo): string
    {
        return match (true) {
            $daysAgo <= self::BUCKET_FRESH => 'fresh',
            $daysAgo <= self::BUCKET_RECENT => 'recent',
            $daysAgo <= self::BUCKET_AGING => 'aging',
            default => 'stale',
        };
    }

    /**
     * @return array{int, string, string, int}
     */
    protected function exec(string $command, ?string $cwd = null): array
    {
        $startTime = hrtime(true);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $cwd);

        if (! is_resource($process)) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            return [1, '', 'Failed to start process', $durationMs];
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        return [$exitCode, $stdout, $stderr, $durationMs];
    }
}
