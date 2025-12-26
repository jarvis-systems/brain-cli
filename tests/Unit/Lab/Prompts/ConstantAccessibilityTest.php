<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Lab\Prompts;

use BrainCLI\Console\AiCommands\Lab\Prompts\CommandLinePrompt;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Test constant accessibility and usage in CommandLinePrompt.
 *
 * Validates that EVENT_TAB_NEXT and EVENT_TAB_PREV constants are:
 * 1. Publicly accessible from external classes (Screen.php)
 * 2. Used as class constants (not hardcoded literals) in Screen.php
 * 3. Properly emitted internally via self:: in CommandLinePrompt.php
 * 4. Have correct visibility (public) via reflection
 *
 * Task #24 Step 3: Unit test coverage for constant accessibility.
 */
class ConstantAccessibilityTest extends TestCase
{
    /**
     * Test that EVENT_TAB_NEXT and EVENT_TAB_PREV constants are publicly accessible.
     *
     * This validates that external classes (like Screen.php) can access these
     * constants via CommandLinePrompt::CONSTANT_NAME syntax.
     */
    public function test_event_constants_are_publicly_accessible(): void
    {
        $this->assertEquals('tab-next', CommandLinePrompt::EVENT_TAB_NEXT);
        $this->assertEquals('tab-previous', CommandLinePrompt::EVENT_TAB_PREV);
    }

    /**
     * Test that Screen.php uses constants instead of hardcoded literals.
     *
     * Verifies that Screen.php:submit() uses CommandLinePrompt::EVENT_TAB_NEXT
     * and CommandLinePrompt::EVENT_TAB_PREV constants (lines 609, 612) rather
     * than hardcoded string values.
     */
    public function test_screen_uses_constants_not_literals(): void
    {
        $screenPath = __DIR__ . '/../../../../../src/Console/AiCommands/Lab/Screen.php';
        $this->assertFileExists($screenPath, 'Screen.php must exist at expected path');

        $screenContent = file_get_contents($screenPath);
        $this->assertIsString($screenContent, 'Failed to read Screen.php');

        // Verify Screen.php uses constants via CommandLinePrompt::
        $this->assertStringContainsString(
            'CommandLinePrompt::EVENT_TAB_NEXT',
            $screenContent,
            'Screen.php should use CommandLinePrompt::EVENT_TAB_NEXT constant'
        );

        $this->assertStringContainsString(
            'CommandLinePrompt::EVENT_TAB_PREV',
            $screenContent,
            'Screen.php should use CommandLinePrompt::EVENT_TAB_PREV constant'
        );

        // Verify the specific usage pattern in submit() method (str_contains context)
        $this->assertMatchesRegularExpression(
            '/str_contains\s*\(\s*\$command\s*,\s*CommandLinePrompt::EVENT_TAB_NEXT\s*\)/',
            $screenContent,
            'Screen.php::submit() should check str_contains($command, CommandLinePrompt::EVENT_TAB_NEXT)'
        );

        $this->assertMatchesRegularExpression(
            '/str_contains\s*\(\s*\$command\s*,\s*CommandLinePrompt::EVENT_TAB_PREV\s*\)/',
            $screenContent,
            'Screen.php::submit() should check str_contains($command, CommandLinePrompt::EVENT_TAB_PREV)'
        );
    }

    /**
     * Test that CommandLinePrompt internally emits its own constants via self::.
     *
     * Verifies that CommandLinePrompt.php uses self::EVENT_TAB_NEXT and
     * self::EVENT_TAB_PREV when emitting these events internally.
     */
    public function test_command_line_prompt_emits_own_constants(): void
    {
        $promptPath = __DIR__ . '/../../../../../src/Console/AiCommands/Lab/Prompts/CommandLinePrompt.php';
        $this->assertFileExists($promptPath, 'CommandLinePrompt.php must exist at expected path');

        $promptContent = file_get_contents($promptPath);
        $this->assertIsString($promptContent, 'Failed to read CommandLinePrompt.php');

        // Verify CommandLinePrompt uses self:: for internal emission
        $this->assertStringContainsString(
            'self::EVENT_TAB_NEXT',
            $promptContent,
            'CommandLinePrompt.php should use self::EVENT_TAB_NEXT when emitting internally'
        );

        $this->assertStringContainsString(
            'self::EVENT_TAB_PREV',
            $promptContent,
            'CommandLinePrompt.php should use self::EVENT_TAB_PREV when emitting internally'
        );
    }

