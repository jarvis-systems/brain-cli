<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Abstracts\CommandBridgeAbstract;
use BrainCLI\Exceptions\CommandTerminatedException;
use BrainCLI\Services\Compile\CompileDiff;
use BrainCLI\Services\CompileLock;
use BrainCLI\Support\Brain;
use Illuminate\Support\Facades\File;
use Symfony\Component\VarExporter\VarExporter;

class CompileCommand extends CommandBridgeAbstract
{
    protected $signature = 'compile
        {agent=exists : Agent for which compilation or all exists agents}
        {--show-variables : Show available variables for compilation}
        {--json : Output in JSON format}
        {--human : Human-readable output (default)}
        {--no-lock : Skip compile lock (unsafe, for emergency only)}
        {--diff : Preview compilation changes without keeping them (backup/compile/diff/restore)}
        ';

    protected $description = 'Compile the Brain configurations files';

    protected $aliases = ['c', 'generate', 'build', 'make'];

    protected array $compiledFilesAndDirectories = [];

    public function getHelp(): string
    {
        return parent::getHelp() . <<<'HELP'

Examples:
  brain compile              Compile all existing agent targets
  brain compile claude       Compile only Claude target
  brain compile --json       Machine-parseable JSON output
  brain compile --diff       Preview changes without writing (exit 0=no diff, 2=diff)
  brain compile --diff --json  Diff as JSON schema (status, exit_code, files)
  PIN_STRICT=1 brain compile   Compile with strict MCP pin verification
HELP;
    }

    public function __construct(
        protected array $env = []
    )
    {
        // set env data
        foreach ($this->env as $key => $value) {
            putenv("$key=$value");
        }
        parent::__construct();
    }

