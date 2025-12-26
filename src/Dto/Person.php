<?php

declare(strict_types=1);

namespace BrainCLI\Dto;

use BackedEnum;
use Bfg\Dto\Dto;
use BrainCLI\Console\Services\MD;
use BrainCLI\Enums\Agent;
use BrainCLI\Enums\Agent\Traits\AgentableTrait;
use BrainCLI\Enums\Agent\Traits\AgentModelsTrait;

class Person extends Dto
{
    use AgentableTrait;

    /**
     * @param BackedEnum|AgentModelsTrait $model
     */
    public function __construct(
        public Agent $agent,
        public BackedEnum $model,
        protected string|null $name = null,
        protected string|null $sessionId = null,
        protected string|null $system = null,
        protected string|null $systemAppend = null,
        protected string|null $identityDirection = null,
        protected string|null $rules = null,
        protected string|null $tasks = null,
        protected string|null $briefly = null,
    ) {
        //
    }

    /**
     * Get the unique ID of the prompt.
     */
    public function id(): string
    {
        return md5(implode('|', [
            $this->agent->depended()?->value ?: $this->agent->value,
            $this->sessionId,
            $this->name,
        ]));
    }

    /**
     * Set the name for the AI.
     */
    public function name(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Set the specific session ID for the AI.
     */
    public function sessionId(string $sessionId): static
    {
        $this->sessionId = $sessionId;
        return $this;
    }

    public function identityDirection(string $direction): static
    {
        $this->identityDirection = MD::fromArray([
            'You Identity' => [
                'name' => $this->name ?: $this->agent->label(),
                'direction' => $direction,
                'instructions' => 'You must clearly follow this direction of identification.',
            ],
        ]);
        return $this;
    }

    public function tasks(array $tasks): static
    {
        $this->tasks = MD::fromArray([
            'Tasks' => $tasks,
            'Instructions' => 'You must clearly follow these tasks.',
        ]);
        return $this;
    }

    public function rules(array $rules): static
    {
        $this->rules = MD::fromArray([
            'Iron Rules' => $rules,
            'Instructions' => 'You must strictly follow these iron rules.',
        ]);
        return $this;
    }

    public function briefly(): static
    {
        $this->briefly = MD::fromArray([
            'Answer Briefly' => 'Answer briefly, very, very briefly! Only to the point!',
        ]);
        return $this;
    }

    /**
     * Set the system prompt for the AI.
     */
    public function system(callable|array|string $system, bool $append = false): static
    {
        if (is_callable($system)) {
            $system = $system($this);
        }

        $system = is_array($system)
            ? MD::fromArray($system)
            : $system;

        if (! $append) {
            $this->system = $system;
        } else {
            if ($this->system) {
                $this->system .= PHP_EOL . PHP_EOL . $system;
            } else {
                if ($this->systemAppend) {
                    $this->systemAppend .= PHP_EOL . PHP_EOL . $system;
                } else {
                    $this->systemAppend = $system;
                }
            }
        }
        return $this;
    }

    public function label(): string
    {
        return sprintf(
            "%s(%s): %s",
            $this->position()->label(),
            $this->agent->label(),
            $this->model->label()
        );
    }

    public function description(): string
    {
        return $this->model->description();
    }

    public function share(): int
    {
        $agentShare = $this->agent->share(); // Is a share % from 100% (from all agents)
        $modelShare = $this->model->share(); // Is a share % from 100% (from agent share)
        return (int) round($agentShare * ($modelShare / 100));
    }

    public function position(): Agent\Position
    {
        return Agent\Position::detectPosition(
            $this->share()
        );
    }

    public function defaultSystemPrompt(): string|null
    {
        $md = null;

        $addMd = function (string $content) use (&$md) {
            if ($md && str_contains($md, PHP_EOL)) {
                $md .= PHP_EOL . PHP_EOL . $content;
            } else {
                $md = $content;
            }
        };

        if ($this->isNotEmpty('identityDirection')) {
            $addMd($this->get('identityDirection'));
        }

        if ($this->isNotEmpty('rules')) {
            $addMd($this->get('rules'));
        }

        if ($this->isNotEmpty('tasks')) {
            $addMd($this->get('tasks'));
        }

        if ($this->isNotEmpty('briefly')) {
            $addMd($this->get('briefly'));
        }

        if ($meta = $this->getMeta()) {
            $addMd(MD::fromArray([
                'Meta Information' => $meta,
            ]));
        }

        return $md;
    }
}
