<?php

namespace BrainCLI\Support;

use BrainCLI\Core;
use Illuminate\Support\Facades\Facade;

/**
 * @see Core
 */
class Brain extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Core::class;
    }
}
