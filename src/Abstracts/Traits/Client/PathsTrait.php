<?php

declare(strict_types=1);

namespace BrainCLI\Abstracts\Traits\Client;

trait PathsTrait
{
    /**
     * Get a client folder path.
     */
    public function folder(): string
    {
        return $this->normalizeValue($this->getFolderParts());
    }

    abstract protected function getFolderParts(): string|array;

    /**
     * Get a client file path.
     */
    public function file(): string
    {
        return $this->normalizeValue($this->getFileParts());
    }

    abstract protected function getFileParts(): string|array;

    /**
     * Get a client settings file path.
     */
    public function settingsFile(): string
    {
        return $this->normalizeValue($this->getSettingsFileParts());
    }

    protected function getSettingsFileParts(): string|array
    {
        return [$this->folder(), 'settings.json'];
    }

    /**
     * Get a client MCP file path.
     */
    public function mcpFile(): string
    {
        return $this->normalizeValue($this->getMcpFileParts());
    }

    protected function getMcpFileParts(): string|array
    {
        return $this->getSettingsFileParts();
    }

    /**
     * Get a client agents folder path.
     */
    public function agentsFolder(): string
    {
        return $this->normalizeValue($this->getAgentsFolderParts());
    }

    protected function getAgentsFolderParts(): string|array
    {
        return [$this->folder(), 'agents'];
    }

    /**
     * Get a client commands folder path.
     */
    public function commandsFolder(): string
    {
        return $this->normalizeValue($this->getCommandsFolderParts());
    }

    protected function getCommandsFolderParts(): string|array
    {
        return [$this->folder(), 'commands'];
    }

    /**
     * Get a client skills folder path.
     */
    public function skillsFolder(): string
    {
        return $this->normalizeValue($this->getSkillsFolderParts());
    }

    protected function getSkillsFolderParts(): string|array
    {
        return [$this->folder(), 'skills'];
    }

    /**
     * Normalize value to string path.
     */
    private function normalizeValue(string|array|null $value): string
    {
        if (is_null($value)) {
            throw new \RuntimeException('Constant must be defined as string or array in subclass.');
        }
        if (is_array($value)) {
            return implode(DS, $value);
        }
        return $value;
    }
}
