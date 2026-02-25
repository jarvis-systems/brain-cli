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

class McpDescribeCommandTest extends TestCase
{
    private LaravelApplication $laravel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->laravel = LaravelApplication::create();
        ServiceProvider::bootApplication($this->laravel);
    }

    public function test_mcp_describe_missing_server_fails(): void
    {
        $command = $this->laravel->make(\BrainCLI\Console\Commands\McpDescribeCommand::class);
        $command->setLaravel($this->laravel);
        $output = new BufferedOutput();
        
        $command->run(
            new ArrayInput([]),
            $output
        );

        $out = $output->fetch();
        $data = json_decode($out, true);
        $this->assertEquals('MISSING_ARGUMENT', $data['error']['code']);
    }

    public function test_mcp_describe_unknown_server_fails(): void
    {
        $command = $this->laravel->make(\BrainCLI\Console\Commands\McpDescribeCommand::class);
        $command->setLaravel($this->laravel);
        $output = new BufferedOutput();
        
        $command->run(
            new ArrayInput(['--server' => 'unknown-server']),
            $output
        );

        $out = $output->fetch();
        $data = json_decode($out, true);
        $this->assertEquals('MCP_SERVER_NOT_FOUND', $data['error']['code']);
    }

    public function test_mcp_describe_honors_kill_switch(): void
    {
        putenv('BRAIN_DISABLE_MCP=true');
        
        $command = $this->laravel->make(\BrainCLI\Console\Commands\McpDescribeCommand::class);
        $command->setLaravel($this->laravel);
        $output = new BufferedOutput();
        
        $command->run(
            new ArrayInput(['--server' => 'vector-task']),
            $output
        );

        $out = $output->fetch();
        $data = json_decode($out, true);
        $this->assertEquals('MCP_DISABLED', $data['error']['code']);
        
        putenv('BRAIN_DISABLE_MCP');
    }
}
