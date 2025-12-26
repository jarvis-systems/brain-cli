<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Abstracts\CommandBridgeAbstract;

class ListIncludesCommand extends CommandBridgeAbstract
{
    protected $signature = 'list:includes {agent=claude : Agent for which compilation}';

    protected $description = 'List all available includes with their metadata.';

    protected $aliases = [];

    public function handleBridge(): int|array
    {
        $this->initFor($this->argument('agent'));

        $files = $this->convertFiles($this->getWorkingFiles('Includes'), 'meta');

        $result = [];

        foreach ($files as $file) {

            $result[] = [
                'Name' => $file['classBasename'],
                'Class' => $file['class'],
                'Purpose' => $file['meta']['purposeText'] ?? 'N/A',
            ];
        }

        return $result;
    }
}

