<?php

declare(strict_types=1);

namespace BrainCLI\Console\AiCommands;

use Bfg\Dto\Dto;
use BrainCLI\Abstracts\CommandBridgeAbstract;
use BrainCLI\Console\Commands\CompileCommand;
use BrainCLI\Enums\Agent;
use BrainCLI\Enums\Process\Type;
use BrainCLI\Support\Brain;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

class CustomRunCommand extends CommandBridgeAbstract
{
    protected array $signatureParts = [
        '{args?* : Arguments for the custom AI command}',
        '{--dump : Dump the processed data before execution}',
    ];

    protected mixed $accumulateCallback = null;

    public function __construct(
        protected string $callName,
        protected array $data,
    ) {
        $this->callName = $this->variablesDetectString($this->data['name'] ?? $this->callName);
        $this->data['_date'] = date('Y-m-d');
        $this->data['_datetime'] = date('Y-m-d H:i:s');
        $this->data['_time'] = date('H:i:s');
        $this->data['_timestamp'] = time();
        $this->data['_call_name'] = $this->callName;
        $this->data['_default_client'] = Agent::defaultAgent()->value;
        $this->data['_path.brain'] = Brain::workingDirectory();
        $this->data['_path.cli'] = Brain::localDirectory();
        $this->data['_path.home'] = getenv('HOME') ?: getenv('USERPROFILE');
        $this->data['_path.cwd'] = getcwd();
        $this->data['_system'] = [
            'uname' => php_uname(),
            'os' => PHP_OS_FAMILY,
            'architecture' => PHP_INT_SIZE * 8 . '-bit',
            'processor' => php_uname('m'),
            'hostname' => gethostname(),
            'name' => php_uname('n'),
            'release' => php_uname('r'),
            'version' => php_uname('v'),
        ];
        $this->data['_user_name'] = get_current_user();
        $this->data['_php_version'] = PHP_VERSION;

        $this->aliases = $this->variablesDetectArray($this->data['aliases'] ?? []);
        $this->signature = $this->callName;
        foreach ($this->signatureParts as $part) {
            $this->signature .= " " . $part;
        }
        $this->description = $this->variablesDetectString($this->data['description'] ?? 'Custom AI agent command');
        parent::__construct();
    }

    public function setAccumulateCallback(callable $accumulateCallback): static
    {
        $this->accumulateCallback = $accumulateCallback;

        return $this;
    }

