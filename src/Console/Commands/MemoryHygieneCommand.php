<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Console\Kernel\CommandKernel;
use BrainCLI\Console\Traits\HelpersTrait;
use BrainCLI\Services\Mcp\McpClientException;
use BrainCLI\Services\Mcp\McpStdioClient;
use BrainCLI\Services\MemoryHygiene\ArtifactWriter;
use BrainCLI\Services\MemoryHygiene\LedgerBuilder;
use BrainCLI\Services\MemoryHygiene\RankSafetyChecker;
use BrainCLI\Services\MemoryHygiene\SmokeTestRunner;
use Illuminate\Console\Command;

/**
 * Automated memory hygiene: ledger, smoke tests, rank safety.
 *
 * Spawns the vector-memory MCP server via stdio and runs
 * the full hygiene workflow producing JSON artifacts.
 */
class MemoryHygieneCommand extends Command
{
    use HelpersTrait;

    protected $signature = 'memory:hygiene
        {--consolidate : Run consolidation phase (requires --yes)}
        {--yes : Confirm destructive operations}
        {--probe-set= : Path to probe-set.json (default: .work/memory-hygiene/probe-set.json)}
    ';

    protected $description = 'Run memory hygiene checks: ledger, smoke tests, rank safety';

    public function handle(): int
    {
        return CommandKernel::run(
            fn () => $this->executeCommand(),
            'memory:hygiene',
            fn (\Throwable $e) => $this->components->error($e->getMessage()),
        );
    }

    protected function executeCommand(): int
    {
        $this->checkWorkingDir();

        $outputDir = getcwd() . '/.work/memory-hygiene';
        $probeSetPath = $this->option('probe-set')
            ?: $outputDir . '/probe-set.json';

        // Validate probe-set exists
        if (! file_exists($probeSetPath)) {
            $this->components->error("Probe set not found: {$probeSetPath}");

            return ERROR;
        }

        $probeSet = json_decode(file_get_contents($probeSetPath) ?: '{}', true);

        if (! is_array($probeSet) || empty($probeSet['probes'])) {
            $this->components->error('Invalid probe-set.json: missing probes array');

            return ERROR;
        }

        // Resolve MCP config
        $mcpConfig = $this->resolveMcpConfig();

        if ($mcpConfig === null) {
            return ERROR;
        }

        $client = new McpStdioClient(
            $mcpConfig['command'],
            $mcpConfig['args'],
            getcwd() ?: null,
        );

        try {
            $this->components->info('Connecting to vector-memory MCP server...');
            $client->connect();

            $writer = new ArtifactWriter($outputDir);

            // Phase 1: Ledger
            $this->components->info('Building ledger...');
            $ledger = (new LedgerBuilder($client))->build();
            $writer->writeLedger($ledger);
            $this->components->info('Ledger written to .work/memory-hygiene/ledger.json');

            // NO_DATA: empty vector store — skip smoke and rank safety
            if (((int) ($ledger['total_memories'] ?? 0)) === 0) {
                return $this->handleNoData($writer, $probeSet, $ledger);
            }

            // Phase 2: Smoke Tests
            $this->components->info('Running smoke tests...');
            $smokeResults = (new SmokeTestRunner($client))->run($probeSet);
            $writer->writeSmokeResults($smokeResults);
            $this->components->info(sprintf(
                'Smoke tests: %d/%d passed (%.0f%%) — %s',
                $smokeResults['passed'],
                $smokeResults['total_probes'],
                $smokeResults['pass_rate'] * 100,
                $smokeResults['threshold_met'] ? 'THRESHOLD MET' : 'BELOW THRESHOLD',
            ));

            // Phase 3: Rank Safety
            $this->components->info('Checking rank safety...');
            $canonicalIds = $this->extractCanonicalIds($ledger);
            $anchorIds = $this->extractAnchorIds($ledger);

            $rankResults = (new RankSafetyChecker($client))->check($probeSet, $canonicalIds, $anchorIds);
            $writer->writeRankSafetyResults($rankResults);
            $this->components->info(sprintf(
                'Rank safety: %s — %d overlap risks',
                $rankResults['verdict'],
                $rankResults['overlap_risks_detected'],
            ));

            // Consolidation guard
            if ($this->option('consolidate')) {
                if (! $this->option('yes')) {
                    $this->components->error('Consolidation requires --yes flag');

                    return ERROR;
                }

                if (! $smokeResults['threshold_met']) {
                    $this->components->error('Consolidation blocked: smoke tests below threshold');

                    return ERROR;
                }

                $this->components->warn('Consolidation phase not yet implemented');
            }

            // Summary output
            $this->outputSummary($ledger, $smokeResults, $rankResults);

            return OK;
        } catch (McpClientException $e) {
            $this->components->error("MCP error: {$e->getMessage()}");

            return ERROR;
        } finally {
            $client->close();
        }
    }

