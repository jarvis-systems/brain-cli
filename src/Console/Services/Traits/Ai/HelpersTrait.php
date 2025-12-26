<?php

declare(strict_types=1);

namespace BrainCLI\Console\Services\Traits\Ai;

use BrainCLI\Support\Brain;

use function React\Async\await;

trait HelpersTrait
{
    /**
     * Get the value of the prompt.
     */
    public function value(): string|array
    {
        if (! $this->promise) {
            throw new \RuntimeException('Promise not set. Did you forget to call the ask() method?');
        }

        try {
            $return = await($this->promise)?->content
                ?? ($this->schema ? [] : '');
        } catch (\Throwable $e) {
            if (Brain::isDebug()) {
                dd($e);
            }
            $return = $this->schema ? [] : '';
        }

        if ($this->schema && ! is_array($return)) {
            throw new \RuntimeException('Expected array response due to schema, got ' . gettype($return));
        }

        return $return;
    }
}
