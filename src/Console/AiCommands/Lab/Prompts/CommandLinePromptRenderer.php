<?php

declare(strict_types=1);

namespace BrainCLI\Console\AiCommands\Lab\Prompts;

use Laravel\Prompts\Themes\Contracts\Scrolling;
use Laravel\Prompts\Themes\Default\Concerns\DrawsBoxes;
use Laravel\Prompts\Themes\Default\Concerns\DrawsScrollbars;
use Laravel\Prompts\Themes\Default\Renderer;

/**
 * Renderer for CommandLinePrompt with dual menus.
 *
 * Features:
 * - History menu above input (↑ to open)
 * - Autocomplete menu below input (↓ to open)
 * - Inline ghost suggestion (dimmed)
 * - Syntax highlighting for command prefixes
 */
class CommandLinePromptRenderer extends Renderer implements Scrolling
{
    use DrawsBoxes;
    use DrawsScrollbars;

    /**
     * Render the command line prompt.
     */
    public function __invoke(CommandLinePrompt $prompt): string
    {
        $maxWidth = $prompt->terminal()->cols() - 6;

        match ($prompt->state) {
            'submit' => $this->renderSubmit($prompt, $maxWidth),
            'cancel' => $this->renderCancel($prompt, $maxWidth),
            'error' => $this->renderError($prompt, $maxWidth),
            default => $this->renderActive($prompt, $maxWidth),
        };

        return (string) $this;
    }

    /**
     * Render submit state.
     */
    protected function renderSubmit(CommandLinePrompt $prompt, int $maxWidth): self
    {
        return $this->box(
            $this->dim($this->truncate($prompt->label, $maxWidth)),
            $this->formatCommandValue($prompt, $this->truncate($prompt->value(), $maxWidth)),
        );
    }

    /**
     * Render cancel state.
     */
    protected function renderCancel(CommandLinePrompt $prompt, int $maxWidth): self
    {
        return $this
            ->box(
                $this->dim($this->truncate($prompt->label, $maxWidth)),
                $this->strikethrough($this->dim($this->truncate($prompt->value() ?: $prompt->placeholder, $maxWidth))),
                color: 'red',
            )
            ->error($prompt->cancelMessage);
    }

    /**
     * Render error state.
     */
    protected function renderError(CommandLinePrompt $prompt, int $maxWidth): self
    {
        return $this
            ->box(
                $this->truncate($prompt->label, $maxWidth),
                $this->renderInputWithGhost($prompt, $maxWidth),
                $prompt->showAutocomplete ? $this->renderAutocomplete($prompt) : '',
                color: 'yellow',
            )
            ->warning($this->truncate($prompt->error, $prompt->terminal()->cols() - 5));
    }

    /**
     * Render active state with dual menus.
     */
    protected function renderActive(CommandLinePrompt $prompt, int $maxWidth): self
    {
        // Render status line if present (real-time updates)
        if ($prompt->hasStatusLine()) {
            $this->renderStatusLine($prompt, $maxWidth);
        }

        // Render history menu above input (if visible)
        if ($prompt->showHistoryMenu) {
            $this->renderHistoryMenu($prompt);
        }

        // Render input box
        $this->box(
            $this->cyan($this->truncate($prompt->label, $maxWidth)),
            $this->renderInputWithGhost($prompt, $maxWidth),
            $prompt->showAutocomplete ? $this->renderAutocomplete($prompt) : '',
        );

        // Hint
        $this->when(
            $prompt->hint || ! $prompt->showAutocomplete,
            fn () => $this->hint($this->buildHint($prompt)),
            fn () => $this->newLine()
        );

        // Space for dropdown to prevent jumping
        $this->spaceForDropdown($prompt);

        return $this;
    }

    /**
     * Render the status line above the prompt.
     */
    protected function renderStatusLine(CommandLinePrompt $prompt, int $maxWidth): self
    {
        $status = $prompt->getStatusLine();

        if ($status === null || $status === '') {
            return $this;
        }

        // Output status line with dim styling
        $this->line($this->dim($this->truncate($status, $maxWidth)));

        return $this;
    }

    /**
     * Render history menu above the input.
     * Oldest commands at top, newest at bottom (closest to input).
     */
    protected function renderHistoryMenu(CommandLinePrompt $prompt): self
    {
        $entries = $prompt->getHistoryEntries();

        if (empty($entries)) {
            return $this;
        }

        $visible = $prompt->visibleHistory();
        $terminalWidth = $prompt->terminal()->cols() - 12;
        $historyCount = count($entries);

        $lines = [];

        // Build lines in normal order first
        foreach ($visible as $index => $entry) {
            $label = $this->truncate($entry, $terminalWidth);

            if ($prompt->historyHighlighted === $index) {
                $lines[$index] = "  {$this->cyan('>')} {$this->bold($label)}  ";
            } else {
                $lines[$index] = "    {$this->dim($label)}  ";
            }
        }

        // Reverse the lines so oldest is at top, newest at bottom
        $lines = array_reverse($lines, true);

        // Convert to indexed array for scrollbar
        $lines = array_values($lines);

        // Add scrollbar if needed
        if ($historyCount > $prompt->scroll) {
            $lines = $this->scrollbar(
                $lines,
                $prompt->historyFirstVisible,
                $prompt->scroll,
                $historyCount,
                min($this->longest($lines, padding: 4), $terminalWidth + 6),
                'cyan'
            );
        }

        // Output history lines
        foreach ($lines as $line) {
            $this->line($line);
        }

        return $this;
    }

