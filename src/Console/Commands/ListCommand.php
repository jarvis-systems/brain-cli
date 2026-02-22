<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use Illuminate\Console\Command;
use BrainCLI\Library;
use BrainCLI\Models\Credential;

class ListCommand extends Command
{
    protected $signature = 'list {search?}';

    protected $description = 'The list of MCP servers';

    public function handle(): int
    {
        $all = Library::create()->all(
            $this->argument('search')
        );

        if (count($all) === 0) {
            $this->components->warn('No MCP servers found in the configuration.');
            return 0;
        } else {
            $this->components->success('List of MCP servers:');
        }

        $all->map(function (object $server) {
            $this->components->twoColumnDetail(
                '[ ' . $server->icon . ' ] ' . $server->name . ' (' . $server->license . ')',
                $server->description ?? ''
            );
        });

        $this->components->info('Total MCP servers: ' . $all->count());

        return 0;
    }


}

