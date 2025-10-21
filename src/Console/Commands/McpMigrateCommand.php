<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use Illuminate\Console\Command;
use BrainCLI\Database\Migrations\MigrationRunner;

class McpMigrateCommand extends Command
{
    protected $signature = 'migrate';

    protected $description = 'Run MCPC database migrations';

    public function handle(): int
    {
        MigrationRunner::run();
        $this->components->info('Database migrations completed.');

        return self::SUCCESS;
    }
}
