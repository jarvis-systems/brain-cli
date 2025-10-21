<?php

declare(strict_types=1);

namespace BrainCLI\Config;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;

class ConfigManager
{
    public static function boot(Container $container, ?string $path = null): void
    {
        $configPath = $path
            ?? (getenv('BRAIN_CONFIG_PATH') ?: (dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'configs'));

        $repository = new ConfigRepository([]);

        if (is_dir($configPath)) {
            foreach (glob($configPath . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
                $key = basename($file, '.php');
                $data = require $file;
                if (is_array($data)) {
                    $repository->set($key, $data);
                }
            }
        }

        $container->instance('config', $repository);
    }
}

