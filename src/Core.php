<?php

declare(strict_types=1);

namespace BrainCLI;

class Core
{
    protected string|null $versionCache = null;

    public function workingDirectory(): string
    {
        $result = getcwd();

        if (! $result) {
            throw new \RuntimeException('Unable to determine the current working directory.');
        }

        return $result . DS . to_string(config('brain.dir', '.brain'));
    }

    public function version(): string|null
    {
        if ($this->versionCache !== null) {
            return $this->versionCache;
        }

        $composerPath = dirname(__DIR__) . DS . 'composer.json';
        if (is_file($composerPath)) {
            $json = json_decode((string) file_get_contents($composerPath), true);
            if (is_array($json) && isset($json['version']) && is_string($json['version'])) {
                return $this->versionCache = $json['version'];
            }
        }

        return $this->versionCache = null;
    }
}
