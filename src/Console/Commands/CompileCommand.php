<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Abstracts\CommandBridgeAbstract;
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
        {--no-lock : Skip compile lock (unsafe, for emergency only)}
        ';

    protected $description = 'Compile the Brain configurations files';

    protected $aliases = ['c', 'generate', 'build', 'make'];

    protected array $compiledFilesAndDirectories = [];

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
        $lock = $this->acquireCompileLock();

        try {
            $agents = $this->detectAgents();
            $this->line('');

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
            $this->line('');
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

            exit(ERROR);
        }

        return $lock;
    }

    /**
     * Enforce --no-lock governance policy.
     *
     * Under paranoid/strict modes, --no-lock is blocked unless
     * BRAIN_ALLOW_NO_LOCK=1 is explicitly set in environment.
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

            exit(ERROR);
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
                $value = '<error>Cannot export variable</error>';
            }
            $this->line(" - <fg=cyan>{{ $key }}</>: $value");
        }
        $this->line('');
        return OK;
    }
}

