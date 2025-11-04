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

    public function brainFile(): string;
    public function mcpFile(): string;
    public function brainFolder(): string;
    public function agentsFolder(): string;
    public function commandsFolder(): string;
    public function skillsFolder(): string;
}
