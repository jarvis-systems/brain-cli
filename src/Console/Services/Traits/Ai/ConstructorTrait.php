<?php

declare(strict_types=1);

namespace BrainCLI\Console\Services\Traits\Ai;

use BackedEnum;
use BrainCLI\Dto\Person;
use BrainCLI\Enums\Agent;
use http\Exception\RuntimeException;

trait ConstructorTrait
{
    /**
     * Create a new instance of the class.
     *
     * @param  mixed  ...$args
     * @return static
     */
    public static function create(...$args): static
    {
        if (is_assoc($args)) {
            if (! isset($args['person']) || ! $args['person'] instanceof Person) {

                if (! isset($args['agent']) || ! $args['agent'] instanceof Agent) {
                    throw new RuntimeException(
                        'The "agent" parameter is required and must be an instance of Agent enum.'
                    );
                }
                if (! isset($args['model']) || ! $args['model'] instanceof BackedEnum) {
                    $args['model'] = $args['agent']->bestModel();
                }

                $args['person'] = new Person(
                    agent: $args['agent'],
                    model: $args['model'],
                );
                unset($args['agent'], $args['model']);
            }
        }
        return new static(...$args);
    }

    /**
     * Create a new instance from a Person DTO.
     *
     * @param  Person  $person
     * @return static
     */
    public static function person(Person $person): static
    {
        return static::create(
            person: $person
        );
    }
}
