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

            $files = $this->convertFiles($this->getWorkingFiles('Agents'), 'meta');
            $json = [];

            foreach ($files as $file) {
                $id = $file['meta']['id'] ?? $file['id'];
                $json[$id] = $file['meta']['description'] ?? 'N/A';
            }

            if ($json) {
                $this->line(json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->components->warn('No masters found.');
            }

            return OK;
        });
    }
}

