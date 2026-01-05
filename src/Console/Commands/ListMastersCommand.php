<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Abstracts\CommandBridgeAbstract;
use BrainCLI\Console\AiCommands\CustomRunCommand;
use BrainCLI\Support\Brain;
use Symfony\Component\Yaml\Yaml;

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

        $filename = getenv('BRAIN_AI_AGENT_NAME');

        if ($filename) {
            $file = Brain::workingDirectory(['agents', $filename]);
            if (is_file($file)) {
                if (str_ends_with($filename, '.yaml') || str_ends_with($filename, '.yml')) {
                    if ($content = file_get_contents($file)) {
                        $hash = md5($filename);
                        $data = Yaml::parse($content, Yaml::PARSE_CUSTOM_TAGS);
                        if (! isset($data['id'])) {
                            $data['id'] = $hash;
                        }
                        if (! isset($data['args'])) {
                            $data['args'] = [];
                        }
                        $callName = pathinfo($filename, PATHINFO_FILENAME);
                        if ($data && is_array($data) && isset($data['client']) && $data['client']) {
                            $obj = $this->laravel->make(CustomRunCommand::class, compact(
                                'callName', 'data', 'filename'
                            ))->getData();

                            if (isset($obj['env'])) {
                                // set environment variables
                                foreach ($obj['env'] as $key => $value) {
                                    putenv("{$key}={$value}");
                                }
                            }
                        }
                    }
                }
            }
        }

        $files = $this->convertFiles($workingFiles, 'meta', $obj['env'] ?? []);
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

