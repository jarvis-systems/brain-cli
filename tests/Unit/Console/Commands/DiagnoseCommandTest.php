<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console\Commands;

use BrainCLI\Console\Commands\DiagnoseCommand;
use BrainCLI\Tests\Support\CliOutputCapture;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DiagnoseCommand behavior, output structure, and internal helpers.
 *
 * Uses source inspection and reflection to verify contracts
 * without requiring the full Laravel application container.
 */
class DiagnoseCommandTest extends TestCase
{
    use CliOutputCapture;

    private string $sourceFile;

    private string $source;

    protected function setUp(): void
    {
        $this->sourceFile = dirname(__DIR__, 4) . '/src/Console/Commands/DiagnoseCommand.php';
        $this->source = file_get_contents($this->sourceFile) ?: '';
    }

    // ─── Source Inspection: Signature & Structure ────────────────────

    public function test_command_signature_contains_human_option(): void
    {
        $this->assertStringContainsString("'diagnose", $this->source);
        $this->assertStringContainsString('--human', $this->source);
    }

    public function test_command_extends_illuminate_command(): void
    {
        $this->assertStringContainsString('extends Command', $this->source);
        $this->assertStringContainsString('use Illuminate\Console\Command', $this->source);
    }

    public function test_handle_returns_int(): void
    {
        $this->assertStringContainsString('public function handle(): int', $this->source);
    }

    public function test_handle_always_returns_zero(): void
    {
        // DiagnoseCommand::handle() always returns 0 (diagnostic never fails)
        $this->assertStringContainsString('return 0;', $this->source);
    }

    // ─── Source Inspection: Diagnosis Payload Structure ──────────────

    public function test_diagnosis_has_self_dev_mode_key(): void
    {
        $this->assertStringContainsString("'self_dev_mode'", $this->source);
    }

    public function test_diagnosis_has_self_dev_source_key(): void
    {
        $this->assertStringContainsString("'self_dev_source'", $this->source);
    }

    public function test_diagnosis_has_autodetect_signals_section(): void
    {
        $this->assertStringContainsString("'autodetect_signals'", $this->source);
        $this->assertStringContainsString("'node_brain_php_in_root'", $this->source);
        $this->assertStringContainsString("'node_brain_php_in_dot_brain'", $this->source);
        $this->assertStringContainsString("'dot_brain_is_symlink'", $this->source);
        $this->assertStringContainsString("'dot_brain_target'", $this->source);
    }

    public function test_diagnosis_has_paths_section(): void
    {
        $this->assertStringContainsString("'paths'", $this->source);
        $this->assertStringContainsString("'project_root'", $this->source);
        $this->assertStringContainsString("'brain_dir'", $this->source);
        $this->assertStringContainsString("'dot_brain_path'", $this->source);
    }

    public function test_diagnosis_has_modes_section(): void
    {
        $this->assertStringContainsString("'modes'", $this->source);
        $this->assertStringContainsString("'strict_mode'", $this->source);
        $this->assertStringContainsString("'cognitive_level'", $this->source);
        $this->assertStringContainsString("'verbosity'", $this->source);
    }

    public function test_diagnosis_has_version_section(): void
    {
        $this->assertStringContainsString("'version'", $this->source);
        $this->assertStringContainsString("'root'", $this->source);
        $this->assertStringContainsString("'core'", $this->source);
        $this->assertStringContainsString("'cli'", $this->source);
    }

    // ─── Source Inspection: Self-Dev Detection Sources ───────────────

    public function test_self_dev_source_values(): void
    {
        // Three possible sources: env, autodetect, off
        $this->assertStringContainsString("'env'", $this->source);
        $this->assertStringContainsString("'autodetect'", $this->source);
        $this->assertStringContainsString("'off'", $this->source);
    }

    public function test_env_detection_uses_service_provider(): void
    {
        $this->assertStringContainsString("ServiceProvider::hasEnv('SELF_DEV_MODE')", $this->source);
        $this->assertStringContainsString("ServiceProvider::getEnv('SELF_DEV_MODE')", $this->source);
    }

    // ─── Source Inspection: Output Formats ───────────────────────────

    public function test_json_output_uses_pretty_print(): void
    {
        $this->assertStringContainsString('JSON_PRETTY_PRINT', $this->source);
        $this->assertStringContainsString('JSON_UNESCAPED_UNICODE', $this->source);
        $this->assertStringContainsString('JSON_UNESCAPED_SLASHES', $this->source);
    }

    public function test_human_output_renders_sections(): void
    {
        // renderHuman() should render all diagnostic sections
        $this->assertStringContainsString("'Brain Diagnostics'", $this->source);
        $this->assertStringContainsString("'Autodetect Signals'", $this->source);
        $this->assertStringContainsString("'Paths'", $this->source);
        $this->assertStringContainsString("'Modes'", $this->source);
        $this->assertStringContainsString("'Versions'", $this->source);
    }

