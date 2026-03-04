<?php

declare(strict_types=1);

namespace BrainCLI\Services\Msp;

interface MspProviderInterface
{
    public function id(): string;

    public function tools(): array;

    public function call(string $tool, array $args): array;
}
