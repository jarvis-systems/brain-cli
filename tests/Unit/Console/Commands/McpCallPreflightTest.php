<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console\Commands;

use BrainCLI\ServiceProvider;
use BrainCLI\Foundation\Application as LaravelApplication;
use BrainCLI\Tests\Support\CliOutputCapture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class McpCallPreflightTest extends TestCase
{
    use CliOutputCapture;

    private LaravelApplication $laravel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->laravel = LaravelApplication::create();
        ServiceProvider::bootApplication($this->laravel);
    }

    public function test_invalid_json_input_fails_fast(): void
    {
        $command = $this->laravel->make(\BrainCLI\Console\Commands\McpCallCommand::class);
        $command->setLaravel($this->laravel);
        $output = new BufferedOutput();
        
        $command->run(
            new ArrayInput([
                '--server' => 'mock-echo',
                '--tool' => 'mock-echo',
                '--input' => '{bad json}',
                '--json' => true
            ]),
            $output
        );

        $data = json_decode($output->fetch(), true);
        $this->assertEquals('MCP_CALL_INVALID_INPUT', $data['error']['code']);
        $this->assertEquals('invalid_json', $data['error']['reason']);
    }

    public function test_trace_mode_adds_metadata(): void
    {
        $command = $this->laravel->make(\BrainCLI\Console\Commands\McpCallCommand::class);
        $command->setLaravel($this->laravel);
        $output = new BufferedOutput();
        
        $command->run(
            new ArrayInput([
                '--server' => 'mock-echo',
                '--tool' => 'mock-echo',
                '--input' => '{"text":"hello"}',
                '--json' => true,
                '--trace' => true
            ]),
            $output
        );

        $data = json_decode($output->fetch(), true);
        $this->assertTrue($data['ok']);
        $this->assertArrayHasKey('request_id', $data);
        $this->assertArrayHasKey('redactions_applied', $data);
        $this->assertEquals(16, strlen($data['request_id']));
    }
}
