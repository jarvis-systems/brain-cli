<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Abstracts\CommandBridgeAbstract;
use BrainCLI\Support\Brain;
use Illuminate\Support\Facades\File;
use Symfony\Component\VarExporter\VarExporter;

class CompileCommand extends CommandBridgeAbstract
{
    protected $signature = 'compile 
        {agent=exists : Agent for which compilation or all exists agents}
        {--show-variables : Show available variables for compilation}
        {--env= : Specify environment file to use during compilation}
        ';

    protected $description = 'Compile the Brain configurations files';

    protected $aliases = ['c', 'generate', 'build', 'make'];

    public function handleBridge(): int|array
    {
        $agents = $this->detectAgents();
        $this->line('');
        $env = $this->option('env');
        if ($env !== null && is_array($r = json_decode($env, true))) {
            $env = $r;
        }

        foreach ($agents as $agent) {

            $this->initFor($agent);

            if ($this->option('show-variables')) {
                $this->showVariables();
                continue;
            }

            if ($this->argument('agent') === 'exists' && $this->agent->depended()) {
                continue;
            }

            $result = ERROR;

            $this->components->task("Compiling for [{$this->agent->value}]", function () use (&$result, $env) {
                $result = $this->compilingProcess($env);
            });

            if ($result !== OK) {
                $this->line('');
                return $result;
            }
        }
        $this->line('');
        return OK;
    }

    protected function compilingProcess(array|null $env = null): int
    {
        $files = $this->convertFiles($this->getWorkingFiles());
        if ($files->isEmpty()) {
            $this->components->warn("No configuration files found for agent {$this->agent->value}.");
            return ERROR;
        }

        if ($this->client->compile($files)) {
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

