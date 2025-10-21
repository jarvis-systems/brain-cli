<?php

declare(strict_types=1);

namespace BrainCLI;

use Illuminate\Support\Collection;
use Illuminate\Support\ItemNotFoundException;
use BrainCLI\Models\Credential;

class Library
{
    private object $data;

    private mixed $notFoundCredentialCallback = null;

    public function __construct(
        private array $variables = []
    ) {
        $this->variables = array_merge($this->variables, getenv());
        $this->variables['project_dir'] = getcwd();
        $this->variables['mcp_dir'] = __DIR__ . '/..';
        $this->variables['time'] = time();
        $this->variables['date'] = date('Y-m-d');
        $this->variables['date_time'] = date('Y-m-d H:i:s');
        try {
            $this->variables['uuid'] = bin2hex(random_bytes(16));
        } catch (\Throwable) {}

        $this->data = json_decode(file_get_contents(__DIR__ . '/../library.json'));
    }

    public static function create(array $attributes = []): static
    {
        return new static($attributes);
    }

    public function all(string|null $search = null): Collection
    {
        return collect($this->data)->filter(function (object $server) use ($search) {
            if ($search) {
                $search = strtolower($search);
                return in_array($search, array_map('strtolower', $server->keywords ?? []))
                    || str_contains(strtolower($server->name), $search)
                    || str_contains(strtolower($server->description ?? ''), $search)
                    || str_contains(strtolower($server->license), $search)
                    || str_contains(strtolower($server->author ?? ''), $search);
            }
            return true;
        })->map(function (object $server, string $key) {
            $server->key = $key;
            return $server;
        });
    }

    public function get(string $name, bool $transform = false): object|null
    {
        $serverInfo = $this->all($name)->first();

        return $transform && $serverInfo ? $this->transformCredentials($serverInfo) : $serverInfo;
    }

    protected function transformCredentials(object $item): object|null
    {
        if (! ($template = $item->template ?? null)) {
            return null;
        }
        $template = json_encode((array)$template);
        $template = preg_replace_callback('/\{credential:([A-Za-z0-9_\-]+):?(.*?)}/', function ($matches) {
            [$input, $name, $default] = $matches;
            if ($default) {
                $default = str_replace([
                    "', '", "',  '", "',   '", "',    '"
                ], "','", $default);
                $default = array_map(fn (string $i) => trim($i), explode("','", trim($default, "'")));
                if (count($default) <= 1) {
                    $default = $default[0];
                }
                if (! $default) {
                    $default = null;
                }
            } else {
                $default = null;
            }
            $model = Credential::query()->where('name', $name)->first();
            if ($model) {
                return $model->value;
            }
            if ($this->notFoundCredentialCallback && is_callable($this->notFoundCredentialCallback)) {
                $value = call_user_func($this->notFoundCredentialCallback, $name, $default);
            } else {
                $value = is_array($default) ? $default[0] : $default;
            }
            if (! $value) {
                throw new ItemNotFoundException("Credential '{$name}' not found.");
            }
            return $value;
        }, $template);

        $item->template = json_decode($template, true);

        return $item;
    }

    public function setNotFoundCredentialCallback(mixed $notFoundCredentialCallback): static
    {
        $this->notFoundCredentialCallback = $notFoundCredentialCallback;

        return $this;
    }
}
