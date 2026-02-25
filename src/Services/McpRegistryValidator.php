<?php

declare(strict_types=1);

namespace BrainCLI\Services;

use BrainCLI\Support\Brain;
use BrainCore\Attributes\Meta;
use BrainCore\Architectures\McpArchitecture;
use BrainCore\Contracts\McpRegistry\ResolvedRegistry;
use RuntimeException;

final class McpRegistryValidator
{
    /**
     * Validate the resolved registry entries.
     *
     * @throws RuntimeException If any entry is invalid
     */
    public function validate(ResolvedRegistry $registry): void
    {
        $this->ensureRootAutoloader();

        foreach ($registry->servers as $server) {
            if (! ($server['enabled'] ?? false)) {
                continue;
            }

            $id = $server['id'];
            $class = $server['class'];

            if (! class_exists($class)) {
                $this->fail('class_missing', "MCP server class '{$class}' (ID: {$id}) does not exist.", "Ensure the class name is correct and autoloadable.");
            }

            if (! is_subclass_of($class, McpArchitecture::class)) {
                $this->fail('bad_interface', "MCP server class '{$class}' (ID: {$id}) must extend McpArchitecture.", "Update the class to extend BrainCore\\Architectures\\McpArchitecture.");
            }

            $this->validateMetaId($class, $id);
        }
    }

    private function validateMetaId(string $class, string $registryId): void
    {
        try {
            $ref = new \ReflectionClass($class);
            $attributes = $ref->getAttributes(Meta::class);
            $metaId = null;

            foreach ($attributes as $attribute) {
                /** @var Meta $meta */
                $meta = $attribute->newInstance();
                if ($meta->name === 'id') {
                    $metaId = $meta->getText();
                    break;
                }
            }

            if ($metaId === null) {
                $this->fail('missing_meta', "MCP server class '{$class}' is missing #[Meta('id', ...)] attribute.", "Add #[Meta('id', '{$registryId}')] to the class.");
            }

            if ($metaId !== $registryId) {
                $this->fail('id_mismatch', "MCP server ID mismatch for class '{$class}'. Registry ID: '{$registryId}', Meta ID: '{$metaId}'.", "Ensure the registry ID matches the #[Meta('id', ...)] attribute in the class.");
            }
        } catch (\Throwable $e) {
            if ($e instanceof RuntimeException && str_contains($e->getMessage(), 'code=MCP_REGISTRY_INVALID')) {
                throw $e;
            }
            $this->fail('reflection_error', "Failed to reflect MCP class '{$class}': " . $e->getMessage(), "Check class definition for errors.");
        }
    }

    private function fail(string $reason, string $message, string $hint): void
    {
        throw new RuntimeException(
            "code=MCP_REGISTRY_INVALID reason={$reason} message=\"{$message}\" hint=\"{$hint}\""
        );
    }

    private function ensureRootAutoloader(): void
    {
        $projectRoot = Brain::projectDirectory();
        $rootAutoloader = $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

        if (is_file($rootAutoloader)) {
            require_once $rootAutoloader;
        }
    }
}
