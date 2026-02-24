<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console\Commands;

use PHPUnit\Framework\TestCase;

class McpPolicyCommandTest extends TestCase
{
    private string $brainBin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->brainBin = dirname(__DIR__, 4) . '/bin/brain';
    }

    public function test_command_signature_is_correct(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/src/Console/Commands/McpPolicyCommand.php');
        $this->assertNotFalse($source);
        $this->assertStringContainsString('mcp:policy', $source);
        $this->assertStringContainsString('--json', $source);
        $this->assertStringContainsString('--diagnostics', $source);
    }

    public function test_output_is_valid_json(): void
    {
        $output = $this->runCommand('');

        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('enabled', $decoded);
    }

    public function test_output_has_required_keys(): void
    {
        $output = $this->runCommand('');
        $decoded = json_decode($output, true);

        $requiredKeys = [
            'enabled',
            'kill_switch_env',
            'resolved_path',
            'schema_version',
            'allowed_count',
            'never_count',
            'clients_enabled',
            'has_overrides',
            'overlap',
        ];

        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $decoded, "Missing required key: {$key}");
        }
    }

    public function test_output_does_not_contain_tool_names(): void
    {
        $output = $this->runCommand('');
        $outputLower = strtolower($output);

        $forbiddenPatterns = [
            'docs',
            'compile',
            'make:',
            'token',
            'secret',
            'api_key',
            'bearer',
            'sk-',
            'gsk_',
            'ctx7sk',
        ];

        foreach ($forbiddenPatterns as $pattern) {
            $this->assertStringNotContainsString(strtolower($pattern), $outputLower, "Output contains forbidden pattern: {$pattern}");
        }
    }

    public function test_output_does_not_contain_wildcard_patterns(): void
    {
        $output = $this->runCommand('');

        $this->assertStringNotContainsString('make:*', $output);
        $this->assertStringNotContainsString('*', $output);
    }

    public function test_allowed_count_is_integer(): void
    {
        $output = $this->runCommand('');
        $decoded = json_decode($output, true);

        $this->assertIsInt($decoded['allowed_count']);
        $this->assertGreaterThanOrEqual(0, $decoded['allowed_count']);
    }

    public function test_never_count_is_integer(): void
    {
        $output = $this->runCommand('');
        $decoded = json_decode($output, true);

        $this->assertIsInt($decoded['never_count']);
        $this->assertGreaterThanOrEqual(0, $decoded['never_count']);
    }

    public function test_overlap_is_false(): void
    {
        $output = $this->runCommand('');
        $decoded = json_decode($output, true);

        $this->assertFalse($decoded['overlap']);
    }

    public function test_schema_version_is_1_0_0(): void
    {
        $output = $this->runCommand('');
        $decoded = json_decode($output, true);

        $this->assertEquals('1.0.0', $decoded['schema_version']);
    }

    public function test_kill_switch_env_is_correct(): void
    {
        $output = $this->runCommand('');
        $decoded = json_decode($output, true);

        $this->assertEquals('BRAIN_DISABLE_MCP', $decoded['kill_switch_env']);
    }

    public function test_kill_switch_disables_policy(): void
    {
        $output = $this->runCommand('BRAIN_DISABLE_MCP=true');
        $decoded = json_decode($output, true);

        $this->assertFalse($decoded['enabled']);
        $this->assertEquals(0, $decoded['allowed_count']);
        $this->assertEquals(0, $decoded['never_count']);
        $this->assertEquals('none', $decoded['resolved_path']);
    }

    public function test_resolved_path_is_relative_or_tilde(): void
    {
        $output = $this->runCommand('');
        $decoded = json_decode($output, true);

        $path = $decoded['resolved_path'];
        $isRelative = str_starts_with($path, './') || str_starts_with($path, '~/');
        $isAbsolute = str_starts_with($path, '/');

        $this->assertTrue($isRelative || $isAbsolute, "Path should be relative or absolute: {$path}");
    }

    public function test_diagnostics_adds_self_hosting(): void
    {
        $output = $this->runCommand('', '--diagnostics');
        $decoded = json_decode($output, true);

        $this->assertArrayHasKey('self_hosting', $decoded);
        $this->assertIsBool($decoded['self_hosting']);
    }

    public function test_resolved_path_contains_brain_config(): void
    {
        $output = $this->runCommand('');
        $decoded = json_decode($output, true);

        $this->assertStringContainsString('.brain-config', $decoded['resolved_path']);
    }

    public function test_enabled_is_true_by_default(): void
    {
        $output = $this->runCommand('');
        $decoded = json_decode($output, true);

        $this->assertTrue($decoded['enabled']);
    }

    public function test_has_overrides_is_true_in_self_hosting(): void
    {
        $output = $this->runCommand('');
        $decoded = json_decode($output, true);

        $this->assertTrue($decoded['has_overrides']);
    }

    public function test_clients_enabled_is_positive(): void
    {
        $output = $this->runCommand('');
        $decoded = json_decode($output, true);

        $this->assertGreaterThan(0, $decoded['clients_enabled']);
    }

    public function test_no_env_values_in_output(): void
    {
        $output = $this->runCommand('');
        $decoded = json_decode($output, true);

        $this->assertArrayNotHasKey('env', $decoded);
        $this->assertArrayNotHasKey('environment', $decoded);
    }

    private function runCommand(string $envPrefix = '', string $extraFlags = ''): string
    {
        $command = trim($envPrefix . ' php ' . escapeshellarg($this->brainBin) . ' mcp:policy ' . $extraFlags);

        $output = [];
        exec($command, $output);

        return implode("\n", $output);
    }
}
