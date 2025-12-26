<?php

declare(strict_types=1);

namespace BrainCLI\Enums\Agent\Traits;

trait AgentableTrait
{
    abstract public function label(): string;
    abstract public function description(): string;
    abstract public function share(): int;

    public static function validateShare(): void
    {
        if (method_exists(self::class, 'cases')) {
            $totalShare = 0;
            foreach (self::cases() as $case) {
                $totalShare += $case->share();
            }
            if ($totalShare !== 100) {
                throw new \LogicException('The total share of all models must equal 100%. Current total: ' . $totalShare . '%.');
            }
        }
    }
}
