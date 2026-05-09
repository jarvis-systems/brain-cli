<?php

declare(strict_types=1);

namespace BrainCLI\Console\Traits;

use BrainCLI\Services\SelfDev\SelfDevResolver;

trait SelfDevGateTrait
{
    protected function requireSelfDev(): bool
    {
        $resolver = SelfDevResolver::make();

        if ($resolver->isEnabled()) {
            return true;
        }

        $this->components->error('Self-hosting mode required for scaffolding.');
        $this->components->info('Use this in the Brain repo, or set legacy SELF_DEV_MODE=true in .brain/.env.');
        $this->components->info('Then re-run: brain ' . $this->getName());

        return false;
    }
}
