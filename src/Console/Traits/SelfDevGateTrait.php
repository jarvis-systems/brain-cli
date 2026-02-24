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

        $this->components->error('SELF_DEV_MODE required for scaffolding.');
        $this->components->info('Enable: set SELF_DEV_MODE=true in .brain/.env');
        $this->components->info('Then re-run: brain ' . $this->getName());

        return false;
    }
}
