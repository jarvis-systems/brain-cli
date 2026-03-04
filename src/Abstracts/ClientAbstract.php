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
use BrainCLI\Services\Mcp\ClientToolingRouter;
use BrainCLI\Services\ProcessFactory;

abstract class ClientAbstract implements ClientContract
{
    use PathsTrait;
    use ProcessTrait;
    use CompileTrait;
    use HelpersTrait;

    protected ClientToolingRouter $tooling;

    /**
     * Constructor of the ClientAbstract class.
     */
    public function __construct(
        protected CommandBridgeAbstract $command,
        ?ClientToolingRouter $tooling = null,
    ) {
        $this->tooling = $tooling ?? new ClientToolingRouter(new \BrainCLI\Services\Mcp\BrainMcpBridge());
    }

    /**
     * Search Brain documentation.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function searchDocs(array $arguments): array
    {
        return $this->tooling->docsSearch($arguments);
    }

    /**
     * Get Brain diagnostics.
     *
     * @return array<string, mixed>
     */
    public function getDiagnostics(): array
    {
        return $this->tooling->diagnose();
    }

    /**
     * List available masters.
     *
     * @return array<string, mixed>
     */
    public function listMasters(?string $agent = null): array
    {
        return $this->tooling->listMasters($agent);
    }

    /**
     * Check if MCP bridge mode is enabled.
     */
    public function isMcpBridgeEnabled(): bool
    {
        return \BrainCLI\Services\Mcp\BrainMcpBridge::isEnabled();
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

    /**
     * Extract tool_use blocks from process output.
     *
     * @return list<array{id: string|null, name: string, input: array|string}>|null
     */
    protected function processParseOutputToolUse(ProcessFactory $factory, array $json): array|null
    {
        return null;
    }
}
