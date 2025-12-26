<?php

declare(strict_types=1);

namespace BrainCLI\Console\AiCommands\Lab\Screens;

use BrainCLI\Console\AiCommands\Lab\Abstracts\ScreenAbstract;
use BrainCLI\Console\AiCommands\Lab\Dto\Context;

class Help extends ScreenAbstract
{
    public function __construct()
    {
        parent::__construct(
            'help',
            'Help\'s Screen',
            'This screen provides assistance and guidance on how to use the AI Lab features effectively.',
        );
    }

    public function main(Context $context, string|null $argument): Context
    {

        $this->line('Available Commands:');
        $this->line('/help - Display this help screen.');
        $this->line('/list - List all available AI Lab features.');
        $this->line('/config - Configure AI Lab settings.');
        $this->line('/run [feature] - Execute a specific AI Lab feature.');
        $this->line();
        $this->line('Use the commands with the appropriate modifier (/, !, $) to interact with the AI Lab.');
        $this->line('For more detailed information on a specific command, type "/help [command]".');
        $this->line();

        return $context
            ->pause()
            ->success('Help information displayed successfully.');
    }
}
