<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Console\Traits\SelfDevGateTrait;
use BrainCLI\Support\Brain;
use Illuminate\Console\Command;

use Symfony\Component\Process\Process;

use function Illuminate\Support\php_binary;

class InitCommand extends Command
{
    use SelfDevGateTrait;

    protected $signature = 'init {--composer=composer : The composer binary to use} {--scaffold : Scaffold default MCPs after bootstrap}';

    protected $description = 'Initialize Brain';

    public function handle(): int
    {
        $workingDir = Brain::workingDirectory();

        if (is_dir($workingDir)) {
            $this->components->error("The brain already initialized in this directory: {$workingDir}");
            return ERROR;
        }

        if ($this->option('scaffold') && ! $this->requireSelfDev()) {
            return ERROR;
        }

        $composer = $this->option('composer');
        $brainFolder = to_string(config('brain.dir', '.brain'));
        $projectFolder = Brain::projectDirectory();

        if ($composer === 'composer') {
            $command = [$composer];
        } else {
            $command = [php_binary(), $composer];
        }

        $command = array_merge($command, [
            'create-project',
            'jarvis-brain/node',
            $brainFolder,
            '--stability=dev'
        ]);

        $result = (new Process($command, $projectFolder, ['COMPOSER_MEMORY_LIMIT' => '-1']))
            ->setTimeout(null)
            ->setPty(Process::isPtySupported())
            ->setTTY(Process::isTTYSupported())
            ->run(function ($type, $output) {
                $this->output->write($output);
            });

        $this->components->task('Creating .env file', function () {
            return copy(
                Brain::workingDirectory('.env.example'),
                Brain::workingDirectory('.env')
            );
        });

        $this->components->task('Creating .ai folder', function () {
            return rename(
                Brain::workingDirectory('.ai'),
                Brain::projectDirectory('.ai')
            );
        });

        if ($result !== OK) {
            return ERROR;
        }

        if ($this->option('scaffold')) {
            foreach (to_array(config('brain.mcp.default', [])) as $name) {
                $this->call('make:mcp', compact('name'));
            }

            $this->components->info('Bootstrap + scaffold complete.');
        } else {
            $this->components->info('Bootstrap complete. To scaffold defaults: enable SELF_DEV_MODE and run: brain init --scaffold');
        }

        return OK;
    }
}

