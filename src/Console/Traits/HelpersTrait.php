<?php

declare(strict_types=1);

namespace BrainCLI\Console\Traits;

use BrainCLI\Exceptions\CommandTerminatedException;
use BrainCLI\Services\CompileLock;
use BrainCLI\Support\Brain;
use Illuminate\Support\Str;
use Laravel\Prompts\Concerns\Colors;

trait HelpersTrait
{
    use Colors;

    protected function extractInnerPathNameName(string $name): array
    {
        $path = str_replace('\\', DS, $name);
        $className = class_basename($name);
        $directory = str_replace($className, '', $path);
        $directory = array_map(function ($directory) {
            return Str::studly($directory);
        }, explode(DS, $directory));
        $nm = trim(implode('\\', $directory), '\\');
        return [
            implode(DS, $directory),
            $className,
            (! empty($nm) ? '\\' . $nm : '')
        ];
    }

    public function checkWorkingDir(): void
    {
        $workingDir = Brain::workingDirectory();
        $brainFile = Brain::workingDirectory(['node', 'Brain.php']);

        if (! is_dir($workingDir) || ! is_file($brainFile)) {
            // Try auto-switch to project root
            $cwd = getcwd();
            if ($cwd !== false) {
                $brainDirName = to_string(config('brain.dir', '.brain'));
                $root = CompileLock::findProjectRoot($cwd, $brainDirName);

                if ($root !== null && $root !== $cwd) {
                    chdir($root);
                    $this->outputComponents()->warn("Auto-switched to project root: {$root}");

                    return;
                }
            }

            if (! is_dir($workingDir)) {
                $this->components->error("The brain working directory does not exist: {$workingDir}");
                throw new CommandTerminatedException();
            }

            $this->components->error("The Brain.php file does not exist in the working directory: {$brainFile}");
            throw new CommandTerminatedException();
        }

        if (Brain::isDebug()) {
            $this->outputComponents()->info('Debug mode is ON. Outputting additional debug information.');
        }
    }
}