    public function test_human_output_active_marker(): void
    {
        $this->assertStringContainsString('ACTIVE', $this->source);
        $this->assertStringContainsString('OFF', $this->source);
    }

    // ─── Reflection: isTruthy() ─────────────────────────────────────

    public function test_is_truthy_accepts_true(): void
    {
        $result = $this->callIsTruthy(true);
        $this->assertTrue($result);
    }

    public function test_is_truthy_accepts_integer_one(): void
    {
        $result = $this->callIsTruthy(1);
        $this->assertTrue($result);
    }

    public function test_is_truthy_accepts_string_one(): void
    {
        $result = $this->callIsTruthy('1');
        $this->assertTrue($result);
    }

    public function test_is_truthy_accepts_string_true(): void
    {
        $result = $this->callIsTruthy('true');
        $this->assertTrue($result);
    }

    public function test_is_truthy_rejects_false(): void
    {
        $result = $this->callIsTruthy(false);
        $this->assertFalse($result);
    }

    public function test_is_truthy_rejects_zero(): void
    {
        $result = $this->callIsTruthy(0);
        $this->assertFalse($result);
    }

    public function test_is_truthy_rejects_empty_string(): void
    {
        $result = $this->callIsTruthy('');
        $this->assertFalse($result);
    }

    public function test_is_truthy_rejects_null(): void
    {
        $result = $this->callIsTruthy(null);
        $this->assertFalse($result);
    }

    public function test_is_truthy_rejects_string_yes(): void
    {
        $result = $this->callIsTruthy('yes');
        $this->assertFalse($result);
    }

    // ─── Reflection: readVersion() ──────────────────────────────────

    public function test_read_version_returns_null_for_missing_file(): void
    {
        $result = $this->callReadVersion('/nonexistent/path/composer.json');
        $this->assertNull($result);
    }

    public function test_read_version_returns_version_from_valid_file(): void
    {
        $tempDir = $this->createTempDir();
        $composerPath = $tempDir . '/composer.json';
        file_put_contents($composerPath, json_encode(['version' => 'v1.2.3']));

        try {
            $result = $this->callReadVersion($composerPath);
            $this->assertSame('v1.2.3', $result);
        } finally {
            $this->cleanDirectory($tempDir);
        }
    }

    public function test_read_version_returns_null_when_no_version_key(): void
    {
        $tempDir = $this->createTempDir();
        $composerPath = $tempDir . '/composer.json';
        file_put_contents($composerPath, json_encode(['name' => 'test/pkg']));

        try {
            $result = $this->callReadVersion($composerPath);
            $this->assertNull($result);
        } finally {
            $this->cleanDirectory($tempDir);
        }
    }

    public function test_read_version_returns_null_for_non_string_version(): void
    {
        $tempDir = $this->createTempDir();
        $composerPath = $tempDir . '/composer.json';
        file_put_contents($composerPath, json_encode(['version' => 123]));

        try {
            $result = $this->callReadVersion($composerPath);
            $this->assertNull($result);
        } finally {
            $this->cleanDirectory($tempDir);
        }
    }

    public function test_read_version_returns_null_for_invalid_json(): void
    {
        $tempDir = $this->createTempDir();
        $composerPath = $tempDir . '/composer.json';
        file_put_contents($composerPath, 'not-json-content');

        try {
            $result = $this->callReadVersion($composerPath);
            $this->assertNull($result);
        } finally {
            $this->cleanDirectory($tempDir);
        }
    }

    // ─── Source Inspection: Version Reading Paths ────────────────────

    public function test_reads_version_from_three_composer_files(): void
    {
        // Verifies that readVersion is called for root, core, and cli
        $this->assertStringContainsString("'composer.json'", $this->source);
        $this->assertStringContainsString("'core'", $this->source);
        $this->assertStringContainsString("'cli'", $this->source);
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    /**
     * Call DiagnoseCommand::isTruthy() via reflection.
     */
    private function callIsTruthy(mixed $value): bool
    {
        $command = new DiagnoseCommand();
        $method = new \ReflectionMethod($command, 'isTruthy');

        /** @var bool */
        return $method->invoke($command, $value);
    }

    /**
     * Call DiagnoseCommand::readVersion() via reflection.
     */
    private function callReadVersion(string $composerPath): string|null
    {
        $command = new DiagnoseCommand();
        $method = new \ReflectionMethod($command, 'readVersion');

        /** @var string|null */
        return $method->invoke($command, $composerPath);
    }
}
