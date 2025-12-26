<?php

declare(strict_types=1);

namespace BrainCLI\Console\AiCommands\Lab\Prompts;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Prompts\Concerns\Scrolling;
use Laravel\Prompts\Concerns\Truncation;
use Laravel\Prompts\Concerns\TypedValue;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Stream\ReadableResourceStream;

/**
 * Command line prompt with dual menu autocomplete.
 *
 * Features:
 * - Two separate menus: history (above) and autocomplete (below)
 * - ↑ from input opens history menu, ↓ from input opens autocomplete menu
 * - Tab inserts selected item and continues editing
 * - Enter submits selected item immediately
 * - Inline ghost suggestion (dimmed, appears after cursor)
 * - Syntax highlighting for command prefixes (/, !, @, ?)
 * - Persistent command history
 * - Real-time auto-refresh (optional)
 */
class CommandLinePrompt extends Prompt
{
    use Scrolling;
    use Truncation;
    use TypedValue;

    /**
     * Command prefix characters for syntax highlighting.
     */
    public const PREFIXES = ['/', '!', '@', '?'];

    /**
     * Modifier characters.
     */
    public const MODIFIERS = ['+', '&'];

    /**
     * Event name for tab-next navigation.
     */
    public const EVENT_TAB_NEXT = 'tab-next';

    /**
     * Event name for tab-previous navigation.
     */
    public const EVENT_TAB_PREV = 'tab-previous';

    /**
     * Current active prompt instance (for external re-render triggers).
     */
    protected static ?self $activeInstance = null;

    /**
     * Whether auto-refresh is enabled.
     */
    protected bool $autoRefresh = false;

    /**
     * Flag to request re-render on next loop iteration.
     */
    protected bool $renderRequested = false;

    /**
     * Auto-refresh interval in milliseconds.
     */
    protected int $refreshIntervalMs = 1000;

    /**
     * Callback to run on each refresh tick (receives $this, should re-render external content).
     *
     * @var Closure|null
     */
    protected ?Closure $onTick = null;

    /**
     * Lines to move cursor up for external content refresh.
     */
    protected int $externalContentLines = 0;

    /**
     * Dynamic status line callback (called on each render).
     *
     * @var Closure|null
     */
    protected ?Closure $statusLineCallback = null;

    /**
     * The options for autocomplete.
     *
     * @var array<string>|Closure(string): (array<string>|Collection<int, string>)
     */
    public array|Closure $options;

    /**
     * The cached matches.
     *
     * @var array<int|string, string>|null
     */
    protected ?array $matches = null;

    /**
     * The current inline suggestion (ghost text).
     */
    protected ?string $inlineSuggestion = null;

    /**
     * Whether autocomplete dropdown (below input) is visible.
     */
    public bool $showAutocomplete = false;

    /**
     * Whether history menu (above input) is visible.
     */
    public bool $showHistoryMenu = false;

    /**
     * Highlighted item in history menu.
     */
    public ?int $historyHighlighted = null;

    /**
     * First visible item in history menu (for scrolling).
     */
    public int $historyFirstVisible = 0;

    /**
     * Create a new CommandLinePrompt instance.
     *
     * @param array<string>|Collection<int, string>|Closure(string): (array<string>|Collection<int, string>) $options
     */
    public function __construct(
        public string $label,
        array|Collection|Closure $options,
        public string $placeholder = '',
        public string $default = '',
        public int $scroll = 5,
        public bool|string $required = false,
        public mixed $validate = null,
        public string $hint = '',
        public ?Closure $transform = null,
        public ?CommandHistory $history = null,
    ) {
        $this->options = $options instanceof Collection ? $options->all() : $options;

        $this->initializeScrolling(null);

        $this->on('key', fn ($key) => match ($key) {
            Key::UP, Key::UP_ARROW, Key::CTRL_P => $this->handleUp(),
            Key::DOWN, Key::DOWN_ARROW, Key::CTRL_N => $this->handleDown(),
            Key::TAB => $this->handleTabSwitch(),
            Key::SHIFT_TAB => $this->handleTabSwitchPrevious(),
            Key::oneOf([Key::HOME, Key::CTRL_A], $key) => $this->highlighted !== null ? $this->highlight(0) : null,
            Key::oneOf([Key::END, Key::CTRL_E], $key) => $this->highlighted !== null ? $this->highlight(count($this->matches()) - 1) : null,
            Key::ENTER => $this->handleEnter(),
            Key::ESCAPE => $this->cancelDropdown(),
            Key::CTRL_C => $this->state = 'cancel',
            Key::oneOf([Key::LEFT, Key::LEFT_ARROW, Key::RIGHT, Key::RIGHT_ARROW, Key::CTRL_B, Key::CTRL_F], $key) => $this->onCursorMove(),
            default => $this->onType(),
        });

        $this->trackTypedValue($default, submit: false, ignore: fn ($key) => Key::oneOf([Key::HOME, Key::END, Key::CTRL_A, Key::CTRL_E], $key) && $this->highlighted !== null);
    }

