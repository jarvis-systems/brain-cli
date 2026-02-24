<?php

namespace BrainCLI\Composer;

use Composer\Script\Event;
use Illuminate\Events\Dispatcher;
use BrainCLI\Foundation\Application as McpApplication;
use BrainCLI\Database\DatabaseManager;

class Hooks
{
    public static function postInstall(Event $event): void
    {
        self::bootstrapDatabase($event, 'Brain installed');
    }

    public static function postUpdate(Event $event): void
    {
        self::bootstrapDatabase($event, 'Brain updated');
    }

    private static function bootstrapDatabase(Event $event, string $msg): void
    {
        $io = $event->getIO();
        $io->write("<info>[BRAIN]</info> {$msg}, initializing database...");

        $autoload = getcwd() . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }

        defined("OK") || define("OK", 0);
        defined("ERROR") || define("ERROR", 1);
        defined("DS") || define("DS", DIRECTORY_SEPARATOR);

        $container = new McpApplication();
        \Illuminate\Container\Container::setInstance($container);
        $container->instance('app', $container);

        $events = new Dispatcher($container);
        DatabaseManager::boot($container, $events);

        $io->write("<info>[BRAIN]</info> Database ready ✅");
    }
}
