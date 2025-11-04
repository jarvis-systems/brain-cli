<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Console\Traits\StubGeneratorTrait;
use BrainCLI\Models\Credential;
use BrainCLI\Support\Brain;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\VarExporter\VarExporter;

class MakeMcpCommand extends Command
{
    use StubGeneratorTrait;

    protected $signature = 'make:mcp 
        {name} 
        {--source= : The command, URL, or source for the MCP}
        {--parameters= : Additional parameters such as args or headers (JSON format)}
        {--force : Overwrite existing files}
        {--http : Create an HTTP-based MCP (default is STDIO)}
        {--sse : Create an SSE-based MCP}
    ';

    protected $description = 'Create a new MCP server class';

    protected array $const = [];

    /**
     * @throws \Symfony\Component\VarExporter\Exception\ExceptionInterface
     */
    public function handle(): int
    {
        $this->addDefaultConstants();

        $info = $this->findInMarket($this->argument('name'));

        return $this->generateFile(
            ...$this->generateParameters($info, $this->generateData($info))
        ) ? OK : ERROR;
    }

    /**
     * @param  array{'name': non-empty-string, 'config': array{'type': non-empty-string, 'command': non-empty-string|null, 'url': non-empty-string|null, 'args': list<non-empty-string>|null, 'headers': array<non-empty-string, non-empty-string>|null}}  $info
     * @param  array{'source': non-empty-string, 'parameters': array<int|non-empty-string, non-empty-string>, 'className': non-empty-string}  $data
     * @return array{file: non-empty-string, stub: non-empty-string, replacements: array<non-empty-string, non-empty-string>}
     * @throws \Symfony\Component\VarExporter\Exception\ExceptionInterface
     */
    protected function generateParameters(array $info, array $data): array
    {
        return [
            'file' => "node/Mcp/{$data['className']}.php",
            'stub' => "mcp.{$info['config']['type']}",
            'replacements' => [
                'mcpId' => Str::snake($info['name'], '-'),
                'namespace' => 'BrainNode\\Mcp',
                'className' => $data['className'],
                'source' => $this->tabsMultiline(VarExporter::export($data['source'])),
                'parameters' => $this->tabsMultiline(VarExporter::export($data['parameters'])),
            ]
        ];
    }

    /**
     * @param  array{'name': non-empty-string, 'config': array{'type': non-empty-string, 'command': non-empty-string|null, 'url': non-empty-string|null, 'args': list<non-empty-string>|null, 'headers': array<non-empty-string, non-empty-string>|null}}  $info
     * @return array{'source': non-empty-string, 'parameters': array<int|non-empty-string, non-empty-string>, 'className': non-empty-string}
     */
    protected function generateData(array $info): array
    {
        $source = $this->option('source') ?? '';
        $parameters = $this->option('parameters') ? json_decode($this->option('parameters'), true) : [];

        $isStdio = $info['config']['type'] === 'stdio';
        $sourceKey = $isStdio ? "command" : "url";
        $parametersKey = $isStdio ? "args" : "headers";

        $source = $info['config'][$sourceKey] ?? $source;
        $parameters = array_merge(
            ($info['config'][$parametersKey] ?? []), $parameters
        );
        $className = Str::studly($info['name']);
        if (! str_ends_with($className, 'Mcp')) {
            $className .= 'Mcp';
        }

        return compact('source', 'parameters', 'className');
    }

    protected function addDefaultConstants(): void
    {
        $this->const['PROJECT_DIRECTORY'] = Brain::projectDirectory();
        $this->const['BRAIN_DIRECTORY'] = Brain::workingDirectory();
        $this->const['TIMESTAMP'] = time();
        $this->const['DATE_TIME'] = date('Y-m-d H:i:s');
        $this->const['DATE'] = date('Y-m-d');
        $this->const['TIME'] = date('H:i:s');
        $this->const['YEAR'] = date('Y');
        $this->const['MONTH'] = date('m');
        $this->const['DAY'] = date('d');
        $this->const['UNIQUE_ID'] = uniqid();
    }

    protected function tabsMultiline(string $value): string
    {
        $lines = [];
        foreach (explode("\n", $value) as $num => $line) {
            $lines[] = ($num ? str_repeat(' ', 8) : '') . $line;
        }
        return implode("\n", $lines);
    }

