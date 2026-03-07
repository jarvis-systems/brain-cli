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
    private const CANON_DB_NAME = 'brain.db';

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

        $brainTaskDatabase = Brain::projectDirectory(['memory', 'tasks.db'], true);

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
        $envPath = Brain::getEnv('BRAIN_DB_PATH');
        if ($envPath && $envPath !== '') {
            return is_dir($envPath)
                ? rtrim($envPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::CANON_DB_NAME
                : $envPath;
        }

        return Brain::workingDirectory(['vendor', '__cache', self::CANON_DB_NAME]);
    }
}
