<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

class ListMastersCommand extends CompileCommand
{
    protected $signature = 'list:masters {agent=claude : Agent for which compilation}';

    protected $description = 'List all available subagent masters for a given agent';

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

