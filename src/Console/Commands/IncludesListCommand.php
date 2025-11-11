<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Enums\Agent;
use BrainCLI\Services\Contracts\CompileContract;
use Illuminate\Support\Str;

class IncludesListCommand extends CompileCommand
{
    protected $signature = 'includes:list {agent=claude : Agent for which compilation}';

    protected $description = 'List all available includes with their metadata.';

    public function handle(): int
    {
        if ($error = $this->initCommand()) {
            return $error;
        }

        return $this->applyComplier(function () {

            $files1 = $this->getFile($this->getFileList('Includes'), 'meta');
            $files2 = $this->getFile($this->getFileList('Includes', true), 'meta');

            foreach (array_merge($files1, $files2) as $file) {
                $this->line("Name: {$file['classBasename']}");
                $this->line("Class: {$file['class']}");
                $this->line("Purpose: " . ($file['meta']['purpose'] ?? 'N/A'));
                $this->line('---');
            }

            return OK;
        });
    }
}

