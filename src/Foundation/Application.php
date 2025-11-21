<?php

declare(strict_types=1);

namespace BrainCLI\Foundation;

use Illuminate\Container\Container;

class Application extends Container
{
    public function runningUnitTests(): bool
    {
        return false;
    }

    public static function create(): static
    {
        return new static();
    }
}

