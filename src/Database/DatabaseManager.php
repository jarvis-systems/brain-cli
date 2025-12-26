<?php

declare(strict_types=1);

namespace BrainCLI\Database;

use BrainCLI\Core;
use BrainCLI\Support\Brain;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Container\Container;

class DatabaseManager
{
    public static function boot(Container $container, Dispatcher $events): void
    {
        $capsule = new Capsule($container);

        $dbPath = self::databasePath();

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
    }

    public static function databasePath(): string
    {
        $path = getenv('MCPC_DB_PATH');
        if ($path && $path !== '') {
            return is_dir($path) ? rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'mcpc.sqlite' : $path;
        }

        $home = rtrim((string) getenv('HOME'), DIRECTORY_SEPARATOR);
        if ($home === '') {
            $home = getcwd();
        }

        return $home . DIRECTORY_SEPARATOR . '.mcpc' . DIRECTORY_SEPARATOR . 'mcpc.sqlite';
    }
}
