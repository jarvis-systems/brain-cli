<?php

declare(strict_types=1);

namespace BrainCLI\Console\AiCommands\Lab\Screens;

use BrainCLI\Console\AiCommands\Lab\Abstracts\ScreenAbstract;
use BrainCLI\Console\AiCommands\Lab\Dto\Context;

class Hello extends ScreenAbstract
{
    public function __construct()
    {
        parent::__construct(
            'hello',
            'AI Lab hello',
            '',
        );
    }

    public function main(Context $context, mixed $argument = null, mixed $var = null): Context
    {
        return $context;
    }
}