    /**
     * Test that constants have correct visibility (public) via reflection.
     *
     * Uses ReflectionClass to verify EVENT_TAB_NEXT and EVENT_TAB_PREV are
     * public constants, allowing external access without errors.
     */
    public function test_constants_have_correct_visibility(): void
    {
        $reflection = new ReflectionClass(CommandLinePrompt::class);

        $tabNext = $reflection->getReflectionConstant('EVENT_TAB_NEXT');
        $tabPrev = $reflection->getReflectionConstant('EVENT_TAB_PREV');

        $this->assertNotFalse($tabNext, 'EVENT_TAB_NEXT constant must exist');
        $this->assertNotFalse($tabPrev, 'EVENT_TAB_PREV constant must exist');

        $this->assertTrue($tabNext->isPublic(), 'EVENT_TAB_NEXT must be public');
        $this->assertTrue($tabPrev->isPublic(), 'EVENT_TAB_PREV must be public');

        $this->assertFalse($tabNext->isPrivate(), 'EVENT_TAB_NEXT must not be private');
        $this->assertFalse($tabPrev->isPrivate(), 'EVENT_TAB_PREV must not be private');
    }

    /**
     * Test that constant values match expected event names.
     *
     * Validates that the constant values correspond to the event names
     * used in keyboard navigation (tab-next, tab-previous).
     */
    public function test_constant_values_match_event_names(): void
    {
        $this->assertSame('tab-next', CommandLinePrompt::EVENT_TAB_NEXT, 'EVENT_TAB_NEXT should be "tab-next"');
        $this->assertSame('tab-previous', CommandLinePrompt::EVENT_TAB_PREV, 'EVENT_TAB_PREV should be "tab-previous"');

        // Verify they are distinct values
        $this->assertNotSame(
            CommandLinePrompt::EVENT_TAB_NEXT,
            CommandLinePrompt::EVENT_TAB_PREV,
            'Tab event constants should have distinct values'
        );
    }

    /**
     * Test that Screen.php can use constants in str_contains() checks.
     *
     * Simulates the actual usage pattern in Screen.php::submit() to verify
     * the constants work correctly with str_contains() function.
     */
    public function test_constants_work_with_str_contains(): void
    {
        // Test str_contains with EVENT_TAB_NEXT
        $command = 'some-command-' . CommandLinePrompt::EVENT_TAB_NEXT;
        $this->assertTrue(
            str_contains($command, CommandLinePrompt::EVENT_TAB_NEXT),
            'str_contains() should find EVENT_TAB_NEXT constant value in command string'
        );

        // Test str_contains with EVENT_TAB_PREV
        $command = 'some-command-' . CommandLinePrompt::EVENT_TAB_PREV;
        $this->assertTrue(
            str_contains($command, CommandLinePrompt::EVENT_TAB_PREV),
            'str_contains() should find EVENT_TAB_PREV constant value in command string'
        );

        // Test that they don't cross-match
        $command = 'some-command-' . CommandLinePrompt::EVENT_TAB_NEXT;
        $this->assertFalse(
            str_contains($command, CommandLinePrompt::EVENT_TAB_PREV),
            'EVENT_TAB_PREV should not match EVENT_TAB_NEXT value'
        );
    }

    /**
     * Test that CommandLinePrompt defines no private tab event constants.
     *
     * Validates that there are no private EVENT_TAB_* constants that would
     * cause accessibility violations if used externally.
     */
    public function test_no_private_tab_event_constants(): void
    {
        $reflection = new ReflectionClass(CommandLinePrompt::class);

        // Get all constants
        $constants = $reflection->getConstants();

        // Filter to tab event constants
        $tabEventConstants = array_filter(
            $constants,
            fn ($name) => str_contains($name, 'EVENT_TAB'),
            ARRAY_FILTER_USE_KEY
        );

        $this->assertNotEmpty($tabEventConstants, 'At least one EVENT_TAB constant should exist');

        // Verify all are public
        foreach ($tabEventConstants as $name => $value) {
            $reflectionConstant = $reflection->getReflectionConstant($name);
            $this->assertTrue(
                $reflectionConstant->isPublic(),
                "Tab event constant {$name} must be public, not private"
            );
        }
    }
}