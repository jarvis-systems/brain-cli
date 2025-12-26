<?php

declare(strict_types=1);

namespace BrainCLI\Console\Services\Traits\Ai;

use Bfg\Dto\Dto;
use BrainCLI\Console\AiCommands\RunCommand;
use BrainCLI\Console\Services\MD;
use BrainCLI\Dto\ProcessOutput\Init;
use BrainCLI\Dto\ProcessOutput\Message;
use BrainCLI\Dto\ProcessOutput\Result;
use Illuminate\Console\Concerns\CallsCommands;
use React\Promise\PromiseInterface;

use function React\Async\async;

trait RunBridgeTrait
{
    use CallsCommands;

    /**
     * Initialization data from the AI process.
     *
     * @var \BrainCLI\Dto\ProcessOutput\Init|null
     */
    protected Init|null $init = null;

    /**
     * Message data from the AI process.
     *
     * @var \BrainCLI\Dto\ProcessOutput\Message|null
     */
    protected Message|null $message = null;

    /**
     * Result data from the AI process.
     *
     * @var \BrainCLI\Dto\ProcessOutput\Result|null
     */
    protected Result|null $result = null;

    /**
     * The promise for the AI response.
     *
     * @var PromiseInterface<Message|null>|null
     */
    protected PromiseInterface|null $promise = null;

    /**
     * The exit code of the AI process.
     *
     * @var int|null
     */
    protected int|null $exitCode = null;

    /**
     * Ask a question to the AI and get a response.
     *
     * @param  callable|array|string  $question
     * @return static
     */
    public function ask(callable|array|string $question): static
    {
        if (is_callable($question)) {
            $question = $question($this);
        }

        $question = is_array($question)
            ? MD::fromArray($question)
            : $question;

        $this->promise = async(function (string $question): Message|null {
            $arguments = [
                '--json' => true,
                '--ask' => $question,
                '--model' => $this->person->model->value,
                '--no-mcp' => $this->npMcp,
                '--yolo' => $this->yolo,
            ];

            if ($this->person->isNotEmpty('sessionId')) {
                $arguments['--resume'] = $this->person->get('sessionId');
            }

            if ($this->person->isNotEmpty('system')) {
                $arguments['--system'] = $this->person->get('system');
            }

            if ($defaultSystem = $this->person->defaultSystemPrompt()) {
                if (isset($arguments['--system'])) {
                    $arguments['--system'] .= PHP_EOL . PHP_EOL . $defaultSystem;
                } else {
                    $arguments['--system'] = $defaultSystem;
                }
            }

            if ($this->person->isNotEmpty('systemAppend')) {
                $arguments['--system-append'] = $this->person->get('systemAppend');
            }

            if ($this->schema) {
                $arguments['--schema'] = json_encode($this->schema);
            }

            $this->run($arguments);

            return $this->message;
        })($question);

        return $this;
    }

    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): static
    {
        if ($this->promise === null) {
            throw new \RuntimeException('No promise available. Did you call ask() first?');
        }

        $this->promise = $this->promise
            ->then($onFulfilled, $onRejected);

        return $this;
    }

    public function cancel(): static
    {
        if ($this->promise === null) {
            throw new \RuntimeException('No promise available to cancel. Did you call ask() first?');
        }

        $this->promise->cancel();

        return $this;
    }

    /**
     * Run the AI command with the given arguments.
     *
     * @param  array<string, mixed>  $arguments
     * @return int
     */
    protected function run(array $arguments = []): int
    {
        $this->init = null;
        $this->message = null;
        $this->result = null;

        return $this->exitCode
            = $this->callSilent(RunCommand::class, $arguments);
    }

    /**
     * Resolve the console command instance for the given command.
     *
     * @param  \Symfony\Component\Console\Command\Command|string  $command
     * @return RunCommand
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected function resolveCommand($command): RunCommand
    {
        $result = app($command, [
            'agent' => $this->person->agent,
        ]);
        if (! $result instanceof RunCommand) {
            throw new \RuntimeException('The resolved command is not an instance of RunCommand.');
        }
        $result->setAccumulateCallback(
            fn (Dto $dto) => $this->accumulate($dto)
        );
        return $result;
    }

    /**
     * Accumulate the DTO data from the AI process.
     *
     * @param  Dto  $dto
     * @return void
     */
    protected function accumulate(Dto $dto): void
    {
        if ($dto instanceof Init) {
            $this->init($dto);
        } elseif ($dto instanceof Message) {
            $this->message($dto);
        } elseif ($dto instanceof Result) {
            $this->result($dto);
        }
    }

    /**
     * Handle the initialization data from the AI process.
     *
     * @param  \BrainCLI\Dto\ProcessOutput\Init  $init
     * @return void
     */
    protected function init(Init $init): void
    {
        $this->init = $init;
        $this->person->set('sessionId', $init->sessionId);
    }

    /**
     * Handle the message data from the AI process.
     *
     * @param  \BrainCLI\Dto\ProcessOutput\Message  $message
     * @return void
     */
    protected function message(Message $message): void
    {
        $this->message = $message;
    }

    /**
     * Handle the result data from the AI process.
     *
     * @param  \BrainCLI\Dto\ProcessOutput\Result  $result
     * @return void
     */
    protected function result(Result $result): void
    {
        $this->result = $result;
    }
}
