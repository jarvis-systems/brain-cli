<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Enums\Agent;
use BrainCLI\Services\Contracts\CompileContract;

class MasterListCommand extends CompileCommand
{
    protected $signature = 'master:list {agent=claude : Agent for which compilation}';

    protected $description = 'List all available masters for a given agent';

    protected $aliases = [];

    public function handle(): int
    {
        if ($error = $this->initCommand()) {
            return $error;
        }

        return $this->applyComplier(function () {

            $files = $this->getFile($this->getFileList('Agents'), 'meta');

            foreach ($files as $file) {
                $id = $file['meta']['id'] ?? $file['id'];
                $this->line("ID: @agent-{$id}");
                $this->line("Master Name: {$file['classBasename']}");
                $this->line("Description: " . ($file['meta']['description'] ?? 'N/A'));
                $this->line('---');
            }

            return OK;
        });
    }
}

