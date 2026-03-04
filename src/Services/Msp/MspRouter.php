<?php

declare(strict_types=1);

namespace BrainCLI\Services\Msp;

use BrainCLI\Services\Msp\Registry\FileMspRegistryResolver;
use BrainCLI\Services\Msp\Registry\MspRegistryResolverInterface;

final class MspRouter
{
    public const ERROR_UNKNOWN_PROVIDER = 'UNKNOWN_PROVIDER';
    public const ERROR_UNKNOWN_TOOL = 'UNKNOWN_TOOL';
    public const ERROR_INVALID_INPUT = 'INVALID_INPUT';
    public const ERROR_MSP_DISABLED = 'MSP_DISABLED';
    public const ERROR_PROVIDER_ERROR = 'PROVIDER_ERROR';

    private MspMode $mode;
    private array $providers = [];
    private array $providerMeta = [];

    public function __construct(
        array $providers = [],
    ) {
        $this->mode = new MspMode();
        foreach ($providers as $provider) {
            if ($provider instanceof MspProviderInterface) {
                $this->providers[$provider->id()] = $provider;
            }
        }
    }

    public static function fromRegistry(
        ?MspRegistryResolverInterface $resolver = null,
        ?string $projectRoot = null,
    ): self {
        $resolver = $resolver ?? new FileMspRegistryResolver($projectRoot ?? '');
        $registry = $resolver->resolve();

        $router = new self();
        $router->mode = new MspMode();

        foreach ($registry['providers'] as $meta) {
            if (! ($meta['enabled'] ?? false)) {
                continue;
            }

            $class = $meta['class'] ?? null;
            if ($class === null || ! class_exists($class)) {
                continue;
            }

            try {
                $provider = new $class();
                if ($provider instanceof MspProviderInterface) {
                    $router->providers[$provider->id()] = $provider;
                    $router->providerMeta[$provider->id()] = $meta;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $router;
    }

    public function register(MspProviderInterface $provider): void
    {
        $this->providers[$provider->id()] = $provider;
    }

    public function hasProvider(string $id): bool
    {
        return isset($this->providers[$id]);
    }

    public function hasTool(string $providerId, string $tool): bool
    {
        if (! $this->hasProvider($providerId)) {
            return false;
        }

        return isset($this->providers[$providerId]->tools()[$tool]);
    }

    public function call(string $providerId, string $tool, array $args): array
    {
        if ($this->mode->isDisabled()) {
            return $this->error(
                self::ERROR_MSP_DISABLED,
                'kill_switch_active',
                'MSP routing disabled by BRAIN_DISABLE_MSP',
                'Remove BRAIN_DISABLE_MSP or use tools: commands directly'
            );
        }

        if (! $this->hasProvider($providerId)) {
            return $this->error(
                self::ERROR_UNKNOWN_PROVIDER,
                'provider_not_registered',
                'The requested provider is not available',
                'Use a registered provider ID'
            );
        }

        $provider = $this->providers[$providerId];

        if (! isset($provider->tools()[$tool])) {
            return $this->error(
                self::ERROR_UNKNOWN_TOOL,
                'tool_not_found',
                'The requested tool is not available',
                'Check provider tool list'
            );
        }

        return $provider->call($tool, $args);
    }

    public function listProviders(): array
    {
        return array_keys($this->providers);
    }

    public function listTools(string $providerId): array
    {
        if (! $this->hasProvider($providerId)) {
            return [];
        }

        return array_keys($this->providers[$providerId]->tools());
    }

    public function isMspEnabled(): bool
    {
        return $this->mode->isEnabled();
    }

    public function killSwitchEnv(): string
    {
        return MspMode::ENV_KILL_SWITCH;
    }

    public function getProviderMeta(string $id): ?array
    {
        return $this->providerMeta[$id] ?? null;
    }

    private function error(string $code, string $reason, string $message, string $hint): array
    {
        return [
            'ok' => false,
            'error' => [
                'code' => $code,
                'reason' => $reason,
                'message' => $message,
                'hint' => $hint,
            ],
        ];
    }
}
