<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Console\Traits\CompilerBridgeTrait;
use Illuminate\Console\Command;

class ListIncludesCommand extends Command
{
    use CompilerBridgeTrait;

    protected $signature = 'list:includes {agent=claude : Agent for which compilation}';

    protected $description = 'List all available includes with their metadata.';

    protected $aliases = [];

    public function handle(): int
    {
        if ($error = $this->initCommand($this->argument('agent'))) {
            return $error;
        }

        return $this->applyComplier(function () {

            $files = $this->convertFiles($this->getWorkingFiles('Includes'), 'meta');

            foreach ($files as $file) {
                $this->line("Name: {$file['classBasename']}");
                $this->line("Class: {$file['class']}");
                $this->line("Purpose: " . ($file['meta']['purposeText'] ?? 'N/A'));
                $this->line('---');
            }

            return OK;
        });
    }
}

