<?php

declare(strict_types=1);

namespace BrainCLI\Enums\Agent;

use BrainCLI\Enums\Agent\Traits\AgentableTrait;
use BrainCLI\Support\Brain;

enum Position: string
{
    use AgentableTrait;

    case DIRECTOR = 'director';
    case LEAD = 'lead';
    case SENIOR = 'senior';
    case MID_LEVEL = 'mid-level';
    case JUNIOR = 'junior';
    case TRAINEE = 'trainee';

    public function label(): string
    {
        return match ($this) {
            self::DIRECTOR => 'Director',
            self::LEAD => 'Lead',
            self::SENIOR => 'Senior',
            self::MID_LEVEL => 'Mid-level',
            self::JUNIOR => 'Junior',
            self::TRAINEE => 'Trainee',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::DIRECTOR => 'Directors oversee multiple teams and projects, set strategic direction, and ensure alignment with organizational goals.',
            self::LEAD => 'Lead developers take on the most complex tasks, provide guidance to the team, and ensure the overall success of projects.',
            self::SENIOR => 'Senior developers have significant experience and are capable of handling complex tasks independently while mentoring junior team members.',
            self::MID_LEVEL => 'Mid-level developers have a solid understanding of development practices and can work on tasks with moderate complexity.',
            self::JUNIOR => 'Junior developers are typically newer to the field and work on simpler tasks while learning and gaining experience.',
            self::TRAINEE => 'Trainees are beginners who are in the process of learning the basics of development and gaining practical experience.',
        };
    }

    public function share(): int
    {
        return match ($this) {
            self::DIRECTOR => Brain::getEnv('DIRECTOR_SHARE', 20),
            self::LEAD => Brain::getEnv('LEAD_SHARE', 15),
            self::SENIOR => Brain::getEnv('SENIOR_SHARE', 10),
            self::MID_LEVEL => Brain::getEnv('MID_LEVEL_SHARE', 7),
            self::JUNIOR => 3,
            self::TRAINEE => 0,
        };
    }

    public static function detectPosition(int $share): Position
    {
        return match (true) {
            $share >= self::DIRECTOR->share() => self::DIRECTOR,
            $share >= self::LEAD->share() => self::LEAD,
            $share >= self::SENIOR->share() => self::SENIOR,
            $share >= self::MID_LEVEL->share() => self::MID_LEVEL,
            $share >= self::JUNIOR->share() => self::JUNIOR,
            default => self::TRAINEE,
        };
    }
}
