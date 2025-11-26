<?php

declare(strict_types=1);

namespace BrainCLI\Services\Contracts;

use Illuminate\Support\Collection;

interface CompileContract
{
    /**
     * @param  Collection<int, array{'id': non-empty-string, 'file': non-empty-string, 'meta': array<string, string>, 'class': class-string<\Bfg\Dto\Dto>, 'namespace': non-empty-string, 'classBasename': non-empty-string, 'format': 'xml'|'json'|'yaml'|'toml', 'structure': string}>  $files
     * @return void
     */
    public function boot(Collection $files): void;

    public function compile(): bool;
    public function compiled(): void;

    /**
     * @return array<non-empty-string, non-empty-string>
     */
    public function formats(): array;

    public function brainFile(): string;
    public function mcpFile(): string;
    public function brainFolder(): string;
    public function agentsFolder(): string;
    public function commandsFolder(): string;
    public function skillsFolder(): string;
    public function compileVariables(): array;

    public function compileAgentPrefix(): string|array;
    public function compileStoreVarPrefixPrefix(): string|array;

    public function commandEnv(): array;
    public function run(): array;
    public function exit(): void;
    public function resume(): array;
    public function update(): array;
}
