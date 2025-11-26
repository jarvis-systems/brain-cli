<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Console\Traits\CompilerBridgeTrait;
use BrainCLI\Enums\Agent;
use BrainCLI\Support\Brain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\VarExporter\VarExporter;
use ValueError;

class CompileCommand extends Command
{
    use CompilerBridgeTrait;

    protected $signature = 'compile 
        {agent=exists : Agent for which compilation or all exists agents}
        {--show-variables : Show available variables for compilation}
        ';

    protected $description = 'Compile the Brain configurations files';

    protected $aliases = ['c', 'generate', 'build', 'make'];

    /**
     * @return int
     */
    public function handle(): int
    {
        $selectAgent = $this->argument('agent');
        $agents = [];
        if ($selectAgent === 'exists') {
            $agents = $this->detectExistsAgents();
        } else {
            foreach (explode(',', $selectAgent) as $item) {
                try {
                    $agents[] = Agent::from($item);
                } catch (ValueError) {
                    $this->components->error("Unsupported agent: {$item}");
                    return ERROR;
                }
            }
        }

        if (! count($agents)) {
            try {
                $agents[] = Agent::from(
                    $this->components->choice('Select agent for compilation', Agent::list(), Agent::CLAUDE->value)
                );
            } catch (ValueError) {
                $this->components->error("Unsupported agent: {$item}");
                return ERROR;
            }
        }
        $this->line('');
        foreach ($agents as $agent) {
            if ($error = $this->initCommand($agent)) {
                return $error;
            }

            $result = $this->applyComplier(function () {

                if ($this->option('show-variables')) {
                    return $this->showVariables();
                }

                $result = ERROR;

                $this->components->task("Compiling for [{$this->agent->value}]", function () use (&$result) {
                    $result = $this->compilingProcess();
                });

                return $result;
            });

            if ($result !== OK) {
                return $result;
            }
        }
        $this->line('');
        return OK;
    }

    protected function compilingProcess(): int
    {
        $files = $this->convertFiles($this->getWorkingFiles());
        if (empty($files)) {
            $this->components->warn("No configuration files found for agent {$this->agent->value}.");
            return ERROR;
        }
        $this->compiler->boot(collect($files));
        if ($this->compiler->compile()) {
            $assets = __DIR__ . '/../../../assets/' . $this->agent->value . '/';
            if (File::exists($assets)) {
                File::copyDirectory($assets, Brain::projectDirectory($this->compiler->brainFolder()));
            }
            $this->compiler->compiled();
            return OK;
        }
        $this->components->error("Compilation failed for agent {$this->agent->value}.");
        return ERROR;
    }

    protected function showVariables(): int
    {
        $brainFile = $this->getWorkingFile('Brain.php::meta');
        $brain = collect($this->convertFiles($brainFile))->first();
        if ($brain === null) {
            $this->components->error("Brain configuration file not found.");
            return ERROR;
        }
        $this->components->info('Available compilation variables:');
        $vars = $brain['meta'];
        $compilerVars = $this->compiler->compileVariables();
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

