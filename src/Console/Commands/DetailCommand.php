<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use Illuminate\Console\Command;
use BrainCLI\Library;
use BrainCLI\Models\Credential;

class DetailCommand extends Command
{
    protected $signature = 'detail {name}';

    protected $description = 'Show details of an MCP server from the configuration';

    public function handle()
    {
        $name = $this->argument('name');

        $lib = Library::create();

        $lib->setNotFoundCredentialCallback([$this, 'askCredentials']);

        $server = $lib->get($name);

        if (! $server) {
            $this->components->error("MCP server '{$name}' not found in the configuration.");
            return;
        }

        $this->components->success('Found MCP server: ' . $name);

        $this->components->twoColumnDetail('Icon', $server->icon ?? 'N/A');
        $this->components->twoColumnDetail('Name', $server->name ?? 'N/A');
        $this->components->twoColumnDetail('Author', $server->author ?? 'N/A');
        $this->components->twoColumnDetail('License', $server->license ?? 'N/A');
        $this->components->twoColumnDetail('Repository', $server->repository ?? 'N/A');
        $this->components->twoColumnDetail('Description', $server->description ?? 'N/A');
        if ($server->required) {
            $this->components->twoColumnDetail('Required', json_encode($server->required));
        }
        if ($server->keywords) {
            $this->components->twoColumnDetail('Keywords', json_encode($server->keywords));
        } else {
            $this->components->twoColumnDetail('Keywords', 'N/A');
        }

        $this->components->info('Detail of MCP server: ' . $name);
    }
}

