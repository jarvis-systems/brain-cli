<?php

declare(strict_types=1);

namespace BrainCLI\Services;

use BrainCLI\Support\Brain;

class LockFileFactory
{
    public static function get(string $name, mixed $default = null): mixed
    {
        $file = self::createLockFilePath();
        $content = is_file($file) ? file_get_contents($file) : false;
        if ($content === false) {
            return $default;
        }
        $data = json_decode($content, true);
        if (!is_array($data)) {
            return $default;
        }
        return data_get($data, $name, $default);
    }

    public static function save(string $name, mixed $value = null): bool
    {
        $file = self::createLockFilePath();
        $content = is_file($file) ? file_get_contents($file) : '';
        $data = $content ? json_decode($content, true) : [];
        if (!is_array($data)) {
            $data = [];
        }
        data_set($data, $name, $value);
        return !! file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public static function createLockFilePath(): string
    {
        return Brain::workingDirectory('brain.lock');
    }
}