    /**
     * Handle up arrow key.
     *
     * - If in autocomplete menu: go up, if at top -> close and return to input
     * - If in history menu: go to older (higher index)
     * - If nothing open: open history menu (if available)
     */
    protected function handleUp(): void
    {
        // If in autocomplete menu - navigate up or close
        if ($this->showAutocomplete) {
            if ($this->highlighted === 0) {
                // At top - close menu and return to input
                $this->closeAutocomplete();
            } else {
                $this->highlightPrevious(count($this->matches()), false);
            }

            return;
        }

        // If in history menu - go to older commands (higher index)
        if ($this->showHistoryMenu) {
            $historyCount = count($this->getHistoryEntries());

            if ($this->historyHighlighted < $historyCount - 1) {
                $this->historyHighlighted++;
                $this->scrollHistoryIntoView();
            }

            return;
        }

        // Nothing open - open history menu (if history available)
        if ($this->history !== null && $this->history->count() > 0) {
            $this->openHistoryMenu();
        }
    }

    /**
     * Handle down arrow key.
     *
     * - If in history menu: go to newer (lower index), if at bottom -> close and return to input
     * - If in autocomplete menu: go down
     * - If nothing open: open autocomplete menu (if matches available)
     */
    protected function handleDown(): void
    {
        // If in history menu - navigate down (newer) or close
        if ($this->showHistoryMenu) {
            if ($this->historyHighlighted === 0) {
                // At bottom (newest) - close menu and return to input
                $this->closeHistoryMenu();
            } else {
                $this->historyHighlighted--;
                $this->scrollHistoryIntoView();
            }

            return;
        }

        // If in autocomplete menu - navigate down
        if ($this->showAutocomplete) {
            $this->highlightNext(count($this->matches()), false);

            return;
        }

        // Nothing open - open autocomplete menu (if matches available)
        $this->matches = null; // Force recalculate
        if (count($this->matches()) > 0) {
            $this->openAutocomplete();
        }
    }

    /**
     * Handle Tab key with context-aware behavior.
     * - In history menu: insert selected and close menu
     * - In autocomplete menu: insert selected and close menu
     * - With inline suggestion: accept suggestion
     * - Otherwise: emit tab-next event for Screen to handle
     */
    protected function handleTabSwitch(): void
    {
        if ($this->showHistoryMenu && $this->historyHighlighted !== null) {
            $this->selectHistoryHighlighted();
            $this->closeHistoryMenu();
            $this->updateInlineSuggestion();
            return;
        }
        if ($this->showAutocomplete && $this->highlighted !== null) {
            $this->selectHighlighted();
            $this->closeAutocomplete();
            $this->matches = null;
            $this->updateInlineSuggestion();
            return;
        }
        if ($this->inlineSuggestion !== null) {
            $this->typedValue = $this->inlineSuggestion;
            $this->cursorPosition = mb_strlen($this->typedValue);
            $this->inlineSuggestion = null;
            $this->matches = null;
            return;
        }
        $this->emit(self::EVENT_TAB_NEXT);
    }

    /**
     * Handle Shift+Tab key with context-aware behavior.
     * - In autocomplete menu: go up one item
     * - In history menu: go down one item (older)
     * - Otherwise: emit tab-previous event for Screen to handle
     */
    protected function handleTabSwitchPrevious(): void
    {
        if ($this->showAutocomplete && $this->highlighted !== null) {
            $this->highlightPrevious(count($this->matches()), false);
            return;
        }
        if ($this->showHistoryMenu) {
            $historyCount = count($this->getHistoryEntries());
            if ($this->historyHighlighted < $historyCount - 1) {
                $this->historyHighlighted++;
                $this->scrollHistoryIntoView();
            }
            return;
        }
        $this->emit(self::EVENT_TAB_PREV);
    }

