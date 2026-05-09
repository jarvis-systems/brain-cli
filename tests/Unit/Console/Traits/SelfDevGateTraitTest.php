<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Console\Traits;

use BrainCLI\Console\Traits\SelfDevGateTrait;
use BrainCLI\Services\SelfDev\SelfDevResolver;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class SelfDevGateTraitTest extends TestCase
{
    private string $traitSource;

    protected function setUp(): void
    {
        $this->traitSource = file_get_contents(
            dirname(__DIR__, 4) . '/src/Console/Traits/SelfDevGateTrait.php'
        ) ?: '';
    }

    public function test_trait_has_require_self_dev_method(): void
    {
        $this->assertStringContainsString('function requireSelfDev()', $this->traitSource);
    }

    public function test_trait_returns_true_when_enabled(): void
    {
        $this->assertStringContainsString('return true;', $this->traitSource);
    }

    public function test_trait_returns_false_when_disabled(): void
    {
        $this->assertStringContainsString('return false;', $this->traitSource);
    }

    public function test_trait_outputs_error_message(): void
    {
        $this->assertStringContainsString('Self-hosting mode required', $this->traitSource);
    }

    public function test_trait_outputs_guidance_message(): void
    {
        $this->assertStringContainsString('legacy SELF_DEV_MODE=true', $this->traitSource);
        $this->assertStringContainsString('SELF_DEV_MODE=true', $this->traitSource);
    }

    public function test_trait_uses_self_dev_resolver(): void
    {
        $this->assertStringContainsString('SelfDevResolver::make()', $this->traitSource);
    }

    public function test_trait_calls_is_enabled(): void
    {
        $this->assertStringContainsString('isEnabled()', $this->traitSource);
    }
}
