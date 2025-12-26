<?php

declare(strict_types=1);

namespace BrainCLI\Abstracts\Traits\Client;

use BrainCLI\Dto\Process\Payload;
use BrainCLI\Dto\ProcessOutput\Init;
use BrainCLI\Dto\ProcessOutput\Message;
use BrainCLI\Dto\ProcessOutput\Result;
use BrainCLI\Enums\Process\Type;
use BrainCLI\Services\ProcessFactory;

trait ProcessTrait
{
    /**
     * Create a process factory.
     */
    public function process(Type $type): ProcessFactory
    {
        return new ProcessFactory(
            type: Type::RUN,
            compiler: $this,
            payload: $this->processPayload(Payload::fromEmpty()),
            command: $this->command,
        );
    }

    /**
     * Modify the process payload before running.
     */
    public function processRunCallback(ProcessFactory $factory): void
    {
        //
    }

    /**
     * Handle hosted process callback.
     */
    public function processHostedCallback(ProcessFactory $factory): void
    {
        //
    }

    /**
     * Handle process exit.
     */
    public function processExitCallback(ProcessFactory $factory, int $exitCode): void
    {
        $this->restoreTemporalFiles();
    }

    /**
     * Parse process output into DTOs.
     *
     * @return array<\Bfg\Dto\Dto>
     */
    public function processParseOutput(ProcessFactory $factory, string $output): array
    {
        $json = json_decode($output, true);
        $dto = [];
        if (is_array($json)) {

            $result = $this->processParseOutputResult($factory, $json);

            if ($data = $this->processParseOutputInit($factory, $json)) {
                $dto[] = Init::fromAssoc([
                    ...$data,
                    'processType' => $factory->type,
                    'agent' => $this->agent(),
                ]);
            }
            if ($data = $this->processParseOutputMessage($factory, $json)) {
                if (isset($data['content']) && $data['content'] && $factory->reflection->isUsed('schema')) {
                    $data['content'] = $this->extractJson($data['content']);
                }
                $dto[] = Message::fromAssoc([
                    ...$data,
                    'agent' => $this->agent(),
                ]);
            }
            if ($result) {
                $dto[] = Result::fromAssoc([
                    ...$result,
                    'agent' => $this->agent(),
                ]);
            }
        }
        return $dto;
    }
}
