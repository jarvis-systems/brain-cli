<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Abstracts\CommandBridgeAbstract;
use BrainCLI\Support\Brain;
use Symfony\Component\Process\Process;

use function Illuminate\Support\php_binary;

class UpdateCommand extends CommandBridgeAbstract
{
    protected $signature = 'update 
        {--composer=composer : The composer binary to use}
        {--all : Update all cli, agents and compile after update}
        {--cli : Update Brain CLI after update}
        {--compile : Compile after update}
    ';

    protected $description = 'Update Brain';

    public function handleBridge(): int|array
    {
        $composer = $this->option('composer');
        $brainFolder = Brain::workingDirectory();

        if ($composer === 'composer') {
            $command = [$composer];
        } else {
            $command = [php_binary(), $composer];
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

        if ($this->option('cli') || $this->option('all')) {
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
        }

        if ($this->option('compile') || $this->option('all')) {
            $this->call('compile');
        }

        $this->components->info("Brain has been updated successfully.");

        return OK;
    }
}

