<?php

declare(strict_types=1);

namespace BrainCLI\Console\Traits;

use BrainCLI\Support\Brain;
use ReflectionClass;

trait StubGeneratorTrait
{
    public function generateFile(
        string $relativeFilePath,
        string $stubName,
        array $replacements = [],
    ): bool {
        $content = $this->generateStub($stubName, $replacements);
        $fullPath = Brain::workingDirectory() . DS . $relativeFilePath;
        $directory = dirname($fullPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        if (file_exists($fullPath) && ! $this->option('force')) {
            $this->components->warn("File {$relativeFilePath} already exists. Use --force to overwrite.");
            return false;
        }
        $result = file_put_contents($fullPath, $content);
        if ($result === false) {
            $this->components->error("Failed to write file {$relativeFilePath}.");
            return false;
        }
        $this->components->success("File {$relativeFilePath} created successfully.");
        return true;
    }

    protected function generateStub(string $stubName, array $replacements = []): string
    {
        $commandDirectory = dirname((new ReflectionClass($this))->getFileName());
        $stubPath = $commandDirectory . DS . 'stubs' . DS . $stubName . '.stub';
        if (! file_exists($stubPath)) {
            throw new \RuntimeException("Stub file {$stubName}.stub does not exist in " . $commandDirectory . DS . "stubs" . DS);
        }
        $stubContent = file_get_contents($stubPath);
        return tag_replace($stubContent, $replacements, '{{ * }}');
    }
}
