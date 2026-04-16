<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Services\Clients;

use BrainCLI\Abstracts\CommandBridgeAbstract;
use BrainCLI\Dto\Compile\CommandInfo;
use BrainCLI\Dto\Compile\Data;
use BrainCLI\Enums\CompiledData\Format;
use BrainCLI\Services\Clients\CodexClient;
use PHPUnit\Framework\TestCase;

class CodexClientPromptFormatTest extends TestCase
{
    public function test_codex_prompt_front_matter_uses_description_not_legacy_name(): void
    {
        $client = new class(new class extends CommandBridgeAbstract {
            protected $signature = 'test:bridge';

            protected function handleBridge(): int|array
            {
                return OK;
            }
        }) extends CodexClient {
            public function exposeCreateCommandContent(Data $command, Data $brain, CommandInfo $info): array|false|string
            {
                return $this->createCommandContent($command, $brain, $info);
            }
        };

        $command = Data::fromAssoc([
            'id' => 'validate-command',
            'file' => '.brain/node/Commands/Task/ValidateCommand.php',
            'class' => 'BrainNode\\Commands\\Task\\ValidateCommand',
            'meta' => [
                'id' => 'task:validate',
                'description' => 'Async validation of vector task with 3 parallel agents',
                'argument-hint' => 'task-id',
            ],
            'namespace' => 'BrainNode\\Commands\\Task',
            'namespaceType' => 'Commands\\Task',
            'classBasename' => 'ValidateCommand',
            'format' => Format::XML,
            'structure' => '<command><meta><id>task:validate</id></meta></command>',
        ]);

        $brain = Data::fromAssoc([
            'id' => 'brain',
            'file' => '.brain/node/Brain.php',
            'class' => 'BrainNode\\Brain',
            'meta' => [],
            'namespace' => 'BrainNode',
            'namespaceType' => null,
            'classBasename' => 'Brain',
            'format' => Format::XML,
            'structure' => '<system></system>',
        ]);

        $info = CommandInfo::fromAssoc([
            'filename' => 'validate',
            'insidePath' => 'task',
            'name' => 'task:validate',
            'description' => 'Async validation of vector task with 3 parallel agents',
            'meta' => $command->meta,
        ]);

        $result = $client->exposeCreateCommandContent($command, $brain, $info);

        $this->assertIsArray($result);
        $this->assertSame('task-validate.md', $result['file']);
        $this->assertStringContainsString('description: "Async validation of vector task with 3 parallel agents"', $result['content']);
        $this->assertStringContainsString('argument-hint: "task-id"', $result['content']);
        $this->assertStringNotContainsString("\nname:", $result['content']);
        $this->assertStringContainsString('<command><meta><id>task:validate</id></meta></command>', $result['content']);
    }
}
