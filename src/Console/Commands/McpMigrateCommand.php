<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use Illuminate\Console\Command;
use BrainCLI\Database\Migrations\MigrationRunner;

class McpMigrateCommand extends McpCommandAbstract
{
    protected $signature = 'mcp:migrate
        {--pretty : Pretty print JSON}
    ';

    protected $description = 'Repair MCP credentials database schema (auto-runs on bootstrap)';

    public function handle(): int
    {
        try {
            MigrationRunner::run();
            
            $this->outputResult([
                'ok' => true,
                'message' => 'Database migrations completed.',
            ]);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->outputError($e->getMessage(), 'MIGRATION_FAILED');
            return 1;
        }
    }
}
