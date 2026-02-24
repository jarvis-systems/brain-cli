<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use Illuminate\Console\Command;
use BrainCLI\Database\Migrations\MigrationRunner;

class McpMigrateCommand extends Command
{
    protected $signature = 'mcp:migrate';

    protected $description = 'Repair MCP credentials database schema (auto-runs on bootstrap)';

    public function handle(): int
    {
        MigrationRunner::run();
        $this->components->info('Database migrations completed.');

        return self::SUCCESS;
    }
}
