<?php

declare(strict_types=1);

namespace BrainCLI\Console\AiCommands\Lab;

use BrainCLI\Console\AiCommands\Lab\Dto\Tab;
use function Termwind\render;

/**
 * TabBar component for Lab REPL interface.
 * Renders tab bar with type icons, states, and visual indicators.
 *
 * Tab Bar Specification:
 * - Height: 1 line
 * - Tab types: Main [M], Process [P], Agent [@], New [+]
 * - Visual indicators: ● (active/running), ○ (inactive), ✓ (completed), ✗ (error), ◉ (has updates)
 * - Colors: Cyan (active), Gray (inactive), Yellow (has updates), Red (error), Green (completed)
 * - Layout: Single line with flex spacing
 */
class TabBar
{
    /**
     * Tab name truncation limit
     */
    private const TAB_MAX_LENGTH = 15;

    /**
     * Termwind CSS color classes for tab states
     */
    private const COLOR_ACTIVE = 'bg-cyan-600 text-white font-bold';
    private const COLOR_INACTIVE = 'bg-gray-700 text-gray-400';
    private const COLOR_HAS_UPDATES = 'bg-yellow-600 text-black font-bold';
    private const COLOR_ERROR = 'bg-red-600 text-white font-bold';
    private const COLOR_COMPLETED = 'bg-green-600 text-white';

    /**
     * Render tab bar as single line with all active and inactive tabs.
     *
     * @param Tab[] $tabs Array of Tab DTOs
     * @return void
     */
    public function render(array $tabs): void
    {
        if (empty($tabs)) {
            return;
        }

        $html = '<div class="flex space-x-1">';

        foreach ($tabs as $tab) {
            $html .= $this->renderTab($tab);
        }

        $html .= '</div>';

        render($html);
    }

    /**
     * Render single tab with type icon, indicator, and styling.
     *
     * Format: [INDICATOR] [TYPE_ICON] [NAME]
     * Example: ● [M] Main
     * Example: ✓ [P] build
     * Example: ◉ [P] agent-1
     */
    protected function renderTab(Tab $tab): string
    {
        $icon = $this->getTypeIcon($tab->type);
        $indicator = $tab->getIndicator();
        $classes = $this->getTabClasses($tab);
        $displayName = $this->truncate($tab->name, self::TAB_MAX_LENGTH);

        return sprintf(
            '<span class="%s">%s %s %s</span>',
            $classes,
            $indicator,
            $icon,
            $displayName
        );
    }

    /**
     * Get type icon for tab.
     *
     * @param string $type One of Tab::TYPE_* constants
     * @return string Icon in [X] format
     */
    protected function getTypeIcon(string $type): string
    {
        return match ($type) {
            Tab::TYPE_MAIN => '[M]',
            Tab::TYPE_PROCESS => '[P]',
            Tab::TYPE_AGENT => '[@]',
            Tab::TYPE_NEW => '[+]',
            default => '[?]',
        };
    }

    /**
     * Get Termwind CSS classes for tab based on state.
     *
     * State-based background colors:
     * - Active: Bright cyan (bg-cyan-600)
     * - Has Updates: Yellow (bg-yellow-600)
     * - Error: Red (bg-red-600)
     * - Completed: Green (bg-green-600)
     * - Inactive: Gray (bg-gray-700)
     */
    protected function getTabClasses(Tab $tab): string
    {
        $bgClass = match ($tab->state) {
            Tab::STATE_ACTIVE => self::COLOR_ACTIVE,
            Tab::STATE_HAS_UPDATES => self::COLOR_HAS_UPDATES,
            Tab::STATE_ERROR => self::COLOR_ERROR,
            Tab::STATE_COMPLETED => self::COLOR_COMPLETED,
            Tab::STATE_INACTIVE => self::COLOR_INACTIVE,
            default => self::COLOR_INACTIVE,
        };

        return "px-2 py-1 {$bgClass}";
    }

    /**
     * Truncate tab name to maximum length with ellipsis.
     *
     * @param string $text Tab name to truncate
     * @param int $maxLength Maximum display length (including ellipsis)
     * @return string Truncated text with ellipsis if needed
     */
    protected function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 1) . '…';
    }
}