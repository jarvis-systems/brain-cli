<?php

declare(strict_types=1);

namespace BrainCLI;

use Yosymfony\Toml\Toml;

class McpFileDetector
{
    private string|null $file = null;
    private array $data = [];

    public function __construct(
        private string|null $type = null
    ) {
        if ($this->type === 'claude') {
            $this->file = getcwd() . '/.mcp.json';
        } elseif ($this->type === 'gemini') {
            $this->file = getenv('HOME') . '/.gemini/config.json';
        } elseif ($this->type === 'qwen') {
            $this->file = getcwd() . '/.qwen/settings.json';
        } elseif ($this->type === 'codex') {
            $this->file = getenv('HOME') . '/.codex/config.toml';
        } else {
            $this->type = 'claude';
            $this->file = getcwd() . '/.mcp.json';
            if (!file_exists($this->file)) {
                $this->type = 'gemini';
                $this->file = getenv('HOME') . '/.gemini/config.json';
            }
            if (!file_exists($this->file)) {
                $this->type = 'qwen';
                $this->file = getcwd() . '/.qwen/settings.json';
            }
            if (!file_exists($this->file)) {
                $this->type = 'codex';
                $this->file = getenv('HOME') . '/.codex/config.toml';
            }
            if (!file_exists($this->file)) {
                $this->file = null;
                $this->type = null;
            }
        }
        if (! $this->type || ! $this->file || ! file_exists($this->file)) {
            throw new \RuntimeException("No configuration file was found for Claude, Codex, Gemini, or Qwen.");
        }
        if ($this->type === 'codex') {
            $this->data = Toml::Parse(file_get_contents($this->file));
        } else {
            $this->data = json_decode(file_get_contents($this->file), true);
        }
    }

    public function addServer(object $server): void
    {
        if ($this->type === 'codex') {
            if (! isset($this->data['mcp_servers'][$server->key])) {
                $this->data['mcp_servers'][$server->key] = $server->template;
            } else {
                throw new \RuntimeException("Server with key {$server->key} already exists in MCP configuration.");
            }
        } else {
            if (! isset($this->data['mcpServers'][$server->key])) {
                $this->data['mcpServers'][$server->key] = $server->template;
            } else {
                throw new \RuntimeException("Server with key {$server->key} already exists in MCP configuration.");
            }
        }
    }

    public function getType(): string
    {
        return $this->type ?: throw new \RuntimeException("No configuration file was found for Claude, Codex, Gemini, or Qwen.");
    }

    public static function create(string $type = null): static
    {
        return new static($type);
    }
}
