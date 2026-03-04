<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Abstracts\CommandBridgeAbstract;
use BrainCLI\Console\AiCommands\CustomRunCommand;
use BrainCLI\Support\Brain;
use Symfony\Component\Yaml\Yaml;

class ListMastersCommand extends CommandBridgeAbstract
{
    protected $signature = 'list:masters {agent=claude : Agent for which compilation} {--json : Output as JSON}';

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

        $files = $this->convertFiles($workingFiles, 'meta', Brain::allEnv());
        $json = [];

        foreach ($files as $file) {
            $id = $file['meta']['id'] ?? $file['id'];
            $json[$id] = $file['meta']['description'] ?? 'N/A';
        }

        ksort($json);

        if (!$json) {
            $this->components->warn('No enabled masters found.');
            return ERROR;
        }

        if ($this->option('json')) {
            return $json;
        }

        $table = [];
        foreach ($json as $id => $desc) {
            $table[] = [$id, $desc];
        }

        $this->table(['ID', 'Description'], $table);

        return OK;
    }
}

