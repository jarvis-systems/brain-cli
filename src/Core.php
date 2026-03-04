<?php

declare(strict_types=1);

namespace BrainCLI;

use Bfg\Dto\Dto;

class Core
{
    protected string|null $versionCache = null;

    public function isDebug(): bool
    {
        return ServiceProvider::isDebug();
    }

    /**
     * Log an exception to stderr when debug mode is enabled.
     *
     * Emits: [prefix] ClassName: message
     * No-op when BRAIN_CLI_DEBUG is not set.
     */
    public function debugException(\Throwable $e, string $prefix = 'brain-debug'): void
    {
        if ($this->isDebug()) {
            error_log('[' . $prefix . '] ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    public function getEnv(string $name, mixed $default = null): mixed
    {
        if ($this->hasEnv($name)) {
            return ServiceProvider::getEnv($name);
        }
        return $default;
    }

    
    public function rawEnv(string|null $findName = null): array
    {
        return ServiceProvider::rawEnv($findName);
    }
    public function allEnv(string|null $findName = null): array
    {
        return ServiceProvider::allEnv($findName);
    }

    public function setEnv(string $name, mixed $value = null): bool
    {
        return ServiceProvider::setEnv($name, $value);
    }

    public function setting(string|array $name, mixed $default = null): mixed
    {
        $userHomeFolder = getenv('HOME') ?: getenv('USERPROFILE');
        $settingsPath = $userHomeFolder. DS . '.brain.json';
        $content = is_file($settingsPath) ? file_get_contents($settingsPath) : null;
        $settings = is_string($content) && Dto::isJson($content) ? json_decode($content, true) : [];

        if (is_array($name)) {
            foreach ($name as $key => $value) {
                data_set($settings, $key, $value);
            }
            return !! file_put_contents($settingsPath, json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return data_get($settings, $name, $default);
    }

    public function hasEnv(string $name): mixed
    {
        return ServiceProvider::hasEnv($name);
    }

    public function nodeDirectory(string|array $path = '', bool $relative = false): string
    {
        $path = is_array($path) ? implode(DS, $path) : $path;
        return $this->workingDirectory([
            'node',
            (! empty($path) ? ltrim($path, DS) : null)
        ], $relative);
    }

    public function workingDirectory(string|array $path = '', bool $relative = false): string
    {
        $path = is_array($path) ? implode(DS, $path) : $path;
        return $this->projectDirectory([
            to_string(config('brain.dir', '.brain')),
            (! empty($path) ? ltrim($path, DS) : null)
        ], $relative);
    }

    public function projectDirectory(string|array $path = '', bool $relative = false): string
    {
        if (! $relative) {
            $result = $this->findProjectRoot();

            if (! $result) {
                $result = getcwd();
            }

            if (! $result) {
                throw new \RuntimeException('Unable to determine the current working directory.');
            }
        } else {
            $result = '';
        }

        $path = is_array($path) ? implode(DS, array_filter($path)) : $path;
        return $result
            . (! empty($path) ? ($relative ? '' : DS) . ltrim($path, DS) : '');
    }

    protected function findProjectRoot(): ?string
    {
        $dir = getcwd();

        if ($dir === false) {
            return null;
        }

        while (true) {
            $candidate = $dir . DS
                . to_string(config('brain.dir', '.brain')) . DS
                . 'node' . DS
                . 'Brain.php';

            if (is_file($candidate)) {
                return $dir;
            }

            $parent = dirname($dir);

            if ($parent === $dir) {
                break;
            }

            $dir = $parent;
        }

        return null;
    }

    public function localDirectory(string|array $path = ''): string
    {
        $result = __DIR__ . DS . '..';
        $result = realpath($result);
        $path = is_array($path) ? implode(DS, array_filter($path)) : $path;
        return $result
            . (! empty($path) ? DS . ltrim($path, DS) : '');
    }

    public function getPackageName(): string
    {
        $composerPath = $this->localDirectory('composer.json');
        if (is_file($composerPath)) {
            $json = json_decode((string) file_get_contents($composerPath), true);
            if (is_array($json) && isset($json['name']) && is_string($json['name'])) {
                return $json['name'];
            }
        }
        return 'jarvis-brain/cli';
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