    /**
     * Open the history menu.
     */
    protected function openHistoryMenu(): void
    {
        $this->showHistoryMenu = true;
        $this->historyHighlighted = 0; // Start at newest
        $this->historyFirstVisible = 0;
        $this->showAutocomplete = false;
        $this->highlighted = null;
    }

    /**
     * Close the history menu.
     */
    protected function closeHistoryMenu(): void
    {
        $this->showHistoryMenu = false;
        $this->historyHighlighted = null;
        $this->historyFirstVisible = 0;
        $this->matches = null; // Invalidate cache
    }

    /**
     * Open the autocomplete menu.
     */
    protected function openAutocomplete(): void
    {
        $this->showAutocomplete = true;
        $this->highlight(0);
        $this->showHistoryMenu = false;
        $this->historyHighlighted = null;
    }

    /**
     * Close the autocomplete menu.
     */
    protected function closeAutocomplete(): void
    {
        $this->showAutocomplete = false;
        $this->highlighted = null;
        $this->firstVisible = 0;
    }

    /**
     * Scroll history into view.
     */
    protected function scrollHistoryIntoView(): void
    {
        // Scroll up if highlighted is above visible area
        if ($this->historyHighlighted < $this->historyFirstVisible) {
            $this->historyFirstVisible = $this->historyHighlighted;
        }

        // Scroll down if highlighted is below visible area
        if ($this->historyHighlighted >= $this->historyFirstVisible + $this->scroll) {
            $this->historyFirstVisible = $this->historyHighlighted - $this->scroll + 1;
        }
    }

    /**
     * Get history entries for display.
     *
     * @return array<int, string>
     */
    public function getHistoryEntries(): array
    {
        if ($this->history === null) {
            return [];
        }

        return $this->history->all();
    }

    /**
     * Get visible history entries (for scrolling).
     *
     * @return array<int, string>
     */
    public function visibleHistory(): array
    {
        $entries = $this->getHistoryEntries();

        return array_slice($entries, $this->historyFirstVisible, $this->scroll, preserve_keys: true);
    }

    /**
     * Select the highlighted history entry.
     */
    protected function selectHistoryHighlighted(): void
    {
        if ($this->historyHighlighted === null) {
            return;
        }

        $entries = $this->getHistoryEntries();

        if (! isset($entries[$this->historyHighlighted])) {
            return;
        }

        $this->typedValue = $entries[$this->historyHighlighted];
        $this->cursorPosition = mb_strlen($this->typedValue);
        $this->matches = null;
        $this->inlineSuggestion = null;
    }

    /**
     * Handle enter key - select and submit immediately.
     */
    protected function handleEnter(): void
    {
        // If in history menu - select and submit
        if ($this->showHistoryMenu && $this->historyHighlighted !== null) {
            $this->selectHistoryHighlighted();
            $this->closeHistoryMenu();
        }

        // If in autocomplete menu - select and submit
        if ($this->showAutocomplete && $this->highlighted !== null) {
            $this->selectHighlighted();
            $this->closeAutocomplete();
        }

        $this->submit();
    }

    /**
     * Accept the suggestion (Tab) - insert into input and continue editing.
     */
    protected function acceptSuggestion(): void
    {
        // If in history menu - insert and close menu
        if ($this->showHistoryMenu && $this->historyHighlighted !== null) {
            $this->selectHistoryHighlighted();
            $this->closeHistoryMenu();
            $this->updateInlineSuggestion();

            return;
        }

        // If in autocomplete menu - insert and close menu
        if ($this->showAutocomplete && $this->highlighted !== null) {
            $this->selectHighlighted();
            $this->closeAutocomplete();
            $this->matches = null;
            $this->updateInlineSuggestion();

            return;
        }

        // If has inline ghost suggestion - accept it
        if ($this->inlineSuggestion !== null) {
            $this->typedValue = $this->inlineSuggestion;
            $this->cursorPosition = mb_strlen($this->typedValue);
            $this->inlineSuggestion = null;
            $this->matches = null;
        }
    }

