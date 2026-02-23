<?php

declare(strict_types=1);

namespace BrainCLI\Services\SelfDev;

use BrainCLI\Support\Brain;

use function config;
use function getcwd;
use function is_file;
use function is_link;
use function readlink;

/**
 * Canonical self-dev mode resolver.
 *
 * Single source of truth for SELF_DEV_MODE detection across CLI.
 * Used by: DiagnoseCommand, future make:* gating, future init gating.
 *
 * Resolution order (first match wins):
 * 1. ENV: SELF_DEV_MODE=true in .brain/.env or process environment
 * 2. Autodetect: node/Brain.php exists in BOTH root AND .brain/node/
 *
 * @see BrainCore\Variations\Traits\BrainIncludesTrait::isSelfDev() for compile-time logic
 */
class SelfDevResolver
{
    private const ENV_KEY = 'SELF_DEV_MODE';

    private ?bool $cachedEnabled = null;
    private ?string $cachedSource = null;
    private ?array $cachedSignals = null;

    public function __construct(
        private readonly string $projectRoot,
        private readonly string $brainDir,
        private readonly string $envFilePath,
    ) {}

    public static function make(): self
    {
        $projectRoot = getcwd() ?: '.';
        $brainDirName = to_string(config('brain.dir', '.brain'));

        return new self(
            projectRoot: $projectRoot,
            brainDir: $projectRoot . DIRECTORY_SEPARATOR . $brainDirName,
            envFilePath: Brain::workingDirectory('.env'),
        );
    }

    public function isEnabled(): bool
    {
        if ($this->cachedEnabled !== null) {
            return $this->cachedEnabled;
        }

        $this->resolve();

        return $this->cachedEnabled;
    }

    public function getSource(): string
    {
        if ($this->cachedSource !== null) {
            return $this->cachedSource;
        }

        $this->resolve();

        return $this->cachedSource;
    }

    public function getSignals(): array
    {
        if ($this->cachedSignals !== null) {
            return $this->cachedSignals;
        }

        $this->resolve();

        return $this->cachedSignals;
    }

    public function getEnvFilePath(): string
    {
        return $this->envFilePath;
    }

    public function hasEnvFile(): bool
    {
        return is_file($this->envFilePath);
    }

    private function resolve(): void
    {
        $signals = $this->detectSignals();
        $envPositive = $signals['env_has_self_dev'] && $this->isTruthy($signals['env_self_dev_value']);
        $autodetectPositive = $signals['node_brain_php_in_root'] && $signals['node_brain_php_in_dot_brain'];

        $this->cachedSignals = $signals;
        $this->cachedEnabled = $envPositive || $autodetectPositive;

        if ($envPositive) {
            $this->cachedSource = 'env';
        } elseif ($autodetectPositive) {
            $this->cachedSource = 'autodetect';
        } else {
            $this->cachedSource = 'off';
        }
    }

    private function detectSignals(): array
    {
        $nodeDir = $this->projectRoot . DIRECTORY_SEPARATOR . 'node';
        $brainNodeDir = $this->brainDir . DIRECTORY_SEPARATOR . 'node';

        $envHasSelfDev = getenv(self::ENV_KEY) !== false;
        $envSelfDevValue = $envHasSelfDev ? getenv(self::ENV_KEY) : null;

        return [
            'env_has_self_dev' => $envHasSelfDev,
            'env_self_dev_value' => $envSelfDevValue,
            'node_brain_php_in_root' => is_file($nodeDir . DIRECTORY_SEPARATOR . 'Brain.php'),
            'node_brain_php_in_dot_brain' => is_file($brainNodeDir . DIRECTORY_SEPARATOR . 'Brain.php'),
            'dot_brain_is_symlink' => is_link($this->brainDir),
            'dot_brain_target' => is_link($this->brainDir) ? readlink($this->brainDir) : null,
        ];
    }

    private function isTruthy(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
        }

        return (bool) $value;
    }
}
