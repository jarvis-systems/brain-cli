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


        $command = [
            $php,
            $composer,
            'create-project',
            'jarvis-brain/node',
            $brainFolder,
            '--stability=dev'
        ];

        (new Process($command, $dir))
            ->setTimeout(null)
            ->setPty(true)
            ->run(function ($type, $output) {
                $this->output->write($output);
            });

        dd($command, $projectFolder);

        return 0;
    }
}