    /**
     * Cancel all menus (Escape).
     */
    protected function cancelDropdown(): void
    {
        $this->closeAutocomplete();
        $this->closeHistoryMenu();
    }

    /**
     * Handle cursor movement.
     */
    protected function onCursorMove(): void
    {
        $this->closeAutocomplete();
        $this->closeHistoryMenu();
        $this->updateInlineSuggestion();
    }

    /**
     * Handle typing.
     */
    protected function onType(): void
    {
        $this->closeAutocomplete();
        $this->closeHistoryMenu();
        $this->matches = null;
        $this->firstVisible = 0;
        $this->updateInlineSuggestion();
    }

    /**
     * Update the inline suggestion based on current input.
     */
    protected function updateInlineSuggestion(): void
    {
        $value = $this->typedValue;

        if ($value === '') {
            $this->inlineSuggestion = null;

            return;
        }

        $matches = $this->matches();

        if (empty($matches)) {
            $this->inlineSuggestion = null;

            return;
        }

        // Find first match that starts with current value
        foreach ($matches as $match) {
            $matchValue = is_array($match) ? ($match['value'] ?? $match[0] ?? '') : $match;
            if (Str::startsWith(mb_strtolower($matchValue), mb_strtolower($value)) && $matchValue !== $value) {
                $this->inlineSuggestion = $matchValue;

                return;
            }
        }

        $this->inlineSuggestion = null;
    }

    /**
     * Select the highlighted entry.
     */
    protected function selectHighlighted(): void
    {
        if ($this->highlighted === null) {
            return;
        }

        $matches = $this->matches();
        $keys = array_keys($matches);

        if (! isset($keys[$this->highlighted])) {
            return;
        }

        $key = $keys[$this->highlighted];
        $match = $matches[$key];

        $this->typedValue = is_array($match) ? ($match['value'] ?? $match[0] ?? (string) $key) : (string) $key;
        $this->cursorPosition = mb_strlen($this->typedValue);
    }

    /**
     * Get options that match the input.
     *
     * @return array<int|string, string|array>
     */
    public function matches(): array
    {
        if (is_array($this->matches)) {
            return $this->matches;
        }

        if ($this->options instanceof Closure) {
            $matches = ($this->options)($this->typedValue);
            $this->matches = $matches instanceof Collection ? $matches->all() : $matches;
        } else {
            $this->matches = array_filter($this->options, function ($option, $key) {
                $value = is_array($option) ? ($option['value'] ?? $option[0] ?? $key) : $key;
                $label = is_array($option) ? ($option['label'] ?? $option[1] ?? $value) : $option;

                return $this->typedValue === ''
                    || Str::contains(mb_strtolower($value), mb_strtolower($this->typedValue))
                    || Str::contains(mb_strtolower($label), mb_strtolower($this->typedValue));
            }, ARRAY_FILTER_USE_BOTH);
        }

        $this->updateInlineSuggestion();

        return $this->matches;
    }

    /**
     * The current visible matches.
     *
     * @return array<int|string, string|array>
     */
    public function visible(): array
    {
        return array_slice($this->matches(), $this->firstVisible, $this->scroll, preserve_keys: true);
    }

    /**
     * Get the entered value with a virtual cursor.
     */
    public function valueWithCursor(int $maxWidth): string
    {
        // If navigating in any menu - don't show cursor in input
        if (($this->showAutocomplete && $this->highlighted !== null)
            || ($this->showHistoryMenu && $this->historyHighlighted !== null)) {
            return $this->typedValue === ''
                ? $this->dim($this->truncate($this->placeholder, $maxWidth))
                : $this->truncate($this->typedValue, $maxWidth);
        }

        if ($this->typedValue === '') {
            return $this->dim($this->addCursor($this->placeholder, 0, $maxWidth));
        }

        return $this->addCursor($this->typedValue, $this->cursorPosition, $maxWidth);
    }

    /**
     * Get the inline suggestion (ghost text).
     */
    public function getInlineSuggestion(): ?string
    {
        return $this->inlineSuggestion;
    }