    protected function handleBridge(): int|array
    {
        $this->data['client'] = $this->variablesDetectString($this->data['client']);

        $agent = Agent::tryFrom($this->data['client']);

        if (! $agent || ! $agent->isEnabled()) {
            $this->components->error("The specified client agent '{$this->data['client']}' is not supported or enabled.");
            return ERROR;
        }

        $this->initFor($agent);

        $this->data['_model'] = $this->agent->modelsAssoc();
        $this->data['_model.general'] = $this->agent->generalModel()->value;

        foreach (($this->argument('args') ?: []) as $index => $arg) {
            $decodable = is_string($arg) && (
                in_array($arg, ['false', 'true', 'null'])
                || is_numeric($arg)
                || Str::of($arg)->isJson()
                || str_starts_with($arg, '"')
            );
            $this->data['args'][$index] = $decodable ? json_decode($arg, true) : $arg;
        }
        $this->variablesDetectArrayData();

        if ($this->option('dump')) {
            dd($this->data);
        }

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
            'prompt' => $this->data['params']['prompt'] ?? null,
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
            ->promptWhen($options['prompt'], $options['prompt'])
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
        $new = [
            'id' => $this->data['id'] ?? md5(uniqid((string) time(), true)),
            'args' => $this->data['args'] ?? [],
        ];
        $oldCwd = getcwd();
        chdir(Brain::workingDirectory('agents'));

        foreach ($data as $path => $value) {
            if ($path === '$schema' || $path === 'id') {
                continue;
            }
            if (Str::startsWith($path, '_')) {
                $new[$path] = $value;
                continue;
            }
            if (
                preg_match('/^(.*)(\\\\$.*)$/', $path, $matches)
                || preg_match('/^(.*)(\\\\@.*)$/', $path, $matches)
                || preg_match('/^(.*)(\\\\!.*)$/', $path, $matches)
            ) {
                $firstPart = $this->variablesDetectString($matches[1]);
                if (! is_string($firstPart)) {
                    throw new \InvalidArgumentException("Invalid path part '{$matches[1]}' in '$path'.");
                }
                $mergeData = $this->variablesDetectString($matches[2]);

                if (is_array($mergeData)) {
                    $newData = Arr::dot($mergeData);
                    foreach ($newData as $newPath => $newValue) {
                        data_set($this->data, $firstPart . $newPath, $newValue);
                        data_set($new, $firstPart . $newPath, $newValue);
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
        $variableRegexp = '\$([a-zA-Z\d_\-\.\$\{\}]+)';
        $fileRegexp = '\@(.+)';
        $cmdRegexp = '\!(.+)';

        $variableReplace = function ($matches) use ($variableRegexp, $fileRegexp, $cmdRegexp) {
            $name = trim($matches[1]);
            $name = $this->variablesDetectString($name);

            if (Brain::hasEnv($name)) {
                $return = (string) Brain::getEnv($name);
            } elseif (array_key_exists($name, $this->data)) {
                $return = $this->data[$name];
            } else {
                $return = data_get($this->data, $name, $matches[0]);
            }
            while (
                is_string($return)
                && (
                    preg_match("/\\{$variableRegexp}/", $return)
                    || preg_match("/\\{$fileRegexp}/", $return)
                    || preg_match("/\\{$cmdRegexp}/", $return)
                    || preg_match("/^$variableRegexp$/", $return)
                    || preg_match("/^$fileRegexp$/", $return)
                    || preg_match("/^$cmdRegexp$/", $return)
                )
            ) {
                $return = $this->variablesDetectString($return);
            }

            return $return;
        };
        $fileReplace = function ($matches, bool $full = false) {
            $file = trim($matches[1]);
            $file = $this->variablesDetectString($file);
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
        $cmdReplace = function ($matches) use ($variableRegexp, $fileRegexp, $cmdRegexp) {
            $command = trim($matches[1]);
            $command = $this->variablesDetectString($command);
            $output = shell_exec($command);
            if ($output === null) {
                throw new \RuntimeException("Command '$command' execution failed.");
            }
            $value = $this->variablesDetectString(trim($output));
            while (
                is_string($value)
                && (
                    preg_match("/\\{$variableRegexp}/", $value)
                    || preg_match("/\\{$fileRegexp}/", $value)
                    || preg_match("/\\{$cmdRegexp}/", $value)
                    || preg_match("/^$variableRegexp$/", $value)
                    || preg_match("/^$fileRegexp$/", $value)
                    || preg_match("/^$cmdRegexp$/", $value)
                )
            ) {
                $value = $this->variablesDetectString($value);
            }
            return $value;
        };

        if (preg_match("/^\\\\".$cmdRegexp."$/", $value, $matches)) {
            $value = $cmdReplace($matches);
        }
        if (preg_match("/^\\\\".$variableRegexp."$/", $value, $matches)) {
            $value = $variableReplace($matches);
        }
        if (preg_match("/^\\\\".$fileRegexp."$/", $value, $matches)) {
            $value = $fileReplace($matches, true);
        }

        $value = preg_replace_callback("/\{$cmdRegexp}/", $cmdReplace, $value) ?: $value;

        $value = preg_replace_callback("/\{$variableRegexp}/", $variableReplace, $value) ?: $value;

        $return = preg_replace_callback("/\{$fileRegexp}/", $fileReplace, $value) ?: $value;

        $decodable = is_string($return) && (in_array($return, ['false', 'true', 'null'])
            || is_numeric($return)
            || Str::of($return)->isJson()
            || str_starts_with($return, '"'));

        return $decodable ? json_decode($return, true) : $return;
    }
}

