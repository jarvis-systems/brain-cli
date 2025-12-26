<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Abstracts\CommandBridgeAbstract;

class ListMastersCommand extends CommandBridgeAbstract
{
    protected $signature = 'list:masters {agent=claude : Agent for which compilation}';

    protected $description = 'List all available subagent masters for a given agent';

    protected $aliases = [];

    public function handleBridge(): int|array
    {
        $this->initFor($this->argument('agent'));

        $workingFiles = $this->getWorkingFiles('Agents');
        if (empty($workingFiles)) {
            $this->components->warn("No master files found for agent {$this->agent->value}.");
            return ERROR;
        }
        $files = $this->convertFiles($workingFiles, 'meta');
        $json = [];

        foreach ($files as $file) {
            $id = $file['meta']['id'] ?? $file['id'];
            $json[$id] = $file['meta']['description'] ?? 'N/A';
        }

        if (! $json) {
            $this->components->warn('No masters found.');
            return ERROR;
        }
        return $json;
    }
}

