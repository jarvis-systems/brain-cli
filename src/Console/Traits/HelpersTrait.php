<?php

declare(strict_types=1);

namespace BrainCLI\Console\Traits;

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

        if (! is_dir($workingDir)) {
            $this->components->error("The brain working directory does not exist: {$workingDir}");
            exit(ERROR);
        }

        if (! is_file($brainFile)) {
            $this->components->error("The Brain.php file does not exist in the working directory: {$brainFile}");
            exit(ERROR);
        }
    }
}
