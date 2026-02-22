<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * Verifies that all migrated call sites use Brain::debugException()
 * and no longer contain the inline debug pattern.
 */
class DebugMigrationParityTest extends TestCase
{
    private const INLINE_PATTERN = "if (Brain::isDebug()) {\n";
    private const INLINE_ERROR_LOG = "error_log('[brain-debug]";
    private const MIGRATED_CALL = 'Brain::debugException(';

    private function readSource(string $relativePath): string
    {
        $path = dirname(__DIR__, 3) . '/src/' . $relativePath;
        $this->assertFileExists($path, "Source file not found: {$relativePath}");

        return file_get_contents($path) ?: '';
    }

    public function test_command_bridge_abstract_uses_debug_exception(): void
    {
        $source = $this->readSource('Abstracts/CommandBridgeAbstract.php');

        $this->assertStringContainsString(self::MIGRATED_CALL, $source);
        $this->assertStringNotContainsString(self::INLINE_ERROR_LOG, $source);
    }

    public function test_ai_helpers_trait_uses_debug_exception(): void
    {
        $source = $this->readSource('Console/Services/Traits/Ai/HelpersTrait.php');

        $this->assertStringContainsString(self::MIGRATED_CALL, $source);
        $this->assertStringNotContainsString(self::INLINE_ERROR_LOG, $source);
    }

    public function test_custom_run_command_uses_debug_exception(): void
    {
        $source = $this->readSource('Console/AiCommands/CustomRunCommand.php');

        $this->assertStringContainsString(self::MIGRATED_CALL, $source);
        $this->assertStringNotContainsString(self::INLINE_ERROR_LOG, $source);
    }

    public function test_screen_uses_debug_exception(): void
    {
        $source = $this->readSource('Console/AiCommands/Lab/Screen.php');

        $this->assertStringContainsString(self::MIGRATED_CALL, $source);
        $this->assertStringNotContainsString(self::INLINE_ERROR_LOG, $source);
    }

    public function test_docs_command_uses_debug_exception(): void
    {
        $source = $this->readSource('Console/Commands/DocsCommand.php');

        $this->assertStringContainsString(self::MIGRATED_CALL, $source);
        $this->assertStringNotContainsString(self::INLINE_ERROR_LOG, $source);
    }

    public function test_service_provider_intentionally_keeps_static_is_debug(): void
    {
        $source = $this->readSource('ServiceProvider.php');

        $this->assertStringContainsString('static::isDebug()', $source);
        $this->assertStringNotContainsString(self::MIGRATED_CALL, $source);
    }

    public function test_core_has_debug_exception_method(): void
    {
        $source = $this->readSource('Core.php');

        $this->assertStringContainsString('public function debugException(', $source);
        $this->assertStringContainsString('\Throwable $e', $source);
        $this->assertStringContainsString("string \$prefix = 'brain-debug'", $source);
    }
}
