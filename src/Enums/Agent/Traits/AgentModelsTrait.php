<?php

declare(strict_types=1);

namespace BrainCLI\Enums\Agent\Traits;

use RuntimeException;

trait AgentModelsTrait
{
    use AgentableTrait;

    /**
     * @return static
     */
    public function withEnv(array $env): object
    {
        return new class($env, $this)
        {
            public function __construct(
                public array $env,
                public \BackedEnum $model,
            ) {
            }

            public function __call(string $name, array $arguments)
            {
                return $this->model->$name(...$arguments);
            }

            public function __get(string $name)
            {
                return $this->model->$name;
            }
        };
    }

    /**
     * @return \BrainCLI\Enums\Agent
     */
    abstract public function agent(): \BrainCLI\Enums\Agent;

    /**
     * @return array<\BackedEnum|AgentModelsTrait>
     */
    abstract protected function rawFallback(): array;

    /**
     * @return array<\BackedEnum|AgentModelsTrait>
     */
    public function fallback(): array
    {
        $fallback = $this->rawFallback();
        $list = [];
        foreach ($fallback as $model) {
            if ($model->agent()->isEnabled()) {
                $list[] = $model;
                //dump([$this::class => $model::class]);

                if ($this->agent()->depended()) {
                    if (! $model->agent()->depended()) {
                        $list = array_merge($list, $model->fallback());
                    } elseif ($this::class === $model::class) {
                        $list = array_merge($list, $model->fallback());
                    }
                } else {
                    $list = array_merge($list, $model->fallback());
                }
            }
        }
        return array_unique($list, SORT_REGULAR);
    }

    /**
     * @return static
     */
    public static function bestModel(): static
    {
        $best = null;
        foreach (self::cases() as $case) {
            if ($best === null || $case->share() > $best->share()) {
                $best = $case;
            }
        }
        if ($best === null) {
            throw new RuntimeException('No models available to determine the best model.');
        }
        return $best;
    }
}
