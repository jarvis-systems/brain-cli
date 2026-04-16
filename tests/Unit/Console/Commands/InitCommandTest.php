<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console\Commands;

use PHPUnit\Framework\TestCase;

class InitCommandTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Commands/InitCommand.php'
        ) ?: '';
    }

    public function test_command_signature_has_scaffold_option(): void
    {
        $this->assertStringContainsString("'init", $this->source);
        $this->assertStringContainsString('--scaffold', $this->source);
    }

    public function test_command_extends_illuminate_command(): void
    {
        $this->assertStringContainsString('extends Command', $this->source);
    }

    public function test_uses_self_dev_gate_trait(): void
    {
        $this->assertStringContainsString('use SelfDevGateTrait', $this->source);
    }

    public function test_requires_self_dev_for_scaffold(): void
    {
        $this->assertStringContainsString("option('scaffold')", $this->source);
        $this->assertStringContainsString('requireSelfDev()', $this->source);
    }

    public function test_scaffold_calls_make_mcp(): void
    {
        $this->assertStringContainsString("call('make:mcp'", $this->source);
    }

    public function test_outputs_guidance_when_no_scaffold(): void
    {
        $this->assertStringContainsString('Bootstrap complete', $this->source);
        $this->assertStringContainsString('brain init --scaffold', $this->source);
    }

    public function test_post_bootstrap_steps_run_only_after_successful_create_project(): void
    {
        $resultCheckPosition = strpos($this->source, 'if ($result !== OK) {');
        $envCopyPosition = strpos($this->source, "Creating .env file");
        $aiRenamePosition = strpos($this->source, "Creating .ai folder");

        $this->assertNotFalse($resultCheckPosition);
        $this->assertNotFalse($envCopyPosition);
        $this->assertNotFalse($aiRenamePosition);
        $this->assertLessThan($envCopyPosition, $resultCheckPosition);
        $this->assertLessThan($aiRenamePosition, $resultCheckPosition);
    }

    public function test_existing_project_ai_directory_is_merged_instead_of_blind_rename(): void
    {
        $this->assertStringContainsString('moveAiDirectory(', $this->source);
        $this->assertStringContainsString('if (! file_exists($target)) {', $this->source);
        $this->assertStringContainsString('if (is_file($targetPath)) {', $this->source);
        $this->assertStringContainsString('return $this->removeDirectory($source);', $this->source);
    }

    public function test_handle_returns_int(): void
    {
        $this->assertStringContainsString('public function handle(): int', $this->source);
    }
}
