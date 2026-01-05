<?php

declare(strict_types=1);

namespace BrainCLI\Enums\Process;

use BrainCLI\Console\AiCommands\CustomRunCommand;
use BrainCLI\Console\AiCommands\RunCommand;

enum Type: string
{
    case RUN = 'run';
    case RESUME = 'resume';
    case CONTINUE = 'continue';
    case INSTALL = 'install';
    case UPDATE = 'update';

    public function label(): string
    {
        return match ($this) {
            self::RUN => 'The run of a process',
            self::RESUME => 'Resuming a process',
            self::CONTINUE => 'Continuing a process',
            self::INSTALL => 'Installation process',
            self::UPDATE => 'Update process',
        };
    }

    public function isProgramm(): bool
    {
        return match ($this) {
            self::RUN, self::RESUME, self::CONTINUE => true,
            default => false,
        };
    }

    public function isInstall(): bool
    {
        return match ($this) {
            self::INSTALL => true,
            default => false,
        };
    }

    public function isUpdate(): bool
    {
        return match ($this) {
            self::UPDATE => true,
            default => false,
        };
    }

    public static function detect(RunCommand $command): Type
    {
        return match (true) {
            !! $command->option('install') => self::INSTALL,
            !! $command->option('update') => self::UPDATE,
            !! $command->option('resume') => self::RESUME,
            !! $command->option('continue') => self::CONTINUE,
            default => self::RUN,
        };
    }

    public static function customDetect(array $data): Type
    {
        return match (true) {
            !! ($data['install'] ?? false) => self::INSTALL,
            !! ($data['update'] ?? false) => self::UPDATE,
            !! ($data['resume'] ?? false) => self::RESUME,
            !! ($data['continue'] ?? false) => self::CONTINUE,
            default => self::RUN,
        };
    }
}
