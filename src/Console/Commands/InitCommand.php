<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Support\Brain;
use Illuminate\Console\Command;

use Symfony\Component\Process\Process;

use function Illuminate\Support\php_binary;

class InitCommand extends Command
{
    protected $signature = 'init {--composer=composer : The composer binary to use}';

    protected $description = 'Initialize Brain';

    public function handle(): int
    {
        $workingDir = Brain::workingDirectory();

        if (is_dir($workingDir)) {
            $this->components->error("The brain already initialized in this directory: {$workingDir}");
            return 1;
        }

        $php = php_binary();
        $composer = $this->option('composer');
        $brainFolder = to_string(config('brain.dir', '.brain'));
        $projectFolder = Brain::projectDirectory();

        if ($composer === 'composer') {
            $command = [$composer];
        } else {
            $command = [$php, $composer];
        }

        $command = array_merge($command, [
            'create-project',
            'jarvis-brain/node',
            $brainFolder,
            '--stability=dev'
        ]);

        (new Process($command, $projectFolder, ['COMPOSER_MEMORY_LIMIT' => '-1']))
            ->setTimeout(null)
            ->setPty(Process::isPtySupported())
            ->setTTY(Process::isTTYSupported())
            ->run(function ($type, $output) {
                $this->output->write($output);
            });

        $this->call('make:mcp', [
            'name' => 'context7',
        ]);

        $this->call('make:mcp', [
            'name' => 'vector-memory',
        ]);

        $this->call('make:mcp', [
            'name' => 'sequential-thinking',
        ]);

        return OK;
    }
}

