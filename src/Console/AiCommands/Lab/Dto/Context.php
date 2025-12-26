<?php

declare(strict_types=1);

namespace BrainCLI\Console\AiCommands\Lab\Dto;

use Bfg\Dto\Attributes\DtoExceptProperty;
use Bfg\Dto\Dto;
use BrainCLI\Console\AiCommands\Lab\Abstracts\ScreenAbstract;
use BrainCLI\Console\AiCommands\Lab\Dto\Tab;

/**
 * Request/Response DTO for Lab command execution.
 *
 * Carries command result, messages, next suggestions, and deferred processes.
 * Supports selective merge and onChange persistence callbacks.
 */
class Context extends Dto
{
    public function __construct(
        protected array|null $result = null,
        #[DtoExceptProperty]
        protected array|null $info = null,
        #[DtoExceptProperty]
        protected array|null $error = null,
        #[DtoExceptProperty]
        protected array|null $success = null,
        #[DtoExceptProperty]
        protected array|null $warning = null,
        protected array|null $nextVariants = null,
        protected string|null $nextCommand = null,
        #[DtoExceptProperty]
        protected string|bool $pause = false,
        protected array $processes = [],
        protected array|null $tabs = null,
        protected string|null $activeTab = null,
    ) {
    }

    public function isOk(): bool
    {
        return empty($this->error);
    }

    public function getError(): string|false
    {
        if ($this->isOk()) {
            return false;
        }
        return implode(PHP_EOL, $this->getAsArray('error'));
    }

    public function clearMeta(): static
    {
        $this->info = null;
        $this->error = null;
        $this->success = null;
        $this->warning = null;
        $this->nextCommand = null;
        $this->pause = false;
        $this->tabs = null;
        $this->activeTab = null;

        return $this;
    }

    /**
     * Set or append command result with optional merge mode.
     *
     * Supports dot notation for nested result appending.
     * Triggers onChange callback when result changes.
     *
     * @param array|null $value Result value to set or append
     * @param bool $append Whether to append to existing result (default: false)
     * @return static Fluent interface
     */
    public function result(array|null $value, bool $append = false): static
    {
        if ($append && is_array($value)) {
            foreach ($value as $key => $item) {
                if (!is_array($this->result)) {
                    $this->result = [];
                }
                // Support dot notation for nested result appending (e.g., "data.items")
                if (is_string($key)) {
                    data_set($this->result, $key, $item);
                } else {
                    $this->result[] = $item;
                }
            }
        } else {
            $this->result = $value;
        }

        $this->onChangeEmit();

        return $this;
    }

    /**
     * Add informational message to context.
     *
     * Auto-normalizes string input to array format.
     * Appends to existing messages if present.
     *
     * @param string|array|null $info Message text or array of messages
     * @param bool $append Whether to append to existing messages (default: false)
     * @return static Fluent interface
     */
    public function info(string|array|null $info, bool $append = false): static
    {
        $info = is_string($info) ? [$info] : $info;

        if ($append && is_array($info)) {
            $this->info = array_merge($this->info ?: [], $info);
        } else {
            $this->info = $info;
        }

        return $this;
    }

    /**
     * Add error message to context.
     *
     * Auto-normalizes string input to array format.
     * Appends to existing error messages if present.
     *
     * @param string|array|null $error Error text or array of errors
     * @param bool $append Whether to append to existing errors (default: false)
     * @return static Fluent interface
     */
    public function error(string|array|null $error, bool $append = false): static
    {
        $error = is_string($error) ? [$error] : $error;

        if ($append && is_array($error)) {
            $this->error = array_merge($this->error ?: [], $error);
        } else {
            $this->error = $error;
        }

        return $this;
    }

    /**
     * Add success message to context.
     *
     * Auto-normalizes string input to array format.
     * Appends to existing success messages if present.
     *
     * @param string|array|null $success Success text or array of messages
     * @param bool $append Whether to append to existing success messages (default: false)
     * @return static Fluent interface
     */
    public function success(string|array|null $success, bool $append = false): static
    {
        $success = is_string($success) ? [$success] : $success;

        if ($append && is_array($success)) {
            $this->success = array_merge($this->success ?: [], $success);
        } else {
            $this->success = $success;
        }

        return $this;
    }

