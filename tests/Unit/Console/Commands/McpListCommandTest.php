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
            'schema_version',
            'status',
            'servers',
            'summary',
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

    public function test_json_flag_alias_works(): void
    {
        $defaultOutput = $this->runCommand('');
        $jsonOutput = $this->runCommand('', '--json');

        $this->assertEquals($defaultOutput, $jsonOutput);
    }

    public function test_kill_switch_disables_list(): void
    {
        $output = $this->runCommand('BRAIN_DISABLE_MCP=true');
        $decoded = json_decode($output, true);

        $this->assertEquals('disabled', $decoded['status']);
        $this->assertEmpty($decoded['servers']);
        $this->assertEquals(0, $decoded['summary']['server_count']);
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

    public function test_server_count_matches_servers_list(): void
    {
        $output = $this->runCommand('');
        $decoded = json_decode($output, true);

        $this->assertCount($decoded['summary']['server_count'], $decoded['servers']);
    }

    public function test_status_is_ready_by_default(): void
    {
        $output = $this->runCommand('');
        $decoded = json_decode($output, true);

        $this->assertEquals('ready', $decoded['status']);
    }

    public function test_fails_when_mcp_class_missing_id_attribute(): void
    {
        $testDir = sys_get_temp_dir() . '/brain-mcp-test-' . uniqid();
        $mcpDir = $testDir . '/.brain/node/Mcp';
        mkdir($mcpDir, 0755, true);
        
        // Create dummy Brain.php to satisfy project root resolution
        file_put_contents($testDir . '/.brain/node/Brain.php', '<?php namespace BrainNode; class Brain {}');
        
        // Create invalid MCP class
        $invalidMcp = <<<'PHP'
<?php
namespace BrainNode\Mcp;
use BrainCore\Architectures\McpArchitecture;
class InvalidMcp extends McpArchitecture {
    protected static function defaultCommand(): string { return 'test'; }
    protected static function defaultArgs(): array { return []; }
}
PHP;
        file_put_contents($mcpDir . '/InvalidMcp.php', $invalidMcp);

        // Run command from testDir
        $currentDir = getcwd();
        chdir($testDir);
        try {
            $output = $this->runCommand('');
            $decoded = json_decode($output, true);
            
            $this->assertEquals('error', $decoded['status']);
            $this->assertEquals('DISCOVERY_FAILED', $decoded['error']['code']);
            $this->assertStringContainsString('missing #[Meta(\'id\', ...)] attribute', $decoded['error']['message']);
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
