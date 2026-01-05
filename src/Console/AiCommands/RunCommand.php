<?php

declare(strict_types=1);

namespace BrainCLI\Console\AiCommands;

use BrainCLI\Abstracts\CommandBridgeAbstract;
use BrainCLI\Enums\Agent;
use BrainCLI\Enums\Process\Type;
use BrainCLI\Services\ProcessFactory;
use BrainCLI\Support\Brain;

class RunCommand extends CommandBridgeAbstract
{
    protected array $signatureParts = [
        '{searchModel? : The AI agent to run (overrides the command name)}',
        '{--i|install : Install required dependencies for the agent}',
        '{--u|update : Update the agent to the latest version}',

        '{--r|resume= : Resume a previous session by providing the session ID}',
        '{--c|continue : Continue the last session}',

        '{--a|ask= : Ask a specific question to the AI agent}',
        '{--j|json : Print all the output in JSON format (works only with --ask option)}',
        '{--serialize : Serialize the output DTOs (works only with --ask options)}',
        '{--J|schema= : Output the JSON schema of the response (works only with --ask options)}',
        '{--M|no-mcp : Disable the use of MCP files for schema validation}',

        '{--m|model= : Specify a custom AI model to use}',
        '{--s|system= : Specify a custom system prompt for the AI agent}',
        '{--S|system-append= : Append to the default system prompt}',

        '{--y|yolo : Allow all permissions for the AI agent (use with caution)}',
        '{--d|dump : Dump the process details (for debugging purposes)}',
    ];

    protected mixed $accumulateCallback = null;

    public function __construct(
        protected Agent $agent,
    ) {
        $this->signature = $this->agent->value;
        foreach ($this->signatureParts as $part) {
            $this->signature .= " " . $part;
        }
        $this->description = $this->agent->description();
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

        if ($sm = $this->argument('searchModel')) {
            $models = $this->agent->modelsEnum()::searchModel($sm === '-' || is_numeric($sm) ? '' : $sm);
            if (count($models) === 1) {
                $model = $models[0]->value;
            } elseif (count($models) > 1) {
                if (is_numeric($sm)) {
                    if (isset($models[(int) $sm])) {
                        $model = $models[(int) $sm]->value;
                    } else {
                        $this->components->error('The provided model index is out of range.');
                        exit(1);
                    }
                } else {
                    $model = $this->components->choice('Multiple models found. Please select one:', array_map(fn ($m) => $m->value, $models));
                }
            }
        }

        $type = Type::detect($this);
        $process = $this->client->process($type);
        $options = $process->payload->defaultOptions([
            'ask' => $this->option('ask'),
            'json' => $this->option('json') || $this->option('serialize'),
            'serialize' => $this->option('serialize'),
            'yolo' => $this->option('yolo'),
            'model' => $model ?? $this->option('model'),
            'system' => $this->option('system'),
            'systemAppend' => $this->option('system-append'),
            'schema' => $this->option('schema'),
            'dump' => $this->option('dump'),
            'resume' => $this->option('resume'),
            'continue' => $this->option('continue'),
            'install' => $this->option('install'),
            'update' => $this->option('update'),
            'no-mcp' => $this->option('no-mcp'),
        ]);

        if ($options['install']) {
            return $process->install()->open();
        } elseif ($options['update']) {
            return $process->update()->open();
        }

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

        return $process->open();
    }
}

