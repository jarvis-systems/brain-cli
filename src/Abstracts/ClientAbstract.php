<?php

declare(strict_types=1);

namespace BrainCLI\Abstracts;

use BrainCLI\Abstracts\Contracts\Client\ClientContract;
use BrainCLI\Abstracts\Traits\Client\CompileTrait;
use BrainCLI\Abstracts\Traits\Client\HelpersTrait;
use BrainCLI\Abstracts\Traits\Client\PathsTrait;
use BrainCLI\Abstracts\Traits\Client\ProcessTrait;
use BrainCLI\Dto\Compile\CommandInfo;
use BrainCLI\Dto\Compile\Data;
use BrainCLI\Dto\Process\Payload;
use BrainCLI\Services\ProcessFactory;

abstract class ClientAbstract implements ClientContract
{
    use PathsTrait;
    use ProcessTrait;
    use CompileTrait;
    use HelpersTrait;

    /**
     * Constructor of the ClientAbstract class.
     */
    public function __construct(
        protected CommandBridgeAbstract $command,
    ) {
        //
    }

    /**
     * @return non-empty-string|array{file: non-empty-string, content: non-empty-string}|false
     */
    abstract protected function createCommandContent(Data $command, Data $brain, CommandInfo $info): string|array|false;

    /**
     * @return array{sessionId: non-empty-string}|null
     */
    abstract protected function processParseOutputInit(ProcessFactory $factory, array $json): array|null;

    /**
     * Process payload creation
     */
    abstract protected function processPayload(Payload $payload): Payload;

    /**
     * @return array{id: non-empty-string, content: non-empty-string}|null
     */
    abstract protected function processParseOutputMessage(ProcessFactory $factory, array $json): array|null;

    /**
     * @return array{inputTokens: int, outputTokens: int}|null
     */
    abstract protected function processParseOutputResult(ProcessFactory $factory, array $json): array|null;
}
