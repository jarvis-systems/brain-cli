<?php

declare(strict_types=1);

namespace BrainCLI\Services\Mcp;

final class ToolingMode
{
    public const ENV_KILL_SWITCH = 'BRAIN_DISABLE_MCP';
    public const ENV_CLIENT_MCP = 'BRAIN_CLIENT_MCP';

    private ?bool $cached = null;
    private ?string $reason = null;

    public function isEnabled(): bool
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $killSwitch = $this->getEnv(self::ENV_KILL_SWITCH);
        if ($killSwitch === '1' || strtolower($killSwitch) === 'true') {
            $this->cached = false;
            $this->reason = 'Kill-switch active (BRAIN_DISABLE_MCP=true)';

            return false;
        }

        $clientMcp = $this->getEnv(self::ENV_CLIENT_MCP);
        if ($clientMcp === '0' || strtolower($clientMcp) === 'false') {
            $this->cached = false;
            $this->reason = 'Client MCP disabled (BRAIN_CLIENT_MCP=0)';

            return false;
        }

        $this->cached = true;
        $this->reason = null;

        return true;
    }

    public function isDisabled(): bool
    {
        return ! $this->isEnabled();
    }

    public function reason(): ?string
    {
        if ($this->cached === null) {
            $this->isEnabled();
        }

        return $this->reason;
    }

    public function reset(): void
    {
        $this->cached = null;
        $this->reason = null;
    }

    private function getEnv(string $name): string
    {
        $value = getenv($name);
        if ($value !== false) {
            return (string) $value;
        }

        return $_ENV[$name] ?? $_SERVER[$name] ?? '';
    }
}
