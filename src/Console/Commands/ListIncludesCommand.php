<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

class ListIncludesCommand extends CompileCommand
{
    protected $signature = 'list:includes {agent=claude : Agent for which compilation}';

    protected $description = 'List all available includes with their metadata.';

    protected $aliases = [];

    public function handle(): int
    {
        if ($error = $this->initCommand()) {
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

