<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Enums\Agent;
use BrainCLI\Services\Contracts\CompileContract;

class MasterListCommand extends CompileCommand
{
    protected $signature = 'master:list {agent=claude : Agent for which compilation}';

    protected $description = 'List all available masters for a given agent';

    public function handle(): int
    {
        $agent = $this->argument('agent');
        $enum = Agent::tryFrom($agent);

        if ($enum === null) {
            $this->components->error("Unsupported agent: {$agent}");
            return ERROR;
        }

        $this->agent = $enum;

        try {
            $compiler = $this->laravel->make($this->agent->containerName());
            if ($compiler instanceof CompileContract) {
                $this->compiler = $compiler;
                $files = $this->getFile($this->getFileList('Agents'), 'meta');

//                $this->table(['Master Name', 'ID', 'Description'], array_map(function ($file) {
//                    $id = $file['meta']['id'] ?? $file['id'];
//                    return [$file['classBasename'], "@agent-{$id}", $file['meta']['description'] ?? ''];
//                }, $files));

                foreach ($files as $file) {
                    $id = $file['meta']['id'] ?? $file['id'];
//                    $this->components->twoColumnDetail('Master Name', $file['classBasename']);
//                    $this->components->twoColumnDetail('ID', "@agent-{$id}");
//                    $this->components->twoColumnDetail('Description', $file['meta']['description'] ?? 'N/A');
                    $this->line("ID: @agent-{$id}");
                    $this->line("Master Name: {$file['classBasename']}");
                    $this->line("Description: " . ($file['meta']['description'] ?? 'N/A'));
                    $this->line('---');
                }

            } else {
                $this->components->error("Compiler for agent {$agent} does not implement CompileContract");
                return ERROR;
            }
        } catch (\Throwable $e) {
            $this->components->error("Compilation failed: " . $e->getMessage());
            return ERROR;
        }
        return OK;
    }
}

