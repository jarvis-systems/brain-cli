<?php

declare(strict_types=1);

namespace BrainCLI\Dto\Process;

use Bfg\Dto\Dto;
use BrainCLI\Services\ProcessFactory;

class Reflection extends Dto
{
    /**
     * @param  array{command: list<string>, env: array<string, string>, commands: array{before: list<string>, after: list<string>, exit: list<string>}}  $body
     * @param  array<string, list<mixed>>  $usedState
     */
    public function __construct(
        public Payload $payload,
        public array $usedState = [],
        public array $body = [],
    ) {
        $this->validatePayload();
    }

    public function isUsed(string $name): bool
    {
        return array_key_exists($name, $this->usedState);
    }

    public function use(string $name, array $arguments = []): bool
    {
        if (! $this->isUsed($name)) {
            $this->usedState[$name] = $arguments;
            return true;
        }
        return false;
    }

    public function validatedUsed(string $name): void
    {
        if (! $this->isUsed($name)) {
            throw new \RuntimeException(
                sprintf('%s is not selected', ucfirst($name))
            );
        }
    }

    public function validatedNotUsed(string $name): void
    {
        if ($this->isUsed($name)) {
            throw new \RuntimeException(
                sprintf('%s is already selected', ucfirst($name))
            );
        }
    }

    /**
     * @param $data array{command: list<string>, env: array<string, string>, commands: array{before: list<string>, after: list<string>, exit: list<string>}}
     */
    public function fillBody(array $data): static
    {
        $this->addCommand($data['command']);
        $this->addCommands($data['commands']);
        $this->addEnv($data['env']);

        return $this;
    }

    public function addCommand(array|string $command): static
    {
        $command = is_array($command) ? $command : [$command];
        $this->body['command'] = [...($this->body['command'] ?? []), ...$command];

        return $this;
    }

    public function addCommands(array $commands): static
    {
        foreach ($commands as $when => $cmds) {
            if (! isset($this->body['commands'][$when])) {
                $this->body['commands'][$when] = [];
            }
            $this->body['commands'][$when] = [
                ...$this->body['commands'][$when],
                ...$cmds,
            ];
        }

        return $this;
    }

    public function addEnv(string|array $name, mixed $value = null): static
    {
        if (is_array($name)) {
            $this->body['env'] = array_merge($this->body['env'] ?? [], $name);
        } else {
            $this->body['env'][$name] = $value;
        }

        return $this;
    }

    public function mapCommand(callable $callback): static
    {
        return $this->mapFor('command', $callback);
    }

    public function mapEnv(callable $callback): static
    {
        return $this->mapFor('env', $callback);
    }

    public function hasCommand(string $part): bool
    {
        foreach ($this->body['command'] as $command) {
            if (str_starts_with($command, $part)) {
                return true;
            }
        }
        return false;
    }

    public function hasEnv(string $name): bool
    {
        return array_key_exists($name, $this->body['env']);
    }

    public function factory(): ProcessFactory
    {
        $factory = $this->getMeta('factory');
        if (! $factory instanceof ProcessFactory) {
            throw new \RuntimeException(
                'Command factory is not set yet'
            );
        }
        return $factory;
    }




    private function validatePayload(): void
    {
        if (! $this->payload->isValidated()) {
            throw new \RuntimeException(
                'Invalid command payload structure (Payload not validated)'
            );
        }
        $this->payload->setMeta('reflection', $this);
    }

    /**
     * @param  'command'|'env'  $name
     * @param  callable  $callback
     * @return static
     */
    private function mapFor(string $name, callable $callback): static
    {
        if (! isset($this->body[$name]) || ! is_array($this->body[$name])) {
            throw new \InvalidArgumentException("Cannot map over non-array body part: {$name}");
        }

        $parts = [];
        $previousKey = null;
        $previousValue = null;
        foreach ($this->body[$name] as $key => $value) {
            $result = call_user_func($callback, $value, $key, $previousValue, $previousKey);
            if (is_string($result)) {
                if (trim($result)) {
                    $parts[$key] = $result;
                }
            } elseif (is_array($result)) {
                foreach ($result as $key2 => $value2) {
                    if (trim($value2)) {
                        $parts[$key2] = $value2;
                    }
                }
            } elseif (is_bool($result)) {
                if ($result) {
                    $parts[$key] = $value;
                }
            } else {
                $parts[$key] = $value;
            }
            $previousKey = $key;
            $previousValue = $value;
        }

        $this->body[$name] = $parts;

        return $this;
    }
}
