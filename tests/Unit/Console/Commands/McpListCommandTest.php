<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console\Commands;

use PHPUnit\Framework\TestCase;

class McpListCommandTest extends TestCase
{
    private string $brainBin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->brainBin = dirname(__DIR__, 4) . '/bin/brain';
    }

    public function test_command_signature_is_correct(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/src/Console/Commands/McpListCommand.php');
        $this->assertNotFalse($source);
        $this->assertStringContainsString('mcp:list', $source);
        $this->assertStringContainsString('--json', $source);
        $this->assertStringContainsString('--pretty', $source);
        $this->assertStringContainsString('--scan', $source);
    }

    public function test_output_is_valid_json(): void
    {
        $output = $this->runCommand('');
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('schema_version', $decoded);
    }

    public function test_output_has_required_keys(): void
    {
        $output = $this->runCommand('');
        $decoded = json_decode($output, true);

        $requiredKeys = [
            'enabled',
            'kill_switch_env',
            'resolved_registry_path',
            'servers',
            'summary',
            'schema_version',
        ];

        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $decoded, "Missing required key: {$key}");
        }
    }

    public function test_json_by_default_is_compact(): void
    {
        $output = $this->runCommand('');
        $this->assertStringNotContainsString("\n", trim($output));
    }

    public function test_pretty_json_is_formatted(): void
    {
        $output = $this->runCommand('', '--pretty');
        $this->assertStringContainsString("\n", trim($output));
    }

    public function test_kill_switch_disables_list(): void
    {
        $output = $this->runCommand('BRAIN_DISABLE_MCP=true');
        $decoded = json_decode($output, true);

        $this->assertFalse($decoded['enabled']);
        $this->assertEmpty($decoded['servers']);
        $this->assertEquals(0, $decoded['summary']['total']);
    }

    public function test_servers_are_sorted_by_id(): void
    {
        $output = $this->runCommand('');
        $decoded = json_decode($output, true);

        if (! empty($decoded['servers'])) {
            $ids = array_column($decoded['servers'], 'id');
            $sortedIds = $ids;
            sort($sortedIds);

            $this->assertEquals($sortedIds, $ids, "Servers should be sorted by ID ASC");
        }
    }

    public function test_scan_mode_works(): void
    {
        $output = $this->runCommand('', '--scan');
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded['servers']);
        // Scan mode shouldn't have registry path
        $this->assertEquals('none', $decoded['resolved_registry_path']);
    }

    public function test_fails_when_missing_registry_in_custom_dir(): void
    {
        $testDir = sys_get_temp_dir() . '/brain-mcp-no-reg-' . uniqid();
        $nodeDir = $testDir . '/.brain/node';
        mkdir($nodeDir, 0755, true);
        
        // Create dummy Brain.php to satisfy project root resolution
        file_put_contents($nodeDir . '/Brain.php', '<?php namespace BrainNode; class Brain {}');
        
        $currentDir = getcwd();
        chdir($testDir);
        try {
            $output = $this->runCommand('');
            $decoded = json_decode($output, true);
            
            $this->assertEquals('error', $decoded['status']);
            $this->assertEquals('MCP_REGISTRY_MISSING', $decoded['error']['code']);
        } finally {
            chdir($currentDir);
        }
    }

    private function runCommand(string $envPrefix = '', string $extraFlags = ''): string
    {
        $command = trim($envPrefix . ' php ' . escapeshellarg($this->brainBin) . ' mcp:list ' . $extraFlags);

        $output = [];
        exec($command, $output);

        return implode("\n", $output);
    }
}
