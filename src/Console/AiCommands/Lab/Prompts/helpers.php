<?php

declare(strict_types=1);

namespace BrainCLI\Console\AiCommands\Lab\Prompts;

use Closure;
use Illuminate\Support\Collection;
use Laravel\Prompts\Prompt;

if (! function_exists(__NAMESPACE__.'\\commandline')) {
    /**
     * Register the CommandLinePrompt renderer.
     */
    function registerCommandLineRenderer(): void
    {
        static $registered = false;

        if ($registered) {
            return;
        }

        // Add renderer to default theme via reflection (since addTheme can't modify default)
        $reflection = new \ReflectionClass(Prompt::class);
        $themesProperty = $reflection->getProperty('themes');
        $themesProperty->setAccessible(true);

        $themes = $themesProperty->getValue();
        $themes['default'][CommandLinePrompt::class] = CommandLinePromptRenderer::class;
        $themesProperty->setValue(null, $themes);

        $registered = true;
    }

    /**
     * Prompt the user with command line input with inline autocomplete.
     *
     * @param  array<int|string, string|array>|Collection<int|string, string|array>|Closure(string): (array<int|string, string|array>|Collection<int|string, string|array>)  $options
     * @param  int|null  $autoRefreshMs  Enable auto-refresh with interval in milliseconds (null = disabled)
     * @param  Closure|null  $onTick  Callback to run on each refresh tick (receives prompt instance)
     * @param  Closure|null  $statusLine  Callback that returns dynamic status text (updated on each render)
     */
    function commandline(
        string $label,
        array|Collection|Closure $options,
        string $placeholder = '',
        string $default = '',
        int $scroll = 5,
        bool|string $required = false,
        mixed $validate = null,
        string $hint = '',
        ?Closure $transform = null,
        ?CommandHistory $history = null,
        ?int $autoRefreshMs = null,
        ?Closure $onTick = null,
        ?Closure $statusLine = null,
    ): string {
        registerCommandLineRenderer();

        $prompt = new CommandLinePrompt(
            label: $label,
            options: $options,
            placeholder: $placeholder,
            default: $default,
            scroll: $scroll,
            required: $required,
            validate: $validate,
            hint: $hint,
            transform: $transform,
            history: $history,
        );

        // Set status line if specified (updates on each render/keypress)
        if ($statusLine !== null) {
            $prompt->withStatusLine($statusLine);
        }

        // Enable auto-refresh ONLY if explicitly requested
        // Note: statusLine works without auto-refresh (updates on keypress)
        if ($autoRefreshMs !== null) {
            $prompt->withAutoRefresh($autoRefreshMs, $onTick);
        }

        $result = $prompt->prompt();

        // Add to history after successful submit
        if ($history !== null && $result !== '') {
            $history->add($result);
        }

        return $result;
    }
}