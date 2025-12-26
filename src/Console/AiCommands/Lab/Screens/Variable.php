<?php

declare(strict_types=1);

namespace BrainCLI\Console\AiCommands\Lab\Screens;

use BrainCLI\Console\AiCommands\Lab\Abstracts\ScreenAbstract;
use BrainCLI\Console\AiCommands\Lab\Dto\Context;

class Variable extends ScreenAbstract
{
    const NONE = '#$__NONE__$#';

    public function __construct()
    {
        parent::__construct(
            'var',
            'Variable Management',
            'Set or get variables within the AI Lab workspace environment.',
            //detectRegexp: '/^\$([a-zA-Z\d\-\_\.]+)$/'
        );
    }

    public function main(Context $response, string $name, mixed $value = self::NONE, bool $del = false, bool $delete = false): Context
    {
        $exists = $this->workspace()
                ->getVariable($name, '__NOT_EXISTS__') !== '__NOT_EXISTS__';
        $varName = $name;
        if ($thisStart = str_starts_with($name, 'this')) {
            $varName = trim(substr($name, 4), '.');
        }

        if ($del || $delete) {
            $result = $response->getAsArray('result');
            if ($thisStart) {
                data_forget($result, $varName);
            } else {
                data_forget($result, $name);
                $this->workspace()->forgetVariable($name);
            }
            $response->result($result);
            return $response;
        }

        if ($name && $value === self::NONE) {
            if ($thisStart) {
                $result = $response->getAsArray('result');
                if ($varName) {
                    $return = [data_get($result, $varName)];
                } else {
                    $return = $result;
                }
                return $response->result($return);
            } elseif ($exists) {
                return $response->result(
                    [$name => $this->workspace()->getVariable($name)], true
                );
            }
            return $response->error("Variable '{$name}' does not exist in the workspace.");
        } elseif ($name && $value !== self::NONE) {
            if ($thisStart) {
                return $response->result([
                    $varName => $value
                ], true);
            } else {
                $this->workspace()->setVariable($name, $value);
                return $response->result(
                    [$name => $this->workspace()->getVariable($name)], true
                );
            }
        }
        return $response->error("Invalid usage. Please provide a variable name and optionally a value to set.");
    }
}
