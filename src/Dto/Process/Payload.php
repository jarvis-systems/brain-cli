<?php

declare(strict_types=1);

namespace BrainCLI\Dto\Process;

use Bfg\Dto\Dto;
use BrainCLI\Attributes\CommandPayloadMap;
use Closure;

/**
 * @method Payload installBehavior(string|array|Closure $value)
 * @method Payload updateBehavior(string|array|Closure $value)
 * @method Payload programBehavior(string|array|Closure $value)
 * @method Payload continueBehavior(string|array|Closure $value)
 * @method Payload resumeBehavior(Closure $value)
 * @method Payload promptBehavior(Closure $value)
 * @method Payload askBehavior(Closure $value)
 * @method Payload jsonBehavior(string|array|Closure $value)
 * @method Payload schemaBehavior(Closure $value)
 * @method Payload yoloBehavior(string|array|Closure $value)
 * @method Payload allowToolsBehavior(Closure $value)
 * @method Payload noMcpBehavior(string|array|Closure $value)
 * @method Payload modelBehavior(Closure $value)
 * @method Payload systemBehavior(Closure $value)
 * @method Payload systemAppendBehavior(Closure $value)
 * @method Payload settingsBehavior(Closure $value)
 * @method Payload appendBehavior(string|array|Closure $value)
 */
class Payload extends Dto
{
    protected Closure|null $defaultOptionsCallback = null;

    public function __construct(
        #[CommandPayloadMap(['notUsed' => ['program', 'update'], 'required' => true])]
        protected string|array|Closure|null $install = null,
        #[CommandPayloadMap(['notUsed' => ['program', 'install'], 'required' => true])]
        protected string|array|Closure|null $update = null,
        #[CommandPayloadMap(['notUsed' => ['program', 'update', 'install'], 'required' => true])]
        protected string|array|Closure|null $program = null,

        #[CommandPayloadMap(['used' => ['program'], 'notUsed' => ['systemAppend']])]
        protected Closure|null $system = null,
        #[CommandPayloadMap(['used' => ['program'], 'notUsed' => ['system']])]
        protected Closure|null $systemAppend = null,

        #[CommandPayloadMap(['used' => ['program']])]
        protected Closure|null $resume = null,
        #[CommandPayloadMap(['used' => ['program']])]
        protected string|array|Closure|null $continue = null,

        #[CommandPayloadMap(['used' => ['program']])]
        protected Closure|null $prompt = null,

        #[CommandPayloadMap(['used' => ['program']])]
        protected Closure|null $ask = null,
        #[CommandPayloadMap(['used' => ['program', 'ask']])]
        protected string|array|Closure|null $json = null,
        #[CommandPayloadMap(['used' => ['program']])]
        protected string|array|Closure|null $yolo = null,
        #[CommandPayloadMap(['used' => ['program', 'ask']])]
        protected Closure|null $schema = null,

        #[CommandPayloadMap(['used' => ['program']])]
        protected Closure|null $allowTools = null,
        #[CommandPayloadMap(['used' => ['program'], 'notUsed' => ['allowTools']])]
        protected string|array|Closure|null $noMcp = null,
        #[CommandPayloadMap(['used' => ['program']])]
        protected Closure|null $model = null,
        #[CommandPayloadMap(['used' => ['program']])]
        protected Closure|null $settings = null,

        #[CommandPayloadMap(['used' => ['program']])]
        protected string|array|Closure|null $append = null,
    ) {
    }

    public function defaultOptionsBehavior(Closure $callback): static
    {
        $this->defaultOptionsCallback = $callback;

        return $this;
    }

    /**
     * @template TOptionsArray of array<string, mixed>
     *
     * @param  TOptionsArray  $options
     * @return TOptionsArray
     */
    public function defaultOptions(array $options): array
    {
        if ($this->defaultOptionsCallback instanceof Closure) {
            $result = call_user_func($this->defaultOptionsCallback, $options);
            if (is_array($result)) {
                $options = array_merge($options, $result);
            }
        }

        return $options;
    }

