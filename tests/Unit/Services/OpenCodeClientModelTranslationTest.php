<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Services;

use BrainCLI\Enums\Agent\Models\OpenCodeModels;
use BrainCLI\Exceptions\InvalidModelIdException;
use BrainCLI\Services\Clients\OpenCodeClient;
use BrainCLI\Support\Brain;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OpenCodeClientModelTranslationTest extends TestCase
{
    private object $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = $this->createClient();
    }

    private function createClient(): object
    {
        $command = $this->createMock(\BrainCLI\Abstracts\CommandBridgeAbstract::class);
        return new class($command) extends OpenCodeClient {
            public function __construct($command)
            {
                parent::__construct($command);
            }

            public function testTranslateModelForClient(?string $model): ?string
            {
                return $this->translateModelForClient($model);
            }
        };
    }

    #[Test]
    #[DataProvider('claudeAliasProvider')]
    public function it_translates_claude_aliases_to_opencode_model_ids(string $alias, string $expectedModelId): void
    {
        $result = $this->client->testTranslateModelForClient($alias);

        $this->assertSame($expectedModelId, $result);
    }

    public static function claudeAliasProvider(): array
    {
        return [
            'sonnet alias' => ['sonnet', 'anthropic/claude-sonnet-4-5'],
            'haiku alias' => ['haiku', 'anthropic/claude-haiku-4-5'],
            'opus alias' => ['opus', 'anthropic/claude-opus-4-5'],
        ];
    }

    #[Test]
    #[DataProvider('fullModelIdProvider')]
    public function it_passes_through_full_model_ids_unchanged(string $fullModelId): void
    {
        $result = $this->client->testTranslateModelForClient($fullModelId);

        $this->assertSame($fullModelId, $result);
    }

    public static function fullModelIdProvider(): array
    {
        return [
            'anthropic sonnet' => ['anthropic/claude-sonnet-4-5'],
            'anthropic haiku' => ['anthropic/claude-haiku-4-5'],
            'opencode glm-4.7-free' => ['opencode/glm-4.7-free'],
            'zai glm-4.7' => ['zai-coding-plan/glm-4.7'],
        ];
    }

    #[Test]
    public function it_returns_null_for_null_input(): void
    {
        $result = $this->client->testTranslateModelForClient(null);

        $this->assertNull($result);
    }

    #[Test]
    public function it_returns_null_for_empty_string(): void
    {
        $result = $this->client->testTranslateModelForClient('');

        $this->assertNull($result);
    }

    #[Test]
    #[DataProvider('nativeOpenCodeAliasProvider')]
    public function it_translates_native_opencode_aliases(string $alias, string $expectedModelId): void
    {
        $result = $this->client->testTranslateModelForClient($alias);

        $this->assertSame($expectedModelId, $result);
    }

    public static function nativeOpenCodeAliasProvider(): array
    {
        return [
            'claude-sonnet alias' => ['claude-sonnet', 'anthropic/claude-sonnet-4-5'],
            'claude-haiku alias' => ['claude-haiku', 'anthropic/claude-haiku-4-5'],
            'claude-opus alias' => ['claude-opus', 'anthropic/claude-opus-4-5'],
            'glm-4.7-free alias' => ['glm-4.7-free', 'opencode/glm-4.7-free'],
        ];
    }

    #[Test]
    public function it_throws_for_unknown_bare_alias(): void
    {
        $this->expectException(InvalidModelIdException::class);
        $this->expectExceptionMessage('Invalid OpenCode model ID: "unknown-model-alias"');

        $this->client->testTranslateModelForClient('unknown-model-alias');
    }

    #[Test]
    #[DataProvider('unknownBareAliasProvider')]
    public function it_throws_for_various_unknown_bare_aliases(string $alias): void
    {
        $this->expectException(InvalidModelIdException::class);

        $this->client->testTranslateModelForClient($alias);
    }

    public static function unknownBareAliasProvider(): array
    {
        return [
            'gpt-4o' => ['gpt-4o'],
            'llama' => ['llama'],
            'mistral' => ['mistral'],
            'gemini-pro' => ['gemini-pro'],
            'unknown-ai-model' => ['unknown-ai-model'],
        ];
    }

    #[Test]
    public function exception_message_contains_helpful_context(): void
    {
        try {
            $this->client->testTranslateModelForClient('gpt-4o');
            $this->fail('Expected InvalidModelIdException was not thrown');
        } catch (InvalidModelIdException $e) {
            $this->assertStringContainsString('gpt-4o', $e->getMessage());
            $this->assertStringContainsString('provider/model format', $e->getMessage());
            $this->assertStringContainsString('known alias', $e->getMessage());
        }
    }
}
