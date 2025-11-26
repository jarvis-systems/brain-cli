<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Console\Traits\CompilerBridgeTrait;
use BrainCLI\Enums\Agent;
use Illuminate\Console\Command;

class ListMastersCommand extends Command
{
    use CompilerBridgeTrait;

    protected $signature = 'list:masters {agent=claude : Agent for which compilation}';

    protected $description = 'List all available subagent masters for a given agent';

    protected $aliases = [];

    public function handle(): int
    {
        if ($error = $this->initCommand($this->argument('agent'))) {
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

