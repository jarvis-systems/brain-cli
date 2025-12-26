<?php

declare(strict_types=1);

namespace BrainCLI\Console\AiCommands\Lab\Screens;

use BrainCLI\Console\AiCommands\Lab\Abstracts\ScreenAbstract;
use BrainCLI\Console\AiCommands\Lab\Dto\Context;

class Evaluate extends ScreenAbstract
{
    public function __construct()
    {
        parent::__construct(
            'e',
            'Evaluate command snippets',
            '',
        );
    }

    public function main(Context $context, string|int|float $command, ...$parts): Context
    {
        $isolated = false;
        foreach ($parts as $key => $part) {
            if (! is_string($part) && ! is_int($part) && ! is_float($part) && ! is_bool($part)) {
                throw new \InvalidArgumentException('All parts of command must be string, int or float');
            }
            if (is_bool($part)) {
                if ($key === 'isolated' || $key === 'iso') {
                    $isolated = true;
                    continue;
                } else {
                    throw new \InvalidArgumentException('Boolean arguments are only allowed for "isolated" parameter');
                }
            }
            if (empty($part)) {
                continue;
            }
            $command .= " " . $part;
        }

        if ($isolated) {
            return $context->mergeGeneral(
                $this->screen()->submit(Context::fromEmpty()->merge($context), (string) $command),
                result: false,
            );
        } else {
            return $this->screen()->submit($context, (string) $command);
        }
    }
}
