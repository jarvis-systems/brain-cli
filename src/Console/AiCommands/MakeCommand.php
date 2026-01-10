<?php

declare(strict_types=1);

namespace BrainCLI\Console\AiCommands;

use BrainCLI\Abstracts\CommandBridgeAbstract;
use BrainCLI\Console\Traits\StubGeneratorTrait;
use BrainCLI\Enums\Agent;
use BrainCLI\Enums\Process\Type;
use BrainCLI\Support\Brain;
use Symfony\Component\Yaml\Yaml;

class MakeCommand extends CommandBridgeAbstract
{
    use StubGeneratorTrait;

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
        $client = $agentInput ? Agent::from($agentInput)->value : '\$_default_client';

        if ($name == 'executor') {
            $stubNameAdd = '.executor';
        } elseif ($name == 'validator') {
            $stubNameAdd = '.validator';
        }

        $result = $this->generateFile(
            $file,
            'ai' . ($stubNameAdd ?? ''),
            ['client' => $client]
        );

        if ($result) {
            return OK;
        }
        return ERROR;
    }
}