    /**
     * Get the ghost text (part after current input).
     */
    public function getGhostText(): string
    {
        if ($this->inlineSuggestion === null || $this->typedValue === '') {
            return '';
        }

        if (Str::startsWith(mb_strtolower($this->inlineSuggestion), mb_strtolower($this->typedValue))) {
            return mb_substr($this->inlineSuggestion, mb_strlen($this->typedValue));
        }

        return '';
    }

    /**
     * Get the current search value.
     */
    public function searchValue(): string
    {
        return $this->typedValue;
    }

    /**
     * Detect command prefix from value.
     */
    public function getCommandPrefix(): ?string
    {
        $value = ltrim($this->typedValue);

        // Check for modifier + prefix
        if (mb_strlen($value) >= 2) {
            $first = mb_substr($value, 0, 1);
            $second = mb_substr($value, 1, 1);

            if (in_array($first, self::MODIFIERS, true) && in_array($second, self::PREFIXES, true)) {
                return $first.$second;
            }
        }

        // Check for just prefix
        if (mb_strlen($value) >= 1) {
            $first = mb_substr($value, 0, 1);

            if (in_array($first, self::PREFIXES, true)) {
                return $first;
            }
        }

        return null;
    }

    /**
     * Get the prefix color based on command type.
     */
    public function getPrefixColor(): string
    {
        $prefix = $this->getCommandPrefix();

        if ($prefix === null) {
            return 'white';
        }

        // Extract actual command prefix (after modifier if present)
        $cmdPrefix = mb_strlen($prefix) === 2 ? mb_substr($prefix, 1, 1) : $prefix;

        return match ($cmdPrefix) {
            '/' => 'cyan',
            '!' => 'yellow',
            '@' => 'green',
            '?' => 'magenta',
            default => 'white',
        };
    }

    /**
     * Check if current input is a valid command format.
     */
    public function isValidCommand(): bool
    {
        return $this->getCommandPrefix() !== null;
    }

    /**
     * Get the value of the prompt.
     */
    public function value(): mixed
    {
        return $this->typedValue;
    }

    /**
     * Enable auto-refresh mode.
     *
     * @param  int  $intervalMs  Refresh interval in milliseconds (default: 1000)
     * @param  Closure|null  $onTick  Callback to run on each tick (receives $this)
     */
    public function withAutoRefresh(int $intervalMs = 1000, ?Closure $onTick = null): static
    {
        $this->autoRefresh = true;
        $this->refreshIntervalMs = max(100, $intervalMs); // Minimum 100ms
        $this->onTick = $onTick;

        return $this;
    }

    /**
     * Disable auto-refresh mode.
     */
    public function withoutAutoRefresh(): static
    {
        $this->autoRefresh = false;
        $this->onTick = null;

        return $this;
    }

    /**
     * Check if auto-refresh is enabled.
     */
    public function isAutoRefreshEnabled(): bool
    {
        return $this->autoRefresh;
    }

    /**
     * Run the prompt with auto-refresh support.
     */
    public function prompt(): mixed
    {
        $this->setAsActive();

        try {
            if (! $this->autoRefresh) {
                // Standard behavior - use parent prompt()
                return parent::prompt();
            }

            // Auto-refresh mode with custom loop
            return $this->runWithAutoRefresh();
        } finally {
            $this->clearActive();
        }
    }

    /**
     * Run the prompt loop with auto-refresh.
     */
    protected function runWithAutoRefresh(): mixed
    {
        $this->capturePreviousNewLines();

        if (static::shouldFallback()) {
            return $this->fallback();
        }

        $this->checkEnvironment();

        // Set up terminal in raw mode (disable echo, canonical mode)
        $this->terminal()->setTty('-icanon -echo');

        register_shutdown_function(fn () => $this->restoreTerminal());

        $this->hideCursor();
        $this->render();

        $lastRender = $this->currentTimeMs();

        try {
            while (true) {
                // Read with timeout
                $key = $this->readKeyWithTimeout();

                // Process key if we got one
                if ($key !== null && $key !== '') {
                    $this->emit('key', $key);

                    if ($this->state === 'submit') {
                        $this->submit();
                        $this->restoreTerminal();

                        return $this->value();
                    }

                    if ($this->state === 'cancel') {
                        $this->state = 'cancel';
                        $this->render();
                        $this->restoreTerminal();

                        return $this->handleCancel();
                    }
                }

                // Check if it's time to refresh
                $now = $this->currentTimeMs();
                if ($now - $lastRender >= $this->refreshIntervalMs) {
                    // Run tick callback if set
                    if ($this->onTick !== null) {
                        ($this->onTick)($this);
                    }

                    $this->render();
                    $lastRender = $now;
                } elseif ($key !== null && $key !== '') {
                    // Re-render after key press
                    $this->render();
                }
            }
        } finally {
            $this->restoreTerminal();
        }
    }

