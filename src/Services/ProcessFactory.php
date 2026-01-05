<?php

declare(strict_types=1);

namespace BrainCLI\Services;

use BrainCLI\Abstracts\CommandBridgeAbstract;
use BrainCLI\Abstracts\ClientAbstract;
use BrainCLI\Dto\Process\Payload;
use BrainCLI\Dto\Process\Reflection;
use BrainCLI\Enums\Process\Type;
use BrainCLI\Support\Brain;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Traits\Conditionable;
use Stringable;
use Symfony\Component\Process\Process;

/**
 * @method ProcessFactory install()
 * @method ProcessFactory installWhen(mixed $condition)
 * @method ProcessFactory update()
 * @method ProcessFactory updateWhen(mixed $condition)
 * @method ProcessFactory program()
 * @method ProcessFactory programWhen(mixed $condition)
 * @method ProcessFactory resume(string|null $sessionId)
 * @method ProcessFactory resumeWhen(mixed $condition, string|callable $sessionId)
 * @method ProcessFactory prompt(string|null $prompt)
 * @method ProcessFactory promptWhen(mixed $condition, string|callable $prompt)
 * @method ProcessFactory continue()
 * @method ProcessFactory continueWhen(mixed $condition)
 * @method ProcessFactory ask(string $prompt)
 * @method ProcessFactory askWhen(mixed $condition, string|callable $prompt)
 * @method ProcessFactory yolo()
 * @method ProcessFactory yoloWhen(mixed $condition)
 * @method ProcessFactory allowTools(array $tools)
 * @method ProcessFactory allowToolsWhen(mixed $condition, array|callable $tools)
 * @method ProcessFactory noMcp()
 * @method ProcessFactory noMcpWhen(mixed $condition)
 * @method ProcessFactory model(string $model)
 * @method ProcessFactory modelWhen(mixed $condition, string|callable $model)
 * @method ProcessFactory system(string $systemPrompt)
 * @method ProcessFactory systemWhen(mixed $condition, string|callable $systemPrompt)
 * @method ProcessFactory systemAppend(string $systemPromptAppend)
 * @method ProcessFactory systemAppendWhen(mixed $condition, string|callable $systemPromptAppend)
 * @method ProcessFactory settings(array $settings)
 * @method ProcessFactory settingsWhen(mixed $condition, array|callable $settings)
 * @method ProcessFactory json()
 * @method ProcessFactory jsonWhen(mixed $condition)
 * @method ProcessFactory schema(array $schema)
 * @method ProcessFactory schemaWhen(mixed $condition, array|callable $schema)
 */
class ProcessFactory implements Arrayable, Stringable
{
    use Conditionable;

    public Reflection $reflection;

    public string $cwd;

    public array $output = [];

    protected bool $dump = false;

    public function __construct(
        public Type $type,
        public ClientAbstract $compiler,
        public Payload $payload,
        public CommandBridgeAbstract $command,
    ) {
        $this->cwd = Brain::projectDirectory();
        $this->reflection = Reflection::fromAssoc([
            'payload' => $payload,
        ])->setMeta('factory', $this);
        $this->payload->setMeta('reflection', $this->reflection);
    }

    public function dump(bool $dump = true): static
    {
        $this->dump = $dump;

        return $this;
    }

    /**
     * @return int Exit code
     */
    public function open(callable|null $openedCallback = null): int
    {
        $this->compiler->processRunCallback($this);
        $process = proc_open($this->toString(), [STDIN, STDOUT, STDERR], $pipes, $this->cwd);
        if (is_resource($process)) {
            $this->compiler->processHostedCallback($this);
            if ($openedCallback) {
                call_user_func($openedCallback, $this);
            }
            $exitCode = proc_close($process);
            $this->compiler->processExitCallback($this, $exitCode);
            return $exitCode;
        }

        return ERROR;
    }

    public function run(callable|null $callback = null): int
    {
        $hosted = false;
        $data = $this->toArray();
        $this->compiler->processRunCallback($this);
        $exitCode = (new Process($data['command'], $this->cwd, $data['env']))
            ->setTimeout(null)
            ->run(function ($type, $output) use ($callback, &$hosted) {
                $output = trim($output);
                if (! $hosted) {
                    $this->compiler->processHostedCallback($this);
                    $hosted = true;
                }
                if ($callback) {
                    call_user_func($callback, $output, $type);
                }
                $this->output[] = $output;
            });
        $this->compiler->processExitCallback($this, $exitCode);
        return $exitCode;
    }

    public function apply(callable $callback): static
    {
        call_user_func($callback, $this);

        return $this;
    }

    public function __call(string $name, array $arguments)
    {
        if (str_ends_with($name, 'When')) {
            $baseName = substr($name, 0, -4);
            $condition = array_shift($arguments);
            if ($condition) {
                if (isset($arguments[0]) && is_callable($arguments[0])) {
                    $arguments[0] = call_user_func($arguments[0], $this);
                }
                return $this->__call($baseName, $arguments);
            }
            return $this;
        }

        $mapData = $this->payload->getMapData($name);

        if ($mapData !== null) {
            if (isset($mapData['used'])) {
                foreach ($mapData['used'] as $item) {
                    $this->reflection->validatedUsed($item);
                }
            }
            if (isset($mapData['notUsed'])) {
                foreach ($mapData['notUsed'] as $item) {
                    $this->reflection->validatedNotUsed($item);
                }
            }
            $this->reflection->fillBody(
                $this->payload->parameter($name, ...$arguments)
            );
            return $this;
        }

        throw new \BadMethodCallException("Method $name does not exist.");
    }

    /**
     * @return array{command: list<string>, env: array<string, string>}
     */
    public function toArray(): array
    {
        if (
            $this->payload->isNotNull('append')
            && $this->reflection->isUsed('program')
        ) {
            $this->__call('append', []);
        }

        $body = $this->reflection->get('body');

        if ($this->dump) {
            dump($body);
        }

        if (empty($body['command'])) {
            throw new \RuntimeException('Incorrect later command build: command part is empty');
        }

        return $body;
    }

    public function toString(): string
    {
        $body = $this->toArray();
        $command = $body['command'];
        foreach ($command as $key => $item) {
            if (str_contains($item, ' ')) {
                $command[$key] = "'" . $item . "'";
            } elseif ($item === '') {
                $command[$key] = "''";
            }
        }
        $command = implode(' ', $command);
        foreach ($body['env'] as $key => $value) {
            $command = "{$key}={$value} {$command}";
        }
        return $command;
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
