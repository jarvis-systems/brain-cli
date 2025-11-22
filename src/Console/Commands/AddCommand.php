<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Support\Brain;
use Illuminate\Console\Command;
use BrainCLI\Library;
use BrainCLI\McpFileDetector;
use BrainCLI\Models\Credential;

class AddCommand extends Command
{
    protected $signature = 'add {name} {--agent= : The agent to use (claude, codex, gemini, qwen)}';

    protected $description = 'Add a new MCP server to the configuration';

    public function handle()
    {
        $name = $this->argument('name');

        try {
            $serverInfo = Library::create()
                ->setNotFoundCredentialCallback([$this, 'askCredentials'])
                ->get($name, true);
        } catch (\Throwable $e) {
            if (Brain::isDebug()) {
                dd($e);
            }
            $this->components->error($e->getMessage());
            return ERROR;
        }

        $fileDetector = McpFileDetector::create();

        try {
            $fileDetector->addServer($serverInfo);
        } catch (\Throwable $e) {
            if (Brain::isDebug()) {
                dd($e);
            }
            $this->components->error($e->getMessage());
            return 1;
        }

        dd($fileDetector);

        //$this->components->info("MCP server {$server->name} added successfully.");
    }

    public function askCredentials(string $name, mixed $default): string|null
    {
        if ($default && is_array($default) && count($default) > 0) {
            $value = $this->choice("Enter the value for credential '{$name}'", $default, 0);
        } elseif ($default && is_string($default)) {
            $value = $this->ask("Enter the value for credential '{$name}'", $default);
        } else {
            $value = $this->ask("Enter the value for credential '{$name}'");
        }
        return $value;
    }
}

