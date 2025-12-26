<?php

declare(strict_types=1);

namespace BrainCLI\Console\AiCommands\Lab\Abstracts;

use Bfg\Dto\Dto;
use BrainCLI\Console\AiCommands\Lab\Dto\Context;
use BrainCLI\Console\AiCommands\Lab\Screen;
use BrainCLI\Console\AiCommands\Lab\WorkSpace;
use BrainCLI\Console\AiCommands\LabCommand;

/**
 * @method void alert(string $string, int $verbosity = \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL)
 * @method mixed ask(string $question, string $default = null, bool $multiline = false)
 * @method mixed askWithCompletion(string $question, array|callable $choices, string $default = null)
 * @method void bulletList(array $elements, int $verbosity = \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL)
 * @method mixed choice(string $question, array $choices, $default = null, int $attempts = null, bool $multiple = false)
 * @method bool confirm(string $question, bool $default = false)
 * @method void info(string $string, int $verbosity = \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL)
 * @method void success(string $string, int $verbosity = \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL)
 * @method void error(string $string, int $verbosity = \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL)
 * @method void secret(string $question, bool $fallback = true)
 * @method void task(string $description, ?callable $task = null, int $verbosity = \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL)
 * @method void twoColumnDetail(string $first, ?string $second = null, int $verbosity = \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL)
 * @method void warn(string $string, int $verbosity = \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL)
 */
abstract class ScreenAbstract extends Dto
{
    public function __construct(
        public string $name,
        public string $title,
        public string $description,
        public string|null $argumentDescription = null,
        public array $options = [],
        public string|null $detectRegexp = null,
    ) {
    }

    public function validateArguments(string|null $argument, Context $response): bool|string|array
    {
        //return is_null($argument) ? true : "The command /{$this->name} does not accept any arguments.";
        $collectedResult = [];
        if ($argument) {
            try {
                $submit = function (string $command, bool $onlySet = false) use (&$collectedResult, $response) {
                    $response2 = $this->screen()->submit(Context::fromEmpty()->merge($response), $command);
                    $response->mergeGeneral(
                        $response2,
                        result: false
                    );
                    if ($response2->isOk()) {
                        if ($response2->isNotEmpty('result')) {
                            $resValues = $response2->getAsArray('result');
                            $lastKey = array_key_last($resValues);
                            $val = count($resValues) === 1 ? $resValues[$lastKey] : $resValues;
                            if (is_string($lastKey) && !$onlySet) {
                                $collectedResult[$lastKey] = $val;
                            } else {
                                $collectedResult[] = $val;
                            }
                        }
                    } elseif ($error = $response2->getError()) {
                        throw new \Exception($error);
                    }
                };
                $parsed = str_getcsv($argument, '<<', '`');
                $parsed = array_values(array_filter(array_map(fn($item) => trim($item), $parsed)));
                if (preg_match($this->screen()->interfaceRegexp, $parsed[0], $matches)) {
                    $submit($parsed[0], true);
                    $result = [];
                } else {
                    $result = str_getcsv($parsed[0], ' ', '"', '\\');
                }
//                dd('>>>', $argument);
                foreach ($result as $key => $inp) {
                    $inp = trim($inp, ',;');
                    $inp = trim($inp);
                    if (preg_match('/^(?<key>[a-zA-Z0-9\-_]+)=(?<inp>.*)$/', $inp, $matches)) {
                        $inp = trim($matches['inp'], '"');
                        $key = $matches['key'];
                    }
                    try {
                        $inp = json_decode("\"$inp\"", true, 512, JSON_THROW_ON_ERROR);
                    } catch (\Exception $e) {
                        throw new \Exception("Invalid argument format for input: $inp");
                    }
                    if (is_numeric($inp)) {
                        if (str_contains($inp, '.')) {
                            $return = (float)$inp;
                        } else {
                            $return = (int)$inp;
                        }
                    } elseif (in_array(strtolower($inp), ['true', 'false'], true)) {
                        $return = strtolower($inp) === 'true';
                    } elseif ($inp === 'null') {
                        $return = null;
                    } elseif (Dto::isJson($inp)) {
                        $return = json_decode(json_decode("\"$inp\""), true, 512, JSON_THROW_ON_ERROR);
                    } else {
                        if (preg_match('/^--(?<name>[a-zA-Z0-9\-_]+)/', $inp, $matches)) {
                            $key = $matches['name'];
                            $return = true;
                        } elseif (preg_match('/^\$(?<name>[a-zA-Z\d\-_.]+)$/', $inp, $matches)) {
                            $varName = $matches['name'];
                            if (str_starts_with($varName, 'this')) {
                                $varName = trim(substr($varName, 4), '.');
                                $result = $response->getAsArray('result');
                                if ($varName !== '') {
                                    $return = data_get($result, $varName);
                                } else {
                                    $return = $result;
                                }
                            } else {
                                $return = $this->workspace()->getVariable($varName);
                            }
                        } else {
                            $return = $inp;
                        }
                    }
                    $collectedResult[$key] = $return;
                }

                foreach ($parsed as $key => $value) {
                    if ($key === 0) {
                        continue;
                    }
                    $submit($value);
                }

                return $collectedResult;
            } catch (\Throwable $e) {
                return $e->getMessage() ?: "An error occurred while parsing the arguments.";
            }
        }
        return true;
    }

    public function commandName(): string
    {
        return "/" . $this->name . ($this->argumentDescription ? " " . $this->argumentDescription : "");
    }

    public function label(): string
    {
        return $this->commandName() . ' - ' . $this->description;
    }

    public function line(string $line = ''): static
    {
        $this->command()->line($line);

        return $this;
    }

    public function __call($method, $parameters)
    {
        return $this->command()->outputComponents()->$method(...$parameters);
    }

    public function command(): LabCommand
    {
        return $this->getMeta('command');
    }

    public function screen(): Screen
    {
        return $this->getMeta('screen');
    }

    public function workspace(): WorkSpace
    {
        return $this->getMeta('workspace');
    }
}
