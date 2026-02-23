<?php

declare(strict_types=1);

namespace BrainCLI\Exceptions;

use RuntimeException;

final class InvalidModelIdException extends RuntimeException
{
    public static function forOpenCode(string $model): self
    {
        return new self(sprintf(
            'Invalid OpenCode model ID: "%s". ' .
            'Model IDs must be in provider/model format (e.g., "anthropic/claude-sonnet-4-5") ' .
            'or a known alias (sonnet, haiku, opus). ' .
            'Unknown bare aliases are not allowed.',
            $model
        ));
    }
}
