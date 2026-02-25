<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console\Commands;

use BrainCLI\ServiceProvider;
use BrainCLI\Foundation\Application as LaravelApplication;
use BrainCLI\Tests\Support\CliOutputCapture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class McpGuardrailsCommandTest extends TestCase
{
    use CliOutputCapture;

    private LaravelApplication $laravel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->laravel = LaravelApplication::create();
        ServiceProvider::bootApplication($this->laravel);
    }

    public function test_guardrails_returns_json(): void
    {
        $command = $this->laravel->make(\BrainCLI\Console\Commands\McpGuardrailsCommand::class);
        $command->setLaravel($this->laravel);
        $output = new BufferedOutput();
        
        $command->run(new ArrayInput(['--json' => true]), $output);

        $out = $output->fetch();
        $this->assertJson($out);
        
        $data = json_decode($out, true);
        $this->assertArrayHasKey('enabled', $data);
        $this->assertArrayHasKey('registry', $data);
        $this->assertArrayHasKey('external_tools_policy', $data);
        $this->assertArrayHasKey('tools_policy', $data);
    }

    public function test_guardrails_honors_kill_switch(): void
    {
        putenv('BRAIN_DISABLE_MCP=true');
        
        $command = $this->laravel->make(\BrainCLI\Console\Commands\McpGuardrailsCommand::class);
        $command->setLaravel($this->laravel);
        $output = new BufferedOutput();
        
        $command->run(new ArrayInput(['--json' => true]), $output);

        $data = json_decode($output->fetch(), true);
        $this->assertFalse($data['enabled']);
        
        putenv('BRAIN_DISABLE_MCP');
    }
}
