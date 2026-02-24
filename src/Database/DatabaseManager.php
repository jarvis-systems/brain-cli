<?php

declare(strict_types=1);

namespace BrainCLI\Database;

use BrainCLI\Core;
use BrainCLI\Database\Migrations\MigrationRunner;
use BrainCLI\Support\Brain;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Container\Container;

class DatabaseManager
{
    private const LEGACY_DB_NAME = 'credentials.sqlite';
    private const CANON_DB_NAME = 'brain.sqlite';

    public static function boot(Container $container, Dispatcher $events): void
    {
        $capsule = new Capsule($container);

        $dbPath = self::resolveDatabasePath();

        if (!is_dir(dirname($dbPath))) {
            mkdir(dirname($dbPath), 0777, true);
        }
        if (!file_exists($dbPath)) {
            touch($dbPath);
        }

        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => $dbPath,
            'prefix' => '',
        ]);

        $brainTaskDatabase = (new Core())->projectDirectory('memory/tasks.db', true);

        if (is_file($brainTaskDatabase)) {
            $capsule->addConnection([
                'driver' => 'sqlite',
                'database' => $brainTaskDatabase,
                'prefix' => '',
            ], 'tasks');
        }

        $capsule->setEventDispatcher($events);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        MigrationRunner::run();
    }

    public static function databasePath(): string
    {
        return self::resolveDatabasePath();
    }

    private static function resolveDatabasePath(): string
    {
        $envPath = getenv('MCPC_DB_PATH');
        if ($envPath && $envPath !== '') {
            return is_dir($envPath)
                ? rtrim($envPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::CANON_DB_NAME
                : $envPath;
        }

        $memoryDir = Brain::workingDirectory('memory');
        $canonPath = $memoryDir . DIRECTORY_SEPARATOR . self::CANON_DB_NAME;
        $legacyPath = $memoryDir . DIRECTORY_SEPARATOR . self::LEGACY_DB_NAME;

        if (file_exists($canonPath)) {
            return $canonPath;
        }

        if (file_exists($legacyPath)) {
            if (!rename($legacyPath, $canonPath)) {
                throw new \RuntimeException("Failed to migrate legacy DB: {$legacyPath} → {$canonPath}");
            }
            return $canonPath;
        }

        return $canonPath;
    }
}
