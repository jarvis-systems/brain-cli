<?php

declare(strict_types=1);

namespace BrainCLI\Console\AiCommands;

use BrainCLI\Abstracts\CommandBridgeAbstract;
use BrainCLI\Enums\Agent;
use BrainCLI\Enums\Process\Type;

class InstallCommand extends CommandBridgeAbstract
{
    protected array $signatureParts = [
        '{agent : The AI client to install}',
    ];

    public function __construct() {
        $this->signature = "install";
        foreach ($this->signatureParts as $part) {
            $this->signature .= " " . $part;
        }
        $this->description = "Install AI client and their dependencies";
        parent::__construct();
    }

    protected function handleBridge(): int|array
    {
        if ($this->argument('agent') == 'all') {

            foreach (Agent::enabledCases() as $case) {

                $this->initFor($case);

                $result = $this->client
                    ->process(Type::INSTALL)
                    ->install()
                    ->open();

                if ($result !== OK) {
                    return $result;
                }
            }

            return OK;
        }

        $this->initFor($this->argument('agent'));

        return $this->client
            ->process(Type::INSTALL)
            ->install()
            ->open();
    }
}

