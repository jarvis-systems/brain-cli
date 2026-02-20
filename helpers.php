<?php

declare(strict_types=1);

if (! function_exists('base_path')) {
    /**
     * Get the base path of the project.
     *
     * @param  string|array  $path
     * @return string
     */
    function base_path(string|array $path = ''): string
    {
        return \BrainCLI\Support\Brain::projectDirectory($path);
    }
}