    /**
     * @return array{
     *     command: list<string>,
     *     env: array<string, string>,
     *     commands: array{
     *         before: list<string>,
     *         after: list<string>,
     *         exit: list<string>
     *     }
     * }
     */
    public function parameter(string $name, ...$arguments): array
    {
        $value = $this->{$name} ?? null;
        $command = [];
        $commands = [
            'before' => [],
            'after' => [],
            'exit' => [],
        ];
        $env = [];
        if ($value instanceof Closure) {
            $value = call_user_func($value, $this->processReflection()->factory(), ...$arguments);
        } elseif (is_null($value)) {
            throw new \RuntimeException(ucfirst($name) . " is not supported by this command");
        }

        if (is_array($value)) {
            if (
                isset($value['command'])
                || isset($value['env'])
                || isset($value['commands']['before'])
                || isset($value['commands']['after'])
            ) {
                if (isset($value['command']) && is_array($value['command'])) {
                    $command = array_merge($command, $value['command']);
                } elseif (isset($value['command']) && is_string($value['command'])) {
                    $command[] = $value['command'];
                }
                if (isset($value['env']) && is_array($value['env'])) {
                    $env = array_merge($env, $value['env']);
                }
                if (
                    isset($value['commands']['before'])
                    && (is_scalar($value['commands']['before']) || is_array($value['commands']['before']))
                ) {
                    $commands['before'] = [
                        ...$commands['before'],
                        ...((array) $value['commands']['before'])
                    ];
                    $commands['before'] = array_map(trim(...), $commands['before']);
                    $commands['before'] = array_values(array_filter(array_unique($commands['before'])));
                }
                if (
                    isset($value['commands']['after'])
                    && (is_scalar($value['commands']['after']) || is_array($value['commands']['after']))
                ) {
                    $commands['after'] = [
                        ...$commands['after'],
                        ...((array) $value['commands']['after'])
                    ];
                    $commands['after'] = array_map(trim(...), $commands['after']);
                    $commands['after'] = array_values(array_filter(array_unique($commands['after'])));
                }
                if (
                    isset($value['commands']['exit'])
                    && (is_scalar($value['commands']['exit']) || is_array($value['commands']['exit']))
                ) {
                    $commands['exit'] = [
                        ...$commands['exit'],
                        ...((array) $value['commands']['exit'])
                    ];
                    $commands['exit'] = array_map(trim(...), $commands['exit']);
                    $commands['exit'] = array_values(array_filter(array_unique($commands['exit'])));
                }
            } else {
                $command = array_merge($command, $value);
            }
        } elseif (is_string($value)) {
            $command[] = $value;
        }

        $command = array_filter($command, fn ($item) => ! is_null($item) && $item !== '');

        $this->processReflection()->use($name, $arguments);

        return compact('command', 'env', 'commands');
    }

    public function getMapData(string $name): array|null
    {
        $param = static::findConstructorParameter($name);

        if ($param) {
            $mapAttributes = $param->getAttributes(CommandPayloadMap::class);
            if (count($mapAttributes) > 0) {
                /** @var CommandPayloadMap $mapInstance */
                $mapInstance = $mapAttributes[0]->newInstance();
                return $mapInstance->data;
            }
            return [];
        }

        return null;
    }

    public function isValidated(): bool
    {
        foreach (static::getConstructorParameters() as $parameter) {
            $name = $parameter->getName();
            $mapData = $this->getMapData($name);
            if ($mapData === null) {
                continue;
            }

            if (array_key_exists('required', $mapData) && $mapData['required'] === true) {
                if ($this->{$name} === null) {
                    return false;
                }
            }
        }
        return true;
    }

    public function __call($method, $parameters)
    {
        if (str_ends_with($method, 'Behavior')) {
            $baseName = substr($method, 0, -8);
            $parameter = static::findConstructorParameter($baseName);

            if ($parameter) {

                if (! array_key_exists(0, $parameters)) {
                    throw new \RuntimeException(
                        sprintf('Parameter for %s is required', $baseName)
                    );
                }

                $this->{$baseName} = $parameters[0];

                return $this;
            }
        }

        return parent::__call($method, $parameters);
    }

    protected function processReflection(): Reflection
    {
        $reflection = $this->getMeta('reflection');
        if (! $reflection instanceof Reflection) {
            throw new \RuntimeException(
                'Process reflection is not set yet'
            );
        }
        return $reflection;
    }
}
