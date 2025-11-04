<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Support\Brain;
use Illuminate\Console\Command;

use Symfony\Component\Process\Process;

use function Illuminate\Support\php_binary;

class UpdateCommand extends Command
{
    protected $signature = 'update {--composer=composer : The composer binary to use}';

    protected $description = 'Update Brain';

    public function handle(): int
    {
        $workingDir = Brain::workingDirectory();

        if (! is_dir($workingDir)) {
            $this->components->error("The brain is not initialized in this directory: {$workingDir}");
            return 1;
        }

        $php = php_binary();
        $composer = $this->option('composer');
        $brainFolder = to_string(config('brain.dir', '.brain'));

        if ($composer === 'composer') {
            $command = [$composer];
        } else {
            $command = [$php, $composer];
        }

        $command = array_merge($command, ['update']);

        return (new Process($command, $brainFolder, ['COMPOSER_MEMORY_LIMIT' => '-1']))
            ->setTimeout(null)
            ->setPty(Process::isPtySupported())
            ->setTTY(Process::isTTYSupported())
            ->run(function ($type, $output) {
                $this->output->write($output);
            });
    }
}