    public function handleBridge(): int|array
    {
        if ($this->option('diff')) {
            return $this->handleDiff();
        }

        $lock = $this->acquireCompileLock();

        try {
            $agents = $this->detectAgents();

            if (! $this->option('json')) {
                $this->line('');
            }

            foreach ($agents as $agent) {

                $this->initFor($agent);

                if ($this->option('show-variables')) {
                    $this->showVariables();
                    continue;
                }

                if ($this->argument('agent') === 'exists' && $agent->depended()) {
                    continue;
                }

                $result = ERROR;

                if ($this->option('json')) {

                    echo json_encode([
                        'agent' => $agent->value,
                        'result' => $this->compilingProcess() === OK ? 'success' : 'error',
                        'filesAndDirectories' => $this->compiledFilesAndDirectories,
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
                } else {

                    $this->components->task("Compiling for [{$agent->value}]", function () use (&$result) {
                        $result = $this->compilingProcess();
                    });

                    if ($result !== OK) {
                        $this->line('');
                        return $result;
                    }
                }
            }

            if (! $this->option('json')) {
                $this->line('');
            }

            return OK;
        } finally {
            $lock?->release();
        }
    }

    /**
     * Acquire compile lock or return null if --no-lock.
     *
     * Exits with ERROR if another compilation is already running
     * or if --no-lock is disallowed by strict mode policy.
     */
    private function acquireCompileLock(): ?CompileLock
    {
        if ($this->option('no-lock')) {
            $this->enforceNoLockPolicy();
            $this->components->warn('Compile lock skipped (--no-lock). Single-writer safety disabled.');

            return null;
        }

        $lock = new CompileLock(Brain::workingDirectory());

        if (! $lock->acquire()) {
            $info = $lock->getHolderInfo();
            $pid = $info['pid'] ?? 'unknown';
            $since = $info['started_at'] ?? 'unknown';

            $this->components->error(
                "Compilation locked by PID {$pid} (since {$since}). Another brain compile is running."
            );
            $this->components->warn('Use --no-lock to override (unsafe).');

            throw new CommandTerminatedException();
        }

        return $lock;
    }

    /**
     * Enforce --no-lock governance policy.
     *
     * Under paranoid/strict modes, --no-lock is blocked unless
     * BRAIN_ALLOW_NO_LOCK=1 is explicitly set in environment.
     *
     * Additionally, BRAIN_TEST_MODE=1 is required for --no-lock
     * to prevent accidental use in production.
     */
    private function enforceNoLockPolicy(): void
    {
        $strictMode = Brain::getEnv('STRICT_MODE', 'standard');
        $allowOverride = Brain::getEnv('BRAIN_ALLOW_NO_LOCK');

        if (! CompileLock::isNoLockAllowed($strictMode, $allowOverride)) {
            $this->components->error(
                "The --no-lock flag is disallowed under STRICT_MODE={$strictMode}."
            );
            $this->components->warn(
                'Set BRAIN_ALLOW_NO_LOCK=1 in environment to override.'
            );

            throw new CommandTerminatedException();
        }

        $workdir = Brain::workingDirectory();
        $contract = CompileLock::validateTestModeContract($workdir);

        if (! $contract['valid']) {
            $this->components->error($contract['error']);
            $this->components->warn('Create isolated temp workdir or add .brain/test-workdir marker file.');

            throw new CommandTerminatedException();
        }
    }

    protected function compilingProcess(): int
    {
        $files = $this->convertFiles($this->getWorkingFiles(), env: $this->env);
        if ($files->isEmpty()) {
            $this->components->warn("No configuration files found for agent {$this->agent->value}.");
            return ERROR;
        }

        if ($this->client->compile($files)) {
            $this->compiledFilesAndDirectories = array_merge(
                $this->compiledFilesAndDirectories,
                $this->client->getCompiledFilesAndDirectories()
            );
            $this->client->compileDone();
            return OK;
        }
        $this->components->error("Compilation failed for agent {$this->agent->value}.");
        return ERROR;
    }

    /**
     * Handle --diff mode: backup output, compile, diff, restore.
     *
     * Exit codes: 0 = no differences, 2 = differences found, 1 = error.
     */
    private function handleDiff(): int
    {
        $projectRoot = Brain::projectDirectory();
        $outputDirs = $this->getCompileOutputPaths($projectRoot);
        $backupRoot = sys_get_temp_dir() . '/brain-compile-diff-' . uniqid();

        // Create backup of current compile output
        $this->backupOutputDirs($outputDirs, $backupRoot);

        $compileResult = ERROR;

        try {
            // Run actual compilation (writes to real output dirs)
            $lock = $this->acquireCompileLock();

            try {
                $agents = $this->detectAgents();

                foreach ($agents as $agent) {
                    $this->initFor($agent);

                    if ($this->argument('agent') === 'exists' && $agent->depended()) {
                        continue;
                    }

                    $compileResult = $this->compilingProcess();

                    if ($compileResult !== OK) {
                        break;
                    }
                }
            } finally {
                $lock?->release();
            }

            if ($compileResult !== OK) {
                $this->components->error('Compilation failed — diff aborted.');

                return ERROR;
            }

            // Diff: compare backup (old) vs current (new)
            $differ = new CompileDiff();
            $allDiffs = ['summary' => ['added' => 0, 'changed' => 0, 'removed' => 0, 'unchanged' => 0], 'files' => []];

            foreach ($outputDirs as $dir) {
                $relativePath = ltrim(str_replace($projectRoot, '', $dir), '/');
                $backupPath = $backupRoot . '/' . $relativePath;
                $currentPath = $dir;

                if (is_dir($currentPath) || is_dir($backupPath)) {
                    $dirDiff = $differ->compare(
                        is_dir($backupPath) ? $backupPath : '',
                        is_dir($currentPath) ? $currentPath : '',
                        $relativePath,
                    );
                } elseif (is_file($currentPath) || is_file($backupPath)) {
                    // Single file comparison (e.g. .mcp.json)
                    $dirDiff = $this->compareSingleFile($backupPath, $currentPath, $relativePath);
                } else {
                    continue;
                }

                $allDiffs['summary']['added'] += $dirDiff['summary']['added'];
                $allDiffs['summary']['changed'] += $dirDiff['summary']['changed'];
                $allDiffs['summary']['removed'] += $dirDiff['summary']['removed'];
                $allDiffs['summary']['unchanged'] += $dirDiff['summary']['unchanged'];
                $allDiffs['files'] = array_merge($allDiffs['files'], $dirDiff['files']);
            }

            // Output
            $noDiff = $differ->isEmpty($allDiffs);

            if ($this->option('json')) {
                echo json_encode(
                    $differ->toJsonSchema($allDiffs),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ) . PHP_EOL;
            } else {
                $this->renderDiffHuman($allDiffs);
            }

            return $noDiff ? OK : 2;
        } finally {
            // Restore original files from backup
            $this->restoreOutputDirs($outputDirs, $backupRoot);

            // Cleanup temp dir
            if (is_dir($backupRoot)) {
                File::deleteDirectory($backupRoot);
            }
        }
    }

    /**
     * Get the list of compile output paths (directories and files).
     *
     * @return list<string>
     */
    private function getCompileOutputPaths(string $projectRoot): array
    {
        $paths = [];

        // .claude/ directory
        $claudeDir = $projectRoot . '/.claude';
        if (is_dir($claudeDir)) {
            $paths[] = $claudeDir;
        }

        // .mcp.json file
        $mcpFile = $projectRoot . '/.mcp.json';
        if (is_file($mcpFile)) {
            $paths[] = $mcpFile;
        }

        // .brain/agent-schema.json
        $schemaFile = Brain::workingDirectory('agent-schema.json');
        if (is_file($schemaFile)) {
            $paths[] = $schemaFile;
        }

        return $paths;
    }

    /**
     * Backup compile output directories/files to a temp location.
     *
     * @param  list<string>  $paths
     */
    private function backupOutputDirs(array $paths, string $backupRoot): void
    {
        $projectRoot = Brain::projectDirectory();

        foreach ($paths as $path) {
            $relativePath = ltrim(str_replace($projectRoot, '', $path), '/');
            $backupPath = $backupRoot . '/' . $relativePath;

            if (is_dir($path)) {
                File::copyDirectory($path, $backupPath);
            } elseif (is_file($path)) {
                $backupDir = dirname($backupPath);

                if (! is_dir($backupDir)) {
                    mkdir($backupDir, 0755, true);
                }

                copy($path, $backupPath);
            }
        }
    }

    /**
     * Restore compile output from backup, replacing current files.
     *
     * @param  list<string>  $paths
     */
    private function restoreOutputDirs(array $paths, string $backupRoot): void
    {
        $projectRoot = Brain::projectDirectory();

        foreach ($paths as $path) {
            $relativePath = ltrim(str_replace($projectRoot, '', $path), '/');
            $backupPath = $backupRoot . '/' . $relativePath;

            if (is_dir($backupPath)) {
                if (is_dir($path)) {
                    File::cleanDirectory($path);
                }

                File::copyDirectory($backupPath, $path);
            } elseif (is_file($backupPath)) {
                copy($backupPath, $path);
            }
        }
    }

    /**
     * Compare a single file (e.g. .mcp.json).
     *
     * @return array{summary: array{added: int, changed: int, removed: int, unchanged: int}, files: list<array<string, mixed>>}
     */
    private function compareSingleFile(string $backupFile, string $currentFile, string $displayPath): array
    {
        $backupExists = is_file($backupFile);
        $currentExists = is_file($currentFile);

        if (! $backupExists && ! $currentExists) {
            return ['summary' => ['added' => 0, 'changed' => 0, 'removed' => 0, 'unchanged' => 0], 'files' => []];
        }

        if (! $backupExists && $currentExists) {
            return [
                'summary' => ['added' => 1, 'changed' => 0, 'removed' => 0, 'unchanged' => 0],
                'files' => [['path' => $displayPath, 'status' => 'added']],
            ];
        }

        if ($backupExists && ! $currentExists) {
            return [
                'summary' => ['added' => 0, 'changed' => 0, 'removed' => 1, 'unchanged' => 0],
                'files' => [['path' => $displayPath, 'status' => 'removed']],
            ];
        }

        $backupContent = file_get_contents($backupFile);
        $currentContent = file_get_contents($currentFile);

        if ($backupContent === $currentContent) {
            return ['summary' => ['added' => 0, 'changed' => 0, 'removed' => 0, 'unchanged' => 1], 'files' => []];
        }

        return [
            'summary' => ['added' => 0, 'changed' => 1, 'removed' => 0, 'unchanged' => 0],
            'files' => [['path' => $displayPath, 'status' => 'changed']],
        ];
    }

    /**
     * Render diff result in human-readable format.
     *
     * @param  array{summary: array{added: int, changed: int, removed: int, unchanged: int}, files: list<array<string, mixed>>}  $diff
     */
    private function renderDiffHuman(array $diff): void
    {
        $summary = $diff['summary'];
        $files = $diff['files'];

        $this->line('');

        if (empty($files)) {
            $this->components->info('Compile diff: no differences');

            return;
        }

        $this->components->info('Compile diff');
        $this->newLine();

        foreach ($files as $file) {
            $status = $file['status'];
            $path = $file['path'];

            $badge = match ($status) {
                'added' => '<fg=green>+</>',
                'removed' => '<fg=red>-</>',
                'changed' => '<fg=yellow>~</>',
                default => ' ',
            };

            $statusLabel = match ($status) {
                'added' => '<fg=green>added</>',
                'removed' => '<fg=red>removed</>',
                'changed' => '<fg=yellow>changed</>',
                default => $status,
            };

            $extra = '';

            if (isset($file['lines_added'], $file['lines_removed'])) {
                $extra = sprintf(
                    '  <fg=green>+%d</> <fg=red>-%d</>',
                    $file['lines_added'],
                    $file['lines_removed'],
                );
            }

            $this->line("  {$badge} {$path}  {$statusLabel}{$extra}");
        }

        $this->newLine();
        $this->components->twoColumnDetail(
            'Summary',
            sprintf(
                '<fg=green>%d added</>, <fg=yellow>%d changed</>, <fg=red>%d removed</>, %d unchanged',
                $summary['added'],
                $summary['changed'],
                $summary['removed'],
                $summary['unchanged'],
            ),
        );
        $this->line('');
    }

    protected function showVariables(): int
    {
        $brainFile = $this->getWorkingFile('Brain.php::meta');
        $brain = $this->convertFiles($brainFile)->first();
        if ($brain === null) {
            $this->components->error("Brain configuration file not found.");
            return ERROR;
        }
        $this->components->info('Available compilation variables:');
        $vars = $brain['meta'];
        $compilerVars = $this->client->compileVariables();
        $allVars = array_merge($vars, $compilerVars);
        foreach ($allVars as $key => $value) {
            try {
                $value = is_string($value) ? $value : VarExporter::export($value);
            } catch (\Throwable $e) {
                Brain::debugException($e, 'brain-debug:showVariables');
                $value = '<error>Cannot export variable</error>';
            }
            $this->line(" - <fg=cyan>{{ $key }}</>: $value");
        }
        $this->line('');
        return OK;
    }
}