    /**
     * Build the hint text with keyboard shortcuts.
     */
    protected function buildHint(CommandLinePrompt $prompt): string
    {
        $hints = [];

        if ($prompt->getGhostText() !== '') {
            $hints[] = 'Tab accept';
        }

        // Show navigation hints based on current state
        if ($prompt->showHistoryMenu) {
            $hints[] = "\xe2\x86\x91 older | \xe2\x86\x93 newer/close";
        } elseif ($prompt->showAutocomplete) {
            $hints[] = "\xe2\x86\x91 up/close | \xe2\x86\x93 down";
        } else {
            $hasHistory = $prompt->history !== null && $prompt->history->count() > 0;
            $hasMatches = count($prompt->matches()) > 0;

            if ($hasHistory && $hasMatches) {
                $hints[] = "\xe2\x86\x91 history | \xe2\x86\x93 suggestions";
            } elseif ($hasHistory) {
                $hints[] = "\xe2\x86\x91 history";
            } elseif ($hasMatches) {
                $hints[] = "\xe2\x86\x93 suggestions";
            }
        }

        $hints[] = 'Enter submit';

        $customHint = $prompt->hint ? $prompt->hint.' | ' : '';

        return $customHint.implode(' | ', $hints);
    }

    /**
     * Render input with inline ghost suggestion.
     */
    protected function renderInputWithGhost(CommandLinePrompt $prompt, int $maxWidth): string
    {
        $value = $prompt->valueWithCursor($maxWidth);

        // If in any menu with highlighted item, show that
        if (($prompt->showAutocomplete && $prompt->highlighted !== null)
            || ($prompt->showHistoryMenu && $prompt->historyHighlighted !== null)) {
            return $value;
        }

        // Get ghost text
        $ghostText = $prompt->getGhostText();

        if ($ghostText === '') {
            return $value;
        }

        // Calculate available space for ghost
        $valueLength = mb_strwidth($this->stripEscapeSequences($value));
        $availableForGhost = $maxWidth - $valueLength;

        if ($availableForGhost <= 0) {
            return $value;
        }

        $ghostTruncated = $this->truncate($ghostText, $availableForGhost);

        return $value.$this->dim($ghostTruncated);
    }

    /**
     * Format command value with syntax highlighting.
     */
    protected function formatCommandValue(CommandLinePrompt $prompt, string $value): string
    {
        $prefix = $prompt->getCommandPrefix();

        if ($prefix === null) {
            return $value;
        }

        $color = $prompt->getPrefixColor();
        $prefixLength = mb_strlen($prefix);
        $coloredPrefix = $this->{$color}(mb_substr($value, 0, $prefixLength));
        $rest = mb_substr($value, $prefixLength);

        return $coloredPrefix.$rest;
    }

    /**
     * Render the autocomplete dropdown (below input).
     */
    protected function renderAutocomplete(CommandLinePrompt $prompt): string
    {
        $matches = $prompt->matches();

        if (empty($matches)) {
            if ($prompt->searchValue() !== '') {
                return $this->gray('  No matches found');
            }

            return '';
        }

        $visible = $prompt->visible();
        $terminalWidth = $prompt->terminal()->cols() - 12;

        $lines = [];

        foreach ($visible as $key => $option) {
            $label = is_array($option) ? ($option['label'] ?? $option[1] ?? $key) : $option;
            $value = is_array($option) ? ($option['value'] ?? $option[0] ?? $key) : $key;

            $label = $this->truncate((string) $label, $terminalWidth);

            // Find index in matches
            $index = array_search($key, array_keys($matches));

            if ($prompt->highlighted === $index) {
                $lines[] = "  {$this->cyan('>')} {$this->bold($label)}  ";
            } else {
                $lines[] = "    {$this->dim($label)}  ";
            }
        }

        if (count($matches) <= $prompt->scroll) {
            return implode(PHP_EOL, $lines);
        }

        return implode(PHP_EOL, $this->scrollbar(
            $lines,
            $prompt->firstVisible,
            $prompt->scroll,
            count($matches),
            min($this->longest($lines, padding: 4), $terminalWidth + 6),
            $prompt->state === 'cancel' ? 'dim' : 'cyan'
        ));
    }

    /**
     * Render a spacer to prevent jumping.
     */
    protected function spaceForDropdown(CommandLinePrompt $prompt): self
    {
        // Only add space if no menu is open but matches exist
        if (! $prompt->showAutocomplete && ! $prompt->showHistoryMenu) {
            $matchCount = count($prompt->matches());

            if ($prompt->searchValue() === '' && $matchCount > 0) {
                $this->newLine(min(
                    $matchCount,
                    $prompt->scroll,
                    $prompt->terminal()->lines() - 7
                ));
            }
        }

        return $this;
    }

    /**
     * The number of lines to reserve outside of the scrollable area.
     */
    public function reservedLines(): int
    {
        return 7;
    }

    /**
     * Get the longest line length from an array of strings.
     *
     * @param array<string|array> $lines
     */
    protected function longest(array $lines, int $padding = 0): int
    {
        $max = $this->minWidth ?? 60;

        foreach ($lines as $line) {
            $text = is_array($line) ? ($line['label'] ?? $line[1] ?? $line[0] ?? '') : $line;
            $length = mb_strwidth($this->stripEscapeSequences((string) $text)) + $padding;

            if ($length > $max) {
                $max = $length;
            }
        }

        return $max;
    }
}