    /**
     * Restore terminal to normal mode.
     */
    protected function restoreTerminal(): void
    {
        // Restore TTY settings
        $this->terminal()->restoreTty();

        // Show cursor with explicit ANSI code and flush
        echo "\033[?25h";
        flush();

        // Also call the parent method
        $this->showCursor();
    }

    /**
     * Read a key with timeout (non-blocking).
     */
    protected function readKeyWithTimeout(): ?string
    {
        // Fix m-1: Check if stream_select is available (POSIX function)
        if (! function_exists('stream_select')) {
            // Fallback for Windows: use blocking read with short timeout
            return $this->terminal()->read();
        }

        $stdin = STDIN;
        $read = [$stdin];
        $write = $except = [];

        // Convert ms to seconds and microseconds
        $sec = (int) ($this->refreshIntervalMs / 1000);
        $usec = ($this->refreshIntervalMs % 1000) * 1000;

        // Use shorter timeout for more responsive refresh
        $timeoutSec = 0;
        $timeoutUsec = min($usec, 100000); // Max 100ms per check

        $result = @stream_select($read, $write, $except, $timeoutSec, $timeoutUsec);

        if ($result > 0) {
            return $this->terminal()->read();
        }

        return null;
    }

    /**
     * Get current time in milliseconds.
     */
    protected function currentTimeMs(): float
    {
        return microtime(true) * 1000;
    }

    /**
     * Handle cancel action.
     */
    protected function handleCancel(): mixed
    {
        // Force restore terminal with stty sane (most reliable)
        @exec('stty sane 2>/dev/null');

        // Show cursor
        echo "\033[?25h";
        flush();
        $this->showCursor();

        if (isset($this->cancelUsing)) {
            return ($this->cancelUsing)();
        }

        exit(1);
    }

    /**
     * Check environment requirements.
     */
    protected function checkEnvironment(): void
    {
        // Fix m-1: Add fallback for stream_isatty (POSIX function)
        if (! function_exists('stream_isatty')) {
            // Assume TTY on Windows where stream_isatty is not available
            return;
        }

        // Ensure we're in a TTY
        if (! stream_isatty(STDIN)) {
            throw new \RuntimeException('Input must be a TTY.');
        }
    }

    /**
     * Set the number of lines above the prompt that should be refreshed.
     * The onTick callback will receive this info and can use ANSI to update that area.
     */
    public function setExternalContentLines(int $lines): static
    {
        $this->externalContentLines = $lines;

        return $this;
    }

    /**
     * Get the number of external content lines.
     */
    public function getExternalContentLines(): int
    {
        return $this->externalContentLines;
    }

    /**
     * Set a dynamic status line that updates on each render.
     *
     * @param  Closure  $callback  Closure that returns status string (plain text or ANSI)
     */
    public function withStatusLine(Closure $callback): static
    {
        $this->statusLineCallback = $callback;

        return $this;
    }

    /**
     * Get the current status line content.
     */
    public function getStatusLine(): ?string
    {
        if ($this->statusLineCallback === null) {
            return null;
        }

        return ($this->statusLineCallback)($this);
    }

    /**
     * Check if prompt has a status line.
     */
    public function hasStatusLine(): bool
    {
        return $this->statusLineCallback !== null;
    }

    /**
     * Get the currently active prompt instance.
     * Returns null if no prompt is currently running.
     */
    public static function getActiveInstance(): ?self
    {
        return self::$activeInstance;
    }

    /**
     * Request a re-render from outside the prompt loop.
     * Safe to call from ReactPHP callbacks, async handlers, etc.
     * Does NOT clear the current typed value.
     *
     * @return bool True if request was queued, false if no active prompt
     */
    public static function requestRender(): bool
    {
        if (self::$activeInstance === null) {
            return false;
        }

        self::$activeInstance->renderRequested = true;

        return true;
    }