    /**
     * Resolve vector-memory MCP server config from .mcp.json.
     *
     * @return array{command: string, args: list<string>}|null
     */
    protected function resolveMcpConfig(): ?array
    {
        $mcpJsonPath = getcwd() . '/.mcp.json';

        if (! file_exists($mcpJsonPath)) {
            $this->components->error('.mcp.json not found in project root');

            return null;
        }

        $config = json_decode(file_get_contents($mcpJsonPath) ?: '{}', true);
        $server = $config['mcpServers']['vector-memory'] ?? null;

        if ($server === null) {
            $this->components->error('vector-memory server not configured in .mcp.json');

            return null;
        }

        $command = $server['command'] ?? null;
        $args = $server['args'] ?? [];

        if (! is_string($command) || $command === '') {
            $this->components->error('vector-memory server has invalid command in .mcp.json');

            return null;
        }

        // Verify command is available
        $which = trim(shell_exec("which {$command} 2>/dev/null") ?: '');

        if ($which === '') {
            $this->components->error("{$command} not found on PATH. Install it first.");

            return null;
        }

        return [
            'command' => $command,
            'args' => is_array($args) ? array_values($args) : [],
        ];
    }

    /**
     * Extract canonical memory IDs from ledger.
     *
     * @param  array<string, mixed>  $ledger
     * @return list<int>
     */
    protected function extractCanonicalIds(array $ledger): array
    {
        $ids = [];

        foreach ($ledger['canonical_memories'] ?? [] as $memory) {
            if (isset($memory['id'])) {
                $ids[] = (int) $memory['id'];
            }
        }

        return $ids;
    }

    /**
     * Extract anchor memory IDs from ledger.
     *
     * @param  array<string, mixed>  $ledger
     * @return list<int>
     */
    protected function extractAnchorIds(array $ledger): array
    {
        $ids = [];

        foreach ($ledger['anchor_memories'] ?? [] as $memory) {
            if (isset($memory['id'])) {
                $ids[] = (int) $memory['id'];
            }
        }

        return $ids;
    }

    /**
     * Handle empty vector store: skip smoke/rank, write NO_DATA artifacts.
     *
     * @param  array<string, mixed>  $probeSet
     * @param  array<string, mixed>  $ledger
     */
    protected function handleNoData(ArtifactWriter $writer, array $probeSet, array $ledger): int
    {
        $this->components->info('NO_DATA: empty vector store — smoke and rank safety skipped.');

        $probes = $probeSet['probes'] ?? [];
        $criticalTotal = 0;

        foreach ($probes as $probe) {
            if ($probe['critical'] ?? false) {
                $criticalTotal++;
            }
        }

        $smokeResults = [
            'run_date' => gmdate('Y-m-d\TH:i:s\Z'),
            'version' => $probeSet['version'] ?? '1.0.0',
            'status' => 'no_data',
            'total_probes' => count($probes),
            'passed' => 0,
            'failed' => 0,
            'skipped' => count($probes),
            'pass_rate' => 0,
            'threshold_met' => null,
            'critical_passed' => 0,
            'critical_total' => $criticalTotal,
            'reason' => 'Empty vector store — no memories to evaluate',
            'results' => [],
        ];

        $rankResults = [
            'run_date' => gmdate('Y-m-d\TH:i:s\Z'),
            'version' => $probeSet['version'] ?? '1.0.0',
            'status' => 'no_data',
            'verdict' => 'NO_DATA',
            'overlap_threshold' => 0.01,
            'probes_checked' => 0,
            'critical_probes_passed' => 0,
            'critical_probes_total' => $criticalTotal,
            'overlap_risks_detected' => 0,
            'canonical_ids_checked' => [],
            'anchor_ids_protected' => [],
            'reason' => 'Empty vector store — rank safety not applicable',
            'results' => [],
        ];

        $writer->writeSmokeResults($smokeResults);
        $writer->writeRankSafetyResults($rankResults);

        $this->outputSummary($ledger, $smokeResults, $rankResults, 'no_data');

        return OK;
    }

    /**
     * Output JSON summary to stdout.
     *
     * @param  array<string, mixed>  $ledger
     * @param  array<string, mixed>  $smoke
     * @param  array<string, mixed>  $rank
     */
    protected function outputSummary(array $ledger, array $smoke, array $rank, string $status = 'complete'): void
    {
        $summary = [
            'status' => $status,
            'ledger' => [
                'total_memories' => $ledger['total_memories'] ?? 0,
                'health_status' => $ledger['health_status'] ?? 'Unknown',
            ],
            'smoke' => [
                'passed' => $smoke['passed'] ?? 0,
                'total' => $smoke['total_probes'] ?? 0,
                'pass_rate' => $smoke['pass_rate'] ?? 0,
                'threshold_met' => $smoke['threshold_met'] ?? false,
                'critical_pass_rate' => $smoke['critical_pass_rate'] ?? 0,
            ],
            'rank_safety' => [
                'verdict' => $rank['verdict'] ?? 'UNKNOWN',
                'overlap_risks' => $rank['overlap_risks_detected'] ?? 0,
            ],
        ];

        if ($status === 'no_data') {
            $summary['reason'] = 'Empty vector store — smoke and rank safety skipped';
            $summary['smoke']['skipped'] = $smoke['skipped'] ?? 0;
        }

        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
    }
}
