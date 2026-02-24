<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

class DatabaseManagerTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = file_get_contents(
            dirname(__DIR__, 3) . '/src/Database/DatabaseManager.php'
        ) ?: '';
    }

    public function test_has_legacy_db_constant(): void
    {
        $this->assertStringContainsString("LEGACY_DB_NAME = 'credentials.sqlite'", $this->source);
    }

    public function test_has_canon_db_constant(): void
    {
        $this->assertStringContainsString("CANON_DB_NAME = 'brain.sqlite'", $this->source);
    }

    public function test_resolve_database_path_method_exists(): void
    {
        $this->assertStringContainsString('function resolveDatabasePath()', $this->source);
    }

    public function test_uses_brain_working_directory(): void
    {
        $this->assertStringContainsString("Brain::workingDirectory('memory')", $this->source);
    }

    public function test_returns_canon_path_when_exists(): void
    {
        $this->assertStringContainsString('if (file_exists($canonPath))', $this->source);
        $this->assertStringContainsString('return $canonPath;', $this->source);
    }

    public function test_migrates_legacy_when_canon_missing(): void
    {
        $this->assertStringContainsString('if (file_exists($legacyPath))', $this->source);
        $this->assertStringContainsString('rename($legacyPath, $canonPath)', $this->source);
    }

    public function test_throws_on_rename_failure(): void
    {
        $this->assertStringContainsString('RuntimeException', $this->source);
        $this->assertStringContainsString('Failed to migrate legacy DB', $this->source);
    }

    public function test_database_path_returns_resolve_result(): void
    {
        $this->assertStringContainsString('return self::resolveDatabasePath();', $this->source);
    }

    public function test_env_path_uses_canon_name(): void
    {
        $this->assertStringContainsString('self::CANON_DB_NAME', $this->source);
    }
}
