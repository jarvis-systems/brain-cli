<?php

namespace BrainCLI\Support;

use BrainCLI\Core;
use Illuminate\Support\Facades\Facade;

/**
 * @see Core
 *
 * @method static void debugException(\Throwable $e, string $prefix = 'brain-debug')
 */
class Brain extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Core::class;
    }
}
