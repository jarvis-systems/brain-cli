<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console\Commands;

use BrainCLI\ServiceProvider;
use BrainCLI\Foundation\Application as LaravelApplication;
use BrainCLI\Support\Brain;
use BrainCLI\Tests\Support\CliOutputCapture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class McpCallCommandTest extends TestCase
{
    use CliOutputCapture;

    private LaravelApplication $laravel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->laravel = LaravelApplication::create();
        ServiceProvider::bootApplication($this->laravel);
    }

    public function test_mcp_call_error_hint_points_to_discovery(): void
    {
        $command = $this->laravel->make(\BrainCLI\Console\Commands\McpCallCommand::class);
        $command->setLaravel($this->laravel);
        $output = new BufferedOutput();
        
        // Use a known server but an unauthorized tool to trigger policy block hint
        $command->run(
            new ArrayInput([
                '--server' => 'vector-memory',
                '--tool' => 'unauthorized-tool',
                '--json' => true
            ]),
            $output
        );

        $data = json_decode($output->fetch(), true);
        
        $this->assertFalse($data['ok']);
        $this->assertStringContainsString('brain mcp:describe --server=vector-memory', $data['error']['hint']);
    }

    public function test_mcp_call_honors_kill_switch(): void
    {
        putenv('BRAIN_DISABLE_MCP=true');
        
        $command = $this->laravel->make(\BrainCLI\Console\Commands\McpCallCommand::class);
        $command->setLaravel($this->laravel);
        $output = new BufferedOutput();
        
        $command->run(
            new ArrayInput([
                '--server' => 'mock-echo',
                '--tool' => 'mock-echo',
                '--json' => true
            ]),
            $output
        );

        $data = json_decode($output->fetch(), true);
        $this->assertFalse($data['ok']);
        $this->assertEquals('MCP_DISABLED', $data['error']['code']);
        
        putenv('BRAIN_DISABLE_MCP');
    }
}
