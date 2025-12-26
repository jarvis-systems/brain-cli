<?php

declare(strict_types=1);

namespace BrainCLI\Abstracts\Contracts\Client;

use BrainCLI\Dto\Compile\Puzzle;
use BrainCLI\Enums\Agent;
use BrainCLI\Enums\Process\Type;
use BrainCLI\Services\ProcessFactory;
use Illuminate\Support\Collection;

interface ClientContract
{
    public function agent(): Agent;


    public function folder(): string;
    public function file(): string;
    public function settingsFile(): string;
    public function mcpFile(): string;
    public function agentsFolder(): string;
    public function commandsFolder(): string;
    public function skillsFolder(): string;


    public function compile(Collection $files): bool;
    public function compileVariables(): array;
    public function compileFormats(): array;
    public function compilePuzzle(): Puzzle;
    public function compileDone(): void;


    public function process(Type $type): ProcessFactory;
    public function processRunCallback(ProcessFactory $factory): void;
    public function processHostedCallback(ProcessFactory $factory): void;
    public function processExitCallback(ProcessFactory $factory, int $exitCode): void;
    public function processParseOutput(ProcessFactory $factory, string $output): array;
}