    /**
     * Force immediate re-render of the active prompt.
     * Use with caution - may cause visual glitches if called during render.
     *
     * @return bool True if rendered, false if no active prompt
     */
    public static function forceRender(): bool
    {
        if (self::$activeInstance === null) {
            return false;
        }

        self::$activeInstance->render();

        return true;
    }

    /**
     * Check if there's a pending render request.
     */
    public function hasPendingRender(): bool
    {
        return $this->renderRequested;
    }

    /**
     * Clear the pending render request flag.
     */
    public function clearPendingRender(): void
    {
        $this->renderRequested = false;
    }

    /**
     * Set this instance as the active one.
     */
    protected function setAsActive(): void
    {
        self::$activeInstance = $this;
    }

    /**
     * Clear the active instance reference.
     */
    protected function clearActive(): void
    {
        if (self::$activeInstance === $this) {
            self::$activeInstance = null;
        }
    }

    // =========================================================================
    // ReactPHP Integration
    // =========================================================================

    /**
     * ReactPHP event loop instance.
     */
    protected ?LoopInterface $loop = null;

    /**
     * ReactPHP STDIN stream.
     */
    protected ?ReadableResourceStream $stdinStream = null;

    /**
     * Deferred promise for async prompt resolution.
     */
    protected ?Deferred $deferred = null;

    /**
     * Periodic timer for auto-refresh in React mode.
     */
    protected mixed $refreshTimer = null;

    /**
     * Run the prompt asynchronously with ReactPHP event loop.
     * Returns a Promise that resolves with the input value.
     *
     * Usage:
     * ```php
     * $loop = Loop::get();
     * $prompt->promptAsync($loop)->then(function ($value) {
     *     echo "Got: $value\n";
     * });
     * $loop->run();
     * ```
     *
     * @param  LoopInterface|null  $loop  Event loop (uses global loop if null)
     * @param  int  $refreshIntervalMs  Auto-refresh interval in ms (0 = disabled)
     * @return PromiseInterface<string>
     */
    public function promptAsync(?LoopInterface $loop = null, int $refreshIntervalMs = 1000): PromiseInterface
    {
        $this->loop = $loop ?? Loop::get();
        $this->deferred = new Deferred();
        $this->setAsActive();

        // Check environment
        if (static::shouldFallback()) {
            $this->deferred->resolve($this->fallback());

            return $this->deferred->promise();
        }

        // Set up terminal
        $this->terminal()->setTty('-icanon -echo');
        $this->hideCursor();
        $this->capturePreviousNewLines();
        $this->render();

        // Set up STDIN stream with ReactPHP
        stream_set_blocking(STDIN, false);
        $this->stdinStream = new ReadableResourceStream(STDIN, $this->loop);

        // Buffer for multi-byte sequences (arrow keys, etc.)
        $inputBuffer = '';

        $this->stdinStream->on('data', function (string $data) use (&$inputBuffer) {
            // Fix M-3: Wrap data handler in try-catch to prevent promise hanging on errors
            try {
                $inputBuffer .= $data;

                // Process complete key sequences
                while ($inputBuffer !== '') {
                    $key = $this->extractKeyFromBuffer($inputBuffer);

                    if ($key === null) {
                        // Incomplete sequence, wait for more data
                        break;
                    }

                    $this->processKeyReact($key);

                    if ($this->state === 'submit' || $this->state === 'cancel') {
                        $this->cleanupReact();

                        if ($this->state === 'submit') {
                            $this->submit();
                            $this->deferred->resolve($this->value());
                        } else {
                            $this->deferred->reject(new \RuntimeException('Cancelled'));
                        }

                        return;
                    }

                    $this->render();
                }
            } catch (\Throwable $e) {
                $this->cleanupReact();
                $this->deferred->reject($e);
            }
        });

        // Set up auto-refresh timer
        if ($refreshIntervalMs > 0) {
            // Fix M-6: Explicit type cast for timer interval (ms to seconds)
            $this->refreshTimer = $this->loop->addPeriodicTimer(
                (float) ($refreshIntervalMs / 1000),
                function () {
                    // Check for pending render requests
                    if ($this->renderRequested) {
                        $this->clearPendingRender();
                    }

                    // Run tick callback
                    if ($this->onTick !== null) {
                        ($this->onTick)($this);
                    }

                    $this->render();
                }
            );
        }

        // Handle stream close/error
        $this->stdinStream->on('close', function () {
            $this->cleanupReact();
            $this->deferred->reject(new \RuntimeException('STDIN closed'));
        });

        return $this->deferred->promise();
    }

