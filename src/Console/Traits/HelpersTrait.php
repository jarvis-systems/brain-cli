<?php

declare(strict_types=1);

namespace BrainCLI\Console\Traits;

use Illuminate\Support\Str;

trait HelpersTrait
{
    protected function extractInnerPathNameName(string $name): array
    {
        $path = str_replace('\\', DS, $name);
        $className = class_basename($name);
        $directory = str_replace($className, '', $path);
        $directory = array_map(function ($directory) {
            return Str::studly($directory);
        }, explode(DS, $directory));
        $nm = trim(implode('\\', $directory), '\\');
        return [
            implode(DS, $directory),
            $className,
            (! empty($nm) ? '\\' . $nm : '')
        ];
    }
}