    /**
     * Add warning message to context.
     *
     * Auto-normalizes string input to array format.
     * Appends to existing warning messages if present.
     *
     * @param string|array|null $warning Warning text or array of warnings
     * @param bool $append Whether to append to existing warnings (default: false)
     * @return static Fluent interface
     */
    public function warning(string|array|null $warning, bool $append = false): static
    {
        $warning = is_string($warning) ? [$warning] : $warning;

        if ($append && is_array($warning)) {
            $this->warning = array_merge($this->warning ?: [], $warning);
        } else {
            $this->warning = $warning;
        }

        return $this;
    }

    /**
     * Set autocomplete suggestions for next command.
     *
     * Provides command completion options displayed to user.
     * Triggers onChange callback.
     *
     * @param string|array|null $nextVariant Array of suggestion strings
     * @param bool $append Whether to append to existing variants (default: false)
     * @return static Fluent interface
     */
    public function nextVariants(string|array|null $nextVariant, bool $append = false): static
    {
        $nextVariant = is_string($nextVariant) ? [$nextVariant] : $nextVariant;

        if ($append && is_array($nextVariant)) {
            $this->nextVariants = array_merge($this->nextVariants ?: [], $nextVariant);
        } else {
            $this->nextVariants = $nextVariant;
        }

        $this->onChangeEmit();

        return $this;
    }

    /**
     * Set the next command to execute in chain.
     *
     * Supports optional argument concatenation.
     * Triggers onChange callback.
     *
     * @param string|null $nextCommand Command string to execute next
     * @param string|null $argument Optional argument to append
     * @return static Fluent interface
     */
    public function nextCommand(string|null $nextCommand, string|null $argument = null): static
    {
        $this->nextCommand = $nextCommand;

        if ($nextCommand && $argument) {
            $this->nextCommand .= ' ' . $argument;
        }

        $this->onChangeEmit();

        return $this;
    }

    public function pause(string|bool $pause = true): static
    {
        $this->pause = $pause;

        return $this;
    }

    /**
     * Register deferred process task for async execution.
     *
     * Adds process to queue with validation.
     * Triggers onChange callback.
     *
     * @param string $name Process name identifier
     * @param class-string<ScreenAbstract> $screenClass Screen class to execute
     * @param string $screenMethod Method to call on screen class
     * @param mixed ...$args Arguments to pass to method
     * @return static Fluent interface
     */
    public function process(string $name, string $screenClass, string $screenMethod, ...$args): static
    {
        if (!class_exists($screenClass) || !is_subclass_of($screenClass, ScreenAbstract::class)) {
            throw new \InvalidArgumentException("Screen class $screenClass does not exist or is not a subclass of ScreenAbstract.");
        }
        if (!method_exists($screenClass, $screenMethod)) {
            throw new \InvalidArgumentException("Method $screenMethod does not exist in class $screenClass.");
        }

        $this->processes[] = [
            'name' => $name,
            'screenClass' => $screenClass,
            'screenMethod' => $screenMethod,
            'args' => $args,
        ];

        $this->onChangeEmit();

        return $this;
    }

    /**
     * Set or append tab configuration.
     *
     * Stores tab metadata and state information.
     * Triggers onChange callback.
     *
     * @param array|null $tabs Tab configuration array
     * @param bool $append Whether to append to existing tabs (default: false)
     * @return static Fluent interface
     */
    public function tabs(array|null $tabs, bool $append = false): static
    {
        if ($append && is_array($tabs)) {
            $this->tabs = array_merge($this->tabs ?: [], $tabs);
        } else {
            $this->tabs = $tabs;
        }
        $this->onChangeEmit();
        return $this;
    }

