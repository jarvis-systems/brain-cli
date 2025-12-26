<?php

declare(strict_types=1);

namespace BrainCLI\Console\AiCommands\Lab\Dto;

use Bfg\Dto\Dto;

/**
 * Tab DTO - Represents a single tab in the Lab UI system.
 *
 * @property string $id Unique tab identifier (kebab-case, e.g., 'main', 'process-123')
 * @property string $name Display name (max 15 chars after truncation)
 * @property string $type Tab type: Main, Process, Agent, New (see TabType enum)
 * @property string $state Current state (see TabState enum for full list)
 *
 * ## State Machine Transitions:
 *
 * Inactive → Active (user switches to tab)
 * Active → Inactive (user switches away)
 * Active/Inactive → HasUpdates (new output arrives)
 * HasUpdates → Active (user views updates)
 * Any → Error (process/agent fails)
 * Any → Completed (process/agent succeeds)
 *
 * ## Usage Examples:
 *
 * ```php
 * // Create Main tab
 * $tab = new Tab(id: 'main', name: 'Main', type: Tab::TYPE_MAIN, state: Tab::STATE_ACTIVE);
 *
 * // Create Process tab
 * $tab = new Tab(id: 'proc-1', name: 'Build', type: Tab::TYPE_PROCESS, state: Tab::STATE_INACTIVE);
 *
 * // Update state
 * $tab->state = Tab::STATE_HAS_UPDATES;
 * ```
 *
 * @see TabType For available tab types
 * @see TabState For available states and indicators
 */
class Tab extends Dto
{
    public const TYPE_MAIN = 'Main';
    public const TYPE_PROCESS = 'Process';
    public const TYPE_AGENT = 'Agent';
    public const TYPE_NEW = 'New';

    public const STATE_ACTIVE = 'Active';
    public const STATE_INACTIVE = 'Inactive';
    public const STATE_HAS_UPDATES = 'HasUpdates';
    public const STATE_ERROR = 'Error';
    public const STATE_COMPLETED = 'Completed';

    public const INDICATOR_ACTIVE = '●';
    public const INDICATOR_INACTIVE = '○';
    public const INDICATOR_COMPLETED = '✓';
    public const INDICATOR_ERROR = '✗';
    public const INDICATOR_HAS_UPDATES = '◉';

    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        public string $state = 'Inactive',
        public array $content = [],
        public int $scrollPosition = 0,
        public ?array $metadata = null,
    ) {}

    public function getIndicator(): string
    {
        return match ($this->state) {
            self::STATE_ACTIVE => self::INDICATOR_ACTIVE,
            self::STATE_INACTIVE => self::INDICATOR_INACTIVE,
            self::STATE_COMPLETED => self::INDICATOR_COMPLETED,
            self::STATE_ERROR => self::INDICATOR_ERROR,
            self::STATE_HAS_UPDATES => self::INDICATOR_HAS_UPDATES,
            default => self::INDICATOR_INACTIVE,
        };
    }

    public function markActive(): static { $this->state = self::STATE_ACTIVE; return $this; }
    public function markInactive(): static { $this->state = self::STATE_INACTIVE; return $this; }
    public function markHasUpdates(): static { $this->state = self::STATE_HAS_UPDATES; return $this; }
    public function markError(): static { $this->state = self::STATE_ERROR; return $this; }
    public function markCompleted(): static { $this->state = self::STATE_COMPLETED; return $this; }
    public function isActive(): bool { return $this->state === self::STATE_ACTIVE; }
    public function isError(): bool { return $this->state === self::STATE_ERROR; }
    public function hasUpdates(): bool { return $this->state === self::STATE_HAS_UPDATES; }
    public function addLine(string $line): static { $this->content[] = $line; return $this; }
    public function clearContent(): static { $this->content = []; $this->scrollPosition = 0; return $this; }
    public function getLineCount(): int { return count($this->content); }
}