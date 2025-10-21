<?php

namespace BrainCLI\Composer;

use Composer\Script\Event;
use Illuminate\Events\Dispatcher;
use BrainCLI\Foundation\Application as McpApplication;
use BrainCLI\Database\DatabaseManager;
use BrainCLI\Database\Migrations\MigrationRunner;

class Hooks
{
    public static function postInstall(Event $event): void
    {
        self::runMigrations($event, 'Brain installed, execute migrations');
    }

    public static function postUpdate(Event $event): void
    {
        self::runMigrations($event, 'Brain updated, execute migrations');
    }

    private static function runMigrations(Event $event, string $msg): void
    {
        $io = $event->getIO();
        $io->write("<info>[BRAIN]</info> {$msg}...");

        // Guarantee Composer autoload (helpers/functions) is present
        $autoload = getcwd() . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }

        $container = new McpApplication();
        \Illuminate\Container\Container::setInstance($container);
        $container->instance('app', $container);

        $events = new Dispatcher($container);
        DatabaseManager::boot($container, $events);
        MigrationRunner::run();

        $io->write("<info>[BRAIN]</info> Migrations complete ✅");
    }
}