    /**
     * @return array{'name': non-empty-string, 'config': array{'type': non-empty-string, 'command': non-empty-string|null, 'url': non-empty-string|null, 'args': list<non-empty-string>|null, 'headers': array<non-empty-string, non-empty-string>|null}}
     */
    protected function findInMarket($name): array
    {
        $hashName = preg_replace('/[^a-z0-9]+/', '', strtolower($name));

        /** @var array<int, non-empty-string> $markets */
        $markets = config('brain.mcp.markets', []);
        $mcpListConfigs = [];

        foreach ($markets as $market) {
            $marketJson = file_get_contents($market);
            if ($marketJson === false) {
                continue;
            }
            $marketData = json_decode($marketJson, true);
            if (! is_array($marketData)) {
                continue;
            }
            $mcpListConfigs = array_merge($mcpListConfigs, $marketData);
        }

        foreach ($mcpListConfigs as $configName => $config) {
            $hashConfigName = preg_replace('/[^a-z0-9]+/', '', strtolower($configName));
            if ($hashConfigName === $hashName) {
                return [
                    'name' => $configName,
                    'config' => $this->variablesDetectArray($config),
                ];
            }
        }
        if ($this->option('http')) {
            $type = 'http';
        } elseif ($this->option('sse')) {
            $type = 'sse';
        } else {
            $type = 'stdio';
        }

        return [
            'name' => $name,
            'config' => [
                'type' => $type,
                'command' => null,
                'url' => null,
                'args' => null,
                'headers' => null,
            ],
        ];
    }

    protected function variablesDetectArray(array $config): array
    {
        $newConfig = [];
        foreach ($config as $key => $value) {
            if (is_string($key)) {
                $key = $this->variablesDetectString($key);
            }
            if (is_string($value)) {
                $newConfig[$key] = $this->variablesDetectString($value);
            } elseif (is_array($value)) {
                $newConfig[$key] = $this->variablesDetectArray($value);
            } else {
                $newConfig[$key] = $value;
            }
        }
        return $newConfig;
    }

    protected function variablesDetectString(string $value): string
    {
        return preg_replace_callback('/<([A-Za-z0-9_\-]+)\.([A-Za-z0-9_\-]+)(\(.*\))?>/', function ($matches) {
            $getMethod = $matches[1] . "Variable";
            $varName = $matches[2];
            $varArgs = isset($matches[3]) ? explode(':', trim($matches[3], '()')) : [];
            $varMethod = null;
            if (isset($varArgs[0])) {
                $varMethod = $varArgs[0];
                array_shift($varArgs);
            }
            foreach ($varArgs as $key => $arg) {
                if (str_contains($arg, '|')) {
                    $varArgs[$key] = array_filter(explode('|', $arg));
                }
            }

            if (method_exists($this, $getMethod)) {
                return $this->{$getMethod}($matches[0], $varName, $varMethod, $varArgs);
            }

            return $matches[0];
        }, $value) ?? $value;
    }

    protected function inputVariable(string $old, string $name, string|null $varMethod, array $args = []): string
    {
        if (! $varMethod) {
            $varMethod = 'askInputVariable';
        } else {
            $varMethod = $varMethod . 'InputVariable';
        }
        if (! method_exists($this, $varMethod)) {
            return $old;
        }

        $credential = Credential::query()->where('name', $name)->first();

        $value = $this->{$varMethod}($name, $credential, ...$args);

        Credential::query()->updateOrCreate(['name' => $name], ['value' => $value]);

        return $value;
    }

    protected function constVariable(string $old, string $name, string|null $varMethod, array $args = [])
    {
        if (! isset($this->const[$name]) || ($varMethod && ! method_exists(Str::class, $varMethod))) {
            return $old;
        }
        $value = $this->const[$name];
        if ($varMethod) {
            $value = Str::{$varMethod}($value, ...$args);
        }
        return $value;
    }

    protected function askInputVariable(string $name, Credential|null $credential): string
    {
        $value = $this->components->ask('Please provide value for ' . $name, $credential?->value);
        if (! $value) {
            return $this->askInputVariable($name, $credential);
        }
        return $value;
    }

    protected function selectInputVariable(string $name, Credential|null $credential, string|array $variants): string
    {
        $variants = (array) $variants;
        $value = $this->components->choice('Please provide value for ' . $name, $variants, $credential?->value);
        if (! $value) {
            return $this->selectInputVariable($name, $credential, $variants);
        }
        return $value;
    }

    protected function multiselectInputVariable(string $name, Credential|null $credential, string|array $variants, string $separator = ','): string
    {
        $variants = (array) $variants;
        if (count($variants) > 1) {
            $all = implode($separator, $variants);
            $variants = [$all, ...$variants];
        }
        $value = $this->components->choice('Please provide value for ' . $name . ' (Multiselect)', $variants, $credential?->value, multiple: true);
        if (! $value) {
            return $this->multiselectInputVariable($name, $credential, $variants, $separator);
        }
        return is_array($value) ? implode($separator, $value) : $value;
    }
}

