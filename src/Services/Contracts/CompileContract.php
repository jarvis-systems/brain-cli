<?php

declare(strict_types=1);

namespace BrainCLI\Services\Contracts;

use BrainCLI\Console\Commands\CompileCommand;

interface CompileContract
{
    public function boot(CompileCommand $command): void;

    public function compile(): bool;

    public function brainFile(): string;
    public function mcpFile(): string;
    public function brainFolder(): string;
    public function agentsFolder(): string;
    public function commandsFolder(): string;
    public function skillsFolder(): string;
}
