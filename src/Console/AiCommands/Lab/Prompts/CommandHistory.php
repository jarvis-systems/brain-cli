<?php

declare(strict_types=1);

namespace BrainCLI\Console\AiCommands\Lab\Prompts;

/**
 * Manages command history with file persistence.
 *
 * Features:
 * - Persistent storage in file
 * - No duplicates (moves existing to top)
 * - Configurable max size
 * - Navigation through history
 */
class CommandHistory
{
    /**
     * History entries (newest first).
     *
     * @var array<string>
     */
    protected array $entries = [];

    /**
     * Current position in history (-1 = not browsing).
     */
    protected int $position = -1;

    /**
     * Temporary input saved when starting to browse history.
     */
    protected ?string $savedInput = null;

    /**
     * Create a new CommandHistory instance.
     */
    public function __construct(
        protected ?string $filePath = null,
        protected int $maxSize = 100,
    ) {
        $this->load();
    }

    /**
     * Load history from file.
     */
    protected function load(): void
    {
        if ($this->filePath === null || ! file_exists($this->filePath)) {
            return;
        }

        $content = file_get_contents($this->filePath);

        if ($content === false) {
            return;
        }

        $lines = array_filter(
            array_map('trim', explode("\n", $content)),
            fn ($line) => $line !== ''
        );

        // Reverse to have newest first, then take maxSize
        $this->entries = array_slice(array_reverse($lines), 0, $this->maxSize);
    }

    /**
     * Save history to file.
     */
    protected function save(): void
    {
        if ($this->filePath === null) {
            return;
        }

        $dir = dirname($this->filePath);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Save in reverse order (oldest first in file, newest last)
        $content = implode("\n", array_reverse($this->entries))."\n";
        file_put_contents($this->filePath, $content);
    }

    /**
     * Add a command to history (no duplicates, moves to top if exists).
     */
    public function add(string $command): void
    {
        $command = trim($command);

        if ($command === '') {
            return;
        }

        // Remove if already exists (will be added to top)
        $this->entries = array_values(array_filter(
            $this->entries,
            fn ($entry) => $entry !== $command
        ));

        // Add to beginning (newest first)
        array_unshift($this->entries, $command);

        // Trim to max size
        if (count($this->entries) > $this->maxSize) {
            $this->entries = array_slice($this->entries, 0, $this->maxSize);
        }

        $this->save();
        $this->resetNavigation();
    }

    /**
     * Get all history entries.
     *
     * @return array<string>
     */
    public function all(): array
    {
        return $this->entries;
    }

    /**
     * Get history entries excluding given keys.
     *
     * @param array<string> $exclude Keys to exclude
     * @return array<string>
     */
    public function except(array $exclude): array
    {
        return array_values(array_filter(
            $this->entries,
            fn ($entry) => ! in_array($entry, $exclude, true)
        ));
    }

    /**
     * Check if command exists in history.
     */
    public function has(string $command): bool
    {
        return in_array(trim($command), $this->entries, true);
    }

    /**
     * Start navigating history (save current input).
     */
    public function startNavigation(string $currentInput): void
    {
        if ($this->savedInput === null) {
            $this->savedInput = $currentInput;
            $this->position = -1;
        }
    }

    /**
     * Navigate to previous (older) command.
     */
    public function previous(): ?string
    {
        if (empty($this->entries)) {
            return null;
        }

        if ($this->position < count($this->entries) - 1) {
            $this->position++;

            return $this->entries[$this->position];
        }

        return $this->entries[$this->position] ?? null;
    }

    /**
     * Navigate to next (newer) command or back to saved input.
     */
    public function next(): ?string
    {
        if ($this->position > 0) {
            $this->position--;

            return $this->entries[$this->position];
        }

        if ($this->position === 0) {
            $this->position = -1;

            return $this->savedInput;
        }

        return $this->savedInput;
    }

    /**
     * Get current history entry or null if not navigating.
     */
    public function current(): ?string
    {
        if ($this->position < 0 || $this->position >= count($this->entries)) {
            return null;
        }

        return $this->entries[$this->position];
    }

    /**
     * Check if currently navigating history.
     */
    public function isNavigating(): bool
    {
        return $this->position >= 0;
    }

    /**
     * Reset navigation state.
     */
    public function resetNavigation(): void
    {
        $this->position = -1;
        $this->savedInput = null;
    }

    /**
     * Get the saved input from before navigation started.
     */
    public function getSavedInput(): ?string
    {
        return $this->savedInput;
    }

    /**
     * Get count of history entries.
     */
    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Clear all history.
     */
    public function clear(): void
    {
        $this->entries = [];
        $this->save();
        $this->resetNavigation();
    }
}