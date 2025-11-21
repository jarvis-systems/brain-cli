<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Console\Traits\HelpersTrait;
use BrainCLI\Support\Brain;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

use function Illuminate\Support\php_binary;

class UpdateCommand extends Command
{
    use HelpersTrait;

    protected $signature = 'update {--composer=composer : The composer binary to use}';

    protected $description = 'Update Brain';

    public function handle(): int
    {
        $this->checkWorkingDir();

        $php = php_binary();
        $composer = $this->option('composer');
        $brainFolder = to_string(config('brain.dir', '.brain'));

        if ($composer === 'composer') {
            $command = [$composer];
        } else {
            $command = [$php, $composer];
        }

        $commandUpdateBrain = array_merge($command, ['update']);

        $resultOfProjectUpdate = (new Process($commandUpdateBrain, $brainFolder, ['COMPOSER_MEMORY_LIMIT' => '-1']))
            ->setTimeout(null)
            ->setPty(Process::isPtySupported())
            ->setTTY(Process::isTTYSupported())
            ->run(function ($type, $output) {
                $this->output->write($output);
            });

        if ($resultOfProjectUpdate !== 0) {
            $this->components->error("Failed to update Brain dependencies.");
            return $resultOfProjectUpdate;
        }

        $localPackageName = Brain::getPackageName();
        $commandUpdateBrainCLI = array_merge($command, ['global', 'update', $localPackageName]);

        $resultOfLocalUpdate = (new Process($commandUpdateBrainCLI, $brainFolder, ['COMPOSER_MEMORY_LIMIT' => '-1']))
            ->setTimeout(null)
            ->setPty(Process::isPtySupported())
            ->setTTY(Process::isTTYSupported())
            ->run(function ($type, $output) {
                $this->output->write($output);
            });

        if ($resultOfLocalUpdate !== 0) {
            $this->components->error("Failed to update Brain CLI dependencies.");
            return $resultOfLocalUpdate;
        }
        $this->components->info("Brain has been updated successfully.");
        return OK;
    }
}

