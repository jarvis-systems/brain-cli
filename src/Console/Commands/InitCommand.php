<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Console\Traits\SelfDevGateTrait;
use BrainCLI\Support\Brain;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

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

        if ($result !== OK) {
            return ERROR;
        }

        $this->components->task('Creating .env file', function () {
            return copy(
                Brain::workingDirectory('.env.example'),
                Brain::workingDirectory('.env')
            );
        });

        $this->components->task('Creating .ai folder', function () {
            return $this->moveAiDirectory(
                Brain::workingDirectory('.ai'),
                Brain::projectDirectory('.ai')
            );
        });

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

    protected function moveAiDirectory(string $source, string $target): bool
    {
        if (! is_dir($source)) {
            return true;
        }

        if (! file_exists($target)) {
            return rename($source, $target);
        }

        if (! is_dir($target)) {
            return false;
        }

        foreach ($this->iterateDirectory($source) as $item) {
            $relativePath = substr($item->getPathname(), strlen($source) + 1);
            $targetPath = $target . DIRECTORY_SEPARATOR . $relativePath;

            if ($item->isDir()) {
                if (! is_dir($targetPath) && ! mkdir($targetPath, 0777, true) && ! is_dir($targetPath)) {
                    return false;
                }

                continue;
            }

            $targetDirectory = dirname($targetPath);

            if (! is_dir($targetDirectory) && ! mkdir($targetDirectory, 0777, true) && ! is_dir($targetDirectory)) {
                return false;
            }

            if (is_file($targetPath)) {
                continue;
            }

            if (! rename($item->getPathname(), $targetPath)) {
                return false;
            }
        }

        return $this->removeDirectory($source);
    }

    /**
     * @return \Traversable<int, SplFileInfo>
     */
    protected function iterateDirectory(string $directory): \Traversable
    {
        return new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
    }

    protected function removeDirectory(string $directory): bool
    {
        foreach ($this->iterateDirectory($directory) as $item) {
            if ($item->isDir()) {
                if (! rmdir($item->getPathname())) {
                    return false;
                }

                continue;
            }

            if (! unlink($item->getPathname())) {
                return false;
            }
        }

        return rmdir($directory);
    }
}
