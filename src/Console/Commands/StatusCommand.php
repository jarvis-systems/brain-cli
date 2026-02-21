<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Models\Task;
use Illuminate\Console\Command;

class StatusCommand extends Command
{
    protected $signature = 'status';

    protected $description = 'The work status';

    public function handle()
    {
        $today = [
            now()->startOfDay()->toDateTimeString(),
            now()->endOfDay()->toDateTimeString(),
        ];
        $tasks = Task::all();

        $first = $tasks->first();
        $this->line((string) json_encode($first, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return 0;
    }
}

