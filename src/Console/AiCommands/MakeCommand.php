<?php

declare(strict_types=1);

namespace BrainCLI\Console\AiCommands;

use BrainCLI\Abstracts\CommandBridgeAbstract;
use BrainCLI\Enums\Agent;
use BrainCLI\Enums\Process\Type;
use BrainCLI\Support\Brain;
use Symfony\Component\Yaml\Yaml;

class MakeCommand extends CommandBridgeAbstract
{
    protected array $signatureParts = [
        '{name : The name of the AI client to make}',
        '{agent? : The AI agent for which to install the client}',
        '{--f|force : Force overwrite if the client already exists}',
    ];

    public function __construct() {
        $this->signature = "make";
        foreach ($this->signatureParts as $part) {
            $this->signature .= " " . $part;
        }
        $this->description = "Make AI client for the specified agent";
        parent::__construct();
    }

    protected function handleBridge(): int|array
    {
        $folder = Brain::projectDirectory('.ai');
        if (!is_dir($folder)) {
            mkdir($folder, 0755, true);
        }
        $name = $this->argument('name');
        $agentInput = $this->argument('agent');
        $filename = strtolower($name) . '.yaml';
        $file = $folder . DS . $filename;
        if (file_exists($file) && !$this->option('force')) {
            $this->components->error("Client file '{$filename}' already exists. Use --force to overwrite.");
            return ERROR;
        }
        $client = $agentInput ? Agent::from($agentInput)->value : '\$_default_client';
        $content = [
            '$schema' => '../vendor/jarvis-brain/core/agent-schema.json',
            'client' => $client
        ];
        $result = file_put_contents(
            $file,
            Yaml::dump($content)
        );
        if ($result) {
            $this->components->info("Client file '{$filename}' has been created for agent '{$agent->value}'.");
            return OK;
        }
        $this->components->error("Failed to create client file '{$filename}'.");
        return ERROR;
    }
}

