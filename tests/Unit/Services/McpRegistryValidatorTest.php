<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Services;

use BrainCLI\ServiceProvider;
use BrainCLI\Foundation\Application as LaravelApplication;
use BrainCLI\Services\McpRegistryValidator;
use BrainCore\Contracts\McpRegistry\ResolvedRegistry;
use BrainCore\Architectures\McpArchitecture;
use BrainCore\Attributes\Meta;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class McpRegistryValidatorTest extends TestCase
{
    private McpRegistryValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        
        $laravel = LaravelApplication::create();
        ServiceProvider::bootApplication($laravel);
        
        $this->validator = new McpRegistryValidator();
    }

    public function test_valid_registry_passes(): void
    {
        $registry = new ResolvedRegistry('1.0.0', [
            [
                'id' => 'vector-memory',
                'class' => \BrainNode\Mcp\VectorMemoryMcp::class,
                'enabled' => true
            ]
        ]);

        $this->validator->validate($registry);
        $this->assertTrue(true); // Did not throw
    }

    public function test_disabled_servers_are_skipped(): void
    {
        $registry = new ResolvedRegistry('1.0.0', [
            [
                'id' => 'non-existent',
                'class' => 'NonExistentClass',
                'enabled' => false
            ]
        ]);

        $this->validator->validate($registry);
        $this->assertTrue(true); // Did not throw
    }

    public function test_missing_class_throws_structured_error(): void
    {
        $registry = new ResolvedRegistry('1.0.0', [
            [
                'id' => 'missing',
                'class' => 'BrainNode\\Mcp\\MissingClass',
                'enabled' => true
            ]
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('code=MCP_REGISTRY_INVALID reason=class_missing');

        $this->validator->validate($registry);
    }

    public function test_bad_interface_throws_structured_error(): void
    {
        $registry = new ResolvedRegistry('1.0.0', [
            [
                'id' => 'bad-interface',
                'class' => \BrainCLI\Core::class, // Does not extend McpArchitecture
                'enabled' => true
            ]
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('code=MCP_REGISTRY_INVALID reason=bad_interface');

        $this->validator->validate($registry);
    }

    public function test_id_mismatch_throws_structured_error(): void
    {
        $registry = new ResolvedRegistry('1.0.0', [
            [
                'id' => 'mismatch-id',
                'class' => \BrainNode\Mcp\VectorMemoryMcp::class, // Actually has id 'vector-memory'
                'enabled' => true
            ]
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('code=MCP_REGISTRY_INVALID reason=id_mismatch');

        $this->validator->validate($registry);
    }
}
