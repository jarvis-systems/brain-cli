<?php

declare(strict_types=1);

namespace BrainCLI\Console\AiCommands;

use BrainCLI\Abstracts\CommandBridgeAbstract;
use BrainCLI\Console\Commands\CompileCommand;
use BrainCLI\Enums\Agent;
use BrainCLI\Enums\Process\Type;
use BrainCLI\Support\Brain;
use Illuminate\Support\Arr;
use Symfony\Component\Yaml\Yaml;

class CustomRunCommand extends CommandBridgeAbstract
{
    protected array $signatureParts = [

    ];

    protected mixed $accumulateCallback = null;

    public function __construct(
        protected string $callName,
        protected Agent $agent,
        protected array $data,
    ) {
        $this->variablesDetectArrayData();
        $this->aliases = $this->data['aliases'] ?? [];
        $this->signature = $this->data['name'] ?? $this->callName;
        foreach ($this->signatureParts as $part) {
            $this->signature .= " " . $part;
        }
        $this->description = $data['description'] ?? 'Custom AI agent command';
        parent::__construct();
    }

    public function setAccumulateCallback(callable $accumulateCallback): static
    {
        $this->accumulateCallback = $accumulateCallback;

        return $this;
    }

    protected function handleBridge(): int|array
    {
        $this->initFor($this->agent);

        $compileNeeded = isset($this->data['env'])
            && is_array($this->data['env'])
            && count($this->data['env']);

        if ($compileNeeded) {
            $this->call(new CompileCommand(), [
                'agent' => $this->agent->value,
                '--env' => json_encode($this->data['env'], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
                '--silent' => true,
            ]);
        }

        $type = Type::customDetect($this->data);

        $process = $this->client->process($type);

        $options = $process->payload->defaultOptions([
            'ask' => $this->data['params']['ask'] ?? null,
            'json' => $this->data['params']['json'] ?? ($this->data['params']['serialize'] ?? false),
            'serialize' => $this->data['params']['serialize'] ?? false,
            'yolo' => $this->data['params']['yolo'] ?? false,
            'model' => $this->data['params']['model'] ?? null,
            'system' => $this->data['params']['system'] ?? null,
            'systemAppend' => $this->data['params']['system-append'] ?? ($this->data['params']['systemAppend'] ?? null),
            'schema' => $this->data['params']['schema'] ?? null,
            'dump' => $this->data['params']['dump'] ?? false,
            'resume' => $this->data['params']['resume'] ?? null,
            'continue' => $this->data['params']['continue'] ?? false,
            'no-mcp' => $this->data['params']['no-mcp'] ?? ($this->data['params']['noMcp'] ?? false),
        ]);

        $process
            ->program()
            ->askWhen($options['ask'], $options['ask'])
            ->jsonWhen($options['json'])
            ->resumeWhen($options['resume'], $options['resume'])
            ->continueWhen($options['continue'])
            ->yoloWhen($options['yolo'])
            ->modelWhen($options['model'], $options['model'])
            ->systemWhen($options['system'], $options['system'])
            ->systemAppendWhen($options['systemAppend'], $options['systemAppend'])
            ->noMcpWhen($options['no-mcp'])
            ->schemaWhen($options['schema'], function () use ($options) {
                if (is_array($jsonSchema = json_decode($options['schema'], true))) {
                    return $jsonSchema;
                }
                $this->components->error("The provided schema is not a valid JSON.");
                exit(1);
            })->dump($options['dump']);

        if ($process->reflection->isUsed('json')) {
            return $process->run(function (string $output) use ($process, $options) {
                $result = $this->client->processParseOutput($process, $output);
                foreach ($result as $dto) {
                    if ($this->accumulateCallback) {
                        call_user_func($this->accumulateCallback, $dto, $this);
                    } elseif ($options['dump']) {
                        dump($dto);
                    } elseif ($options['serialize']) {
                        $this->line($dto->toSerialize());
                    } else {
                        $this->line($dto->toJson(JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
                    }
                }
            });
        }

        return $process->open(function () use ($compileNeeded) {
            if ($compileNeeded) {
                $this->call(new CompileCommand(), [
                    'agent' => $this->agent->value,
                    '--silent' => true,
                ]);
            }
        });
    }

    protected function variablesDetectArrayData(): void
    {
        $data = Arr::dot($this->data);
        $new = [];
        $oldCwd = getcwd();
        chdir(Brain::workingDirectory('agents'));

        foreach ($data as $path => $value) {
            if ($path === '$schema') {
                continue;
            }
            if (
                preg_match('/^(.*)(\$.*)$/', $path, $matches)
                || preg_match('/^(.*)(\+.*)$/', $path, $matches)
            ) {
                //dd($path, $matches[1]);
                $mergeData = $this->variablesDetectString($matches[2]);

                if (is_array($mergeData)) {
                    $newData = Arr::dot($mergeData);
                    foreach ($newData as $newPath => $newValue) {
                        data_set($this->data, $matches[1] . $newPath, $newValue);
                        data_set($new, $matches[1] . $newPath, $newValue);
                    }
                } else {
                    $path = $mergeData;
                    data_set($this->data, $path, $value);
                    data_set($new, $path, $value);
                }
                continue;
            }
            if (is_string($value)) {
                $value = $this->variablesDetectString($value);
            }
            data_set($this->data, $path, $value);
            data_set($new, $path, $value);
        }

        $this->data = $new;

        chdir($oldCwd);
    }

    protected function variablesDetectArray(array $data): array
    {
        $newData = [];
        foreach ($data as $key => $value) {
            if ($key === '$schema') {
                continue;
            }
            if (is_string($key)) {
                $key = $this->variablesDetectString($key);
                if (is_array($key)) {
                    $newData = array_merge($newData, $this->variablesDetectArray($key));
                    continue;
                }
            }
            if (is_string($value)) {
                $newData[$key] = $this->variablesDetectString($value);
            } elseif (is_array($value)) {
                $newData[$key] = $this->variablesDetectArray($value);
            } else {
                $newData[$key] = $value;
            }
        }
        return $newData;
    }

    protected function variablesDetectString(string $value): string|int|float|null|bool|array
    {
        $variableRegexp = '\$([a-zA-Z\d_\-\.]+)';
        $fileRegexp = '\+(.+)';

        $variableReplace = function ($matches) {
            $name = $matches[1];

            if (Brain::hasEnv($name)) {
                return (string) Brain::getEnv($name);
            }

            return data_get($this->data, $name, $matches[0]);
        };
        $fileReplace = function ($matches, bool $full = false) {
            $file = trim($matches[1]);
            getcwd();
            if (is_file($file)) {
                $content = file_get_contents($file) ?: $matches[0];
                if ($full) {
                    $ext = pathinfo($file, PATHINFO_EXTENSION);
                    if (in_array($ext, ['json', 'jsonl'])) {
                        $decoded = json_decode($content, true);
                        if (is_array($decoded)) {
                            return $this->variablesDetectArray($decoded);
                        }
                    } elseif (in_array($ext, ['yaml', 'yml'])) {
                        $decoded = Yaml::parse($content);
                        if (is_array($decoded)) {
                            return $this->variablesDetectArray($decoded);
                        }
                    } elseif ($ext === 'env') {
                        $lines = explode("\n", $content);
                        $envData = [];
                        foreach ($lines as $line) {
                            if (str_contains($line, '=')) {
                                [$envKey, $envValue] = explode('=', $line, 2);
                                $envData[trim($envKey)] = trim($envValue);
                            }
                        }
                        return $this->variablesDetectArray($envData);
                    }
                }
                return $this->variablesDetectString($content);
            }
            throw new \InvalidArgumentException("File '$file' not found.");
        };

        if (preg_match("/^$variableRegexp$/", $value, $matches)) {
            return $variableReplace($matches);
        }
        if (preg_match("/^$fileRegexp$/", $value, $matches)) {
            return $fileReplace($matches, true);
        }

        $value = preg_replace_callback("/\{$variableRegexp}/", $variableReplace, $value) ?: $value;

        return preg_replace_callback("/\{$fileRegexp}/", $fileReplace, $value) ?: $value;
    }
}

