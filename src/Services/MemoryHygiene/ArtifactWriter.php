<?php

declare(strict_types=1);

namespace BrainCLI\Services\MemoryHygiene;

/**
 * Writes memory hygiene artifacts as pretty-printed JSON files.
 */
class ArtifactWriter
{
    public function __construct(
        protected string $outputDir,
    ) {
    }

    /**
     * @param  array<string, mixed>  $ledger
     */
    public function writeLedger(array $ledger): void
    {
        $this->writeJson('ledger.json', $ledger);
    }

    /**
     * @param  array<string, mixed>  $results
     */
    public function writeSmokeResults(array $results): void
    {
        $this->writeJson('smoke-results.json', $results);
    }

    /**
     * @param  array<string, mixed>  $results
     */
    public function writeRankSafetyResults(array $results): void
    {
        $this->writeJson('rank-safety-results.json', $results);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function writeJson(string $filename, array $data): void
    {
        if (! is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }

        $path = rtrim($this->outputDir, '/') . '/' . $filename;

        file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
        );
    }
}
