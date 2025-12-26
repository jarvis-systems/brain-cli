<?php

declare(strict_types=1);

namespace BrainCLI\Console\AiCommands\Lab;

use BrainCLI\Console\AiCommands\LabCommand;
use Illuminate\Support\Arr;

/**
 * Variable persistence manager for Brain Lab.
 *
 * Manages workspace variables with dot-notation support,
 * JSON persistence to workspace.json, and state management.
 *
 * @see .docs/tor/lab-specification-part-1.md Section 3.4
 */
class WorkSpace
{
    public array $variables = [];
    /** @var array<string, mixed> Reserved for future state management (undo/redo history) */
    public array $states = [];

    protected string $workspaceFile;

    /**
     * Initialize workspace with file path.
     *
     * @param LabCommand $command Lab command instance
     * @param string $laboratoryPath Path to laboratory directory
     */
    public function __construct(
        protected LabCommand $command,
        protected string $laboratoryPath,
    ) {
        $this->workspaceFile = implode(DS, [$this->laboratoryPath, 'workspace.json']);

        $this->load();
    }

    /**
     * Set a workspace variable with dot-notation support.
     *
     * Supports nested paths like "user.name" or "items.0.id".
     *
     * @param string $name Variable name (supports dot notation)
     * @param mixed $value Value to set
     * @return void
     */
    public function setVariable(string $name, mixed $value = null): void
    {
        data_set($this->variables, $name, $value);
        $this->save();
    }

    /**
     * Get a workspace variable with dot-notation support.
     *
     * @param string $name Variable name (supports dot notation)
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public function getVariable(string $name, mixed $default = null): mixed
    {
        return data_get($this->variables, $name, $default);
    }

    /**
     * Remove a workspace variable.
     *
     * @param string $name Variable name (supports dot notation)
     * @return void
     */
    public function forgetVariable(string $name): void
    {
        data_forget($this->variables, $name);
        $this->save();
    }

    /**
     * Flatten all variables to dot notation format.
     *
     * Converts nested arrays to flat key-value pairs with dot-separated keys.
     *
     * @return array<string, mixed>
     */
    public function dotsVariables(): array
    {
        return Arr::dot($this->variables);
    }

    /**
     * Persist workspace variables to JSON file.
     *
     * @return bool True on success, false on failure
     */
    public function save(): bool
    {
        return (bool) file_put_contents(
            $this->workspaceFile,
            json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Convert public properties to array.
     *
     * Uses Reflection to filter only public properties, ensuring
     * private/protected state is not exposed during serialization.
     * Compatible with PHP 8+ property visibility.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $reflection = new \ReflectionClass($this);
        return array_filter(
            get_object_vars($this),
            fn ($key) => $reflection->getProperty($key)->isPublic(),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Load workspace variables from JSON file.
     *
     * Performs type-safe merge: only loads values that match
     * the existing property types. Arrays are merged, scalars replaced.
     *
     * @return void
     */
    public function load(): void
    {
        if (!$this->canLoadFile()) {
            return;
        }

        $data = $this->readWorkspaceData();
        if ($data === null) {
            return;
        }

        foreach ($data as $key => $value) {
            $this->mergeProperty($key, $value);
        }
    }

    /**
     * Check if workspace file exists and is readable.
     *
     * @return bool True if file can be loaded
     */
    private function canLoadFile(): bool
    {
        return is_file($this->workspaceFile);
    }

    /**
     * Read and decode workspace JSON file.
     *
     * @return array<string, mixed>|null Decoded data or null on failure
     */
    private function readWorkspaceData(): ?array
    {
        $content = file_get_contents($this->workspaceFile);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Merge a single property value with type validation.
     *
     * Only merges if property exists and types match.
     * Arrays are merged, scalars are replaced.
     *
     * @param string $key Property name
     * @param mixed $value Value from loaded data
     * @return void
     */
    private function mergeProperty(string $key, mixed $value): void
    {
        if (!property_exists($this, $key)) {
            return;
        }

        $reflection = new \ReflectionProperty($this, $key);
        if (!$reflection->isPublic()) {
            return;
        }

        // Cache type checks to avoid duplicate get_debug_type() calls
        $dataType = get_debug_type($value);
        $thisType = get_debug_type($this->{$key});

        if ($dataType !== $thisType) {
            return;
        }

        // Explicit array validation with null coalesce for safety
        if (is_array($this->{$key}) && is_array($value)) {
            $this->{$key} = array_merge($this->{$key} ?? [], $value);
        } else {
            $this->{$key} = $value;
        }
    }
}