    /**
     * Extract a complete key sequence from the input buffer.
     * Returns null if the buffer contains an incomplete escape sequence.
     */
    protected function extractKeyFromBuffer(string &$buffer): ?string
    {
        if ($buffer === '') {
            return null;
        }

        $firstByte = $buffer[0];

        // Escape sequence (arrow keys, function keys, etc.)
        if ($firstByte === "\x1b") {
            // Need at least 3 chars for basic escape sequences like \x1b[A
            if (strlen($buffer) < 3) {
                return null; // Wait for more data
            }

            // Check for common escape sequences
            if ($buffer[1] === '[') {
                // CSI sequence
                $length = 3;

                // Some sequences are longer (e.g., \x1b[1;5A for Ctrl+Arrow)
                if (strlen($buffer) >= 4 && ctype_digit($buffer[2])) {
                    // Find the end of the sequence (a letter)
                    for ($i = 3; $i < strlen($buffer); $i++) {
                        if (ctype_alpha($buffer[$i]) || $buffer[$i] === '~') {
                            $length = $i + 1;
                            break;
                        }
                    }
                }

                $key = substr($buffer, 0, $length);
                $buffer = substr($buffer, $length);

                return $key;
            }

            // Other escape sequences (Alt+key)
            if (strlen($buffer) >= 2) {
                $key = substr($buffer, 0, 2);
                $buffer = substr($buffer, 2);

                return $key;
            }

            return null;
        }

        // Multi-byte UTF-8 character
        $charLen = 1;
        $ord = ord($firstByte);

        if (($ord & 0xE0) === 0xC0) {
            $charLen = 2;
        } elseif (($ord & 0xF0) === 0xE0) {
            $charLen = 3;
        } elseif (($ord & 0xF8) === 0xF0) {
            $charLen = 4;
        }

        if (strlen($buffer) < $charLen) {
            return null; // Wait for more data
        }

        $key = substr($buffer, 0, $charLen);
        $buffer = substr($buffer, $charLen);

        return $key;
    }

    /**
     * Process a key in ReactPHP mode.
     */
    protected function processKeyReact(string $key): void
    {
        $this->emit('key', $key);
    }

    /**
     * Clean up ReactPHP resources.
     */
    protected function cleanupReact(): void
    {
        // Cancel refresh timer
        if ($this->refreshTimer !== null && $this->loop !== null) {
            $this->loop->cancelTimer($this->refreshTimer);
            $this->refreshTimer = null;
        }

        // Remove listeners from STDIN stream but DON'T close it
        // (closing would close the underlying STDIN resource)
        if ($this->stdinStream !== null) {
            // Fix M-4: Add try-catch around stream cleanup (may fail if stream already closed)
            try {
                $this->stdinStream->removeAllListeners();
                $this->stdinStream->pause();
            } catch (\Exception $e) {
                // Stream already closed - safe to ignore
            }
            // Don't call close() - it would close STDIN!
            $this->stdinStream = null;
        }

        // Restore terminal blocking mode
        stream_set_blocking(STDIN, true);

        // Restore terminal
        $this->restoreTerminal();
        $this->clearActive();

        // Stop the event loop
        if ($this->loop !== null) {
            $this->loop->stop();
        }
    }

    /**
     * Trigger a re-render from ReactPHP context.
     * Safe to call from any async callback.
     */
    public function triggerRender(): void
    {
        if ($this->loop !== null) {
            // Schedule render on next tick to avoid conflicts
            $this->loop->futureTick(fn () => $this->render());
        } else {
            $this->render();
        }
    }

    /**
     * Get the ReactPHP event loop (if running in async mode).
     */
    public function getLoop(): ?LoopInterface
    {
        return $this->loop;
    }

    /**
     * Check if running in ReactPHP async mode.
     */
    public function isAsyncMode(): bool
    {
        return $this->loop !== null && $this->deferred !== null;
    }
}