    /**
     * Set the active tab identifier.
     *
     * Specifies which tab is currently active.
     * Triggers onChange callback.
     *
     * @param string|null $tabId Tab identifier
     * @return static Fluent interface
     */
    public function activeTab(string|null $tabId): static
    {
        $this->activeTab = $tabId;
        $this->onChangeEmit();
        return $this;
    }

    /**
     * Ensure Main tab exists. Creates it if missing.
     *
     * @return self
     */
    public function ensureMainTab(): self
    {
        // Get current tabs or initialize empty array
        $currentTabs = $this->tabs ?: [];

        // Check if Main tab already exists
        $hasMainTab = false;
        foreach ($currentTabs as $tab) {
            if ($tab instanceof Tab && $tab->name === 'Main') {
                $hasMainTab = true;
                break;
            }
        }

        if (!$hasMainTab) {
            // Create Main tab
            $currentTabs['main'] = new Tab(
                id: 'main',
                name: 'Main',
                type: Tab::TYPE_MAIN,
                state: Tab::STATE_ACTIVE
            );

            // Update tabs and set as active
            $this->tabs($currentTabs);
            $this->activeTab('main');
        }

        return $this;
    }

    public function mergeGeneral(
        Context|null $old,
        bool $result = true,
        bool $processes = true,
        bool $tabs = false,
    ): static {
        return $this->merge(
            $old,
            result: $result,
            info: false,
            error: false,
            success: false,
            warning: false,
            next: false,
            pause: false,
            processes: $processes,
            tabs: $tabs,
        );
    }

    /**
     * Selective field merging from another Context.
     *
     * Allows fine-grained control over which fields to merge:
     * result, info, error, success, warning, nextVariants, nextCommand, pause, processes, tabs.
     *
     * @param Context|null $old Source context to merge from
     * @param bool $result Merge result field (default: true)
     * @param bool $info Merge info messages (default: true)
     * @param bool $error Merge error messages (default: true)
     * @param bool $success Merge success messages (default: true)
     * @param bool $warning Merge warning messages (default: true)
     * @param bool $next Merge next variants (default: true)
     * @param bool $pause Merge pause state (default: true)
     * @param bool $processes Merge processes queue (default: true)
     * @param bool $tabs Merge tabs state (default: true)
     * @return static Fluent interface
     */
    public function merge(
        Context|null $old,
        bool $result = true,
        bool $info = true,
        bool $error = true,
        bool $success = true,
        bool $warning = true,
        bool $next = true,
        bool $pause = true,
        bool $processes = true,
        bool $tabs = true,
    ): static {
        // Merge only fields with true flags to preserve granular control
        if ($old) {
            if ($result && $old->isNotEmpty('result')) {
                $this->result($old->getAsArray('result'), true);
            }
            if ($info && $old->isNotEmpty('info')) {
                $this->info($old->getAsArray('info'), true);
            }
            if ($error && $old->isNotEmpty('error')) {
                $this->error($old->getAsArray('error'), true);
            }
            if ($success && $old->isNotEmpty('success')) {
                $this->success($old->getAsArray('success'), true);
            }
            if ($warning && $old->isNotEmpty('warning')) {
                $this->warning($old->getAsArray('warning'), true);
            }
            if ($next && $old->isNotEmpty('next')) {
                $this->nextVariants($old->getAsArray('next'), true);
            }
            if ($pause && $old->get('pause') !== false) {
                $this->pause($old->get('pause'));
            }
            if ($processes && $old->isNotEmpty('processes')) {
                foreach ($old->getAsArray('processes') as $process) {
                    $this->process(
                        $process['name'],
                        $process['screenClass'],
                        $process['screenMethod'],
                        ...$process['args']
                    );
                }
            }
            if ($tabs && $old->isNotEmpty('tabs')) {
                $this->tabs($old->getAsArray('tabs'), true);
            }
        }

        return $this;
    }

    protected function onChangeEmit(): void
    {
        $onChange = $this->getMeta('onChange');
        if (is_callable($onChange)) {
            $onChange($this);
        }
    }
}
