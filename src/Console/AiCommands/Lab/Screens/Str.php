<?php

declare(strict_types=1);

namespace BrainCLI\Console\AiCommands\Lab\Screens;

use BrainCLI\Console\AiCommands\Lab\Abstracts\ScreenAbstract;
use BrainCLI\Console\AiCommands\Lab\Dto\Context;
use Illuminate\Support\Collection;

class Str extends ScreenAbstract
{
    public function __construct()
    {
        parent::__construct(
            'str',
            'Str function',
            '',
            detectRegexp: '/^str\-([a-zA-Z\d\-\_\.]+)$/'
        );
    }

    public function main(Context $context, mixed $parameterName, mixed $val, ...$args): Context
    {
        if (method_exists(\Illuminate\Support\Str::class, $parameterName)) {
            $result = \Illuminate\Support\Str::{$parameterName}($val, ...$args);
        } elseif (
            $parameterName === 'explode'
        ) {
            $result = call_user_func($parameterName, $val, ...$args);
        } else {
            return $context->error("Method '{$parameterName}' does not exist in Str class.");
        }
        if ($result instanceof Collection) {
            $result = $result->toArray();
        }
        return $context->result(
            $result, true
        );
    }
}
