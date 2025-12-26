<?php

declare(strict_types=1);

namespace BrainCLI;

use BrainCLI\Config\ConfigManager;
use BrainCLI\Console\Commands\CompileCommand;
use BrainCLI\Console\Commands\DocsCommand;
use BrainCLI\Console\Commands\InitCommand;
use BrainCLI\Console\Commands\ListIncludesCommand;
use BrainCLI\Console\Commands\ListMastersCommand;
use BrainCLI\Console\Commands\MakeCommandCommand;
use BrainCLI\Console\Commands\MakeIncludeCommand;
use BrainCLI\Console\Commands\MakeMasterCommand;
use BrainCLI\Console\Commands\MakeMcpCommand;
use BrainCLI\Console\Commands\MakeScriptCommand;
use BrainCLI\Console\Commands\MakeSkillCommand;
use BrainCLI\Console\Commands\ScriptCommand;
use BrainCLI\Console\Commands\StatusCommand;
use BrainCLI\Console\Commands\UpdateCommand;
use BrainCLI\Database\DatabaseManager;
use BrainCLI\Foundation\Application as LaravelApplication;
use BrainCLI\Services\Clients\ClaudeClient;
use BrainCLI\Services\Clients\CodexClient;
use BrainCLI\Services\Clients\GeminiClient;
use BrainCLI\Services\Clients\QwenClient;
use BrainCLI\Support\Brain;
use Dotenv\Dotenv;
use Dotenv\Repository\Adapter\PutenvAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Illuminate\Console\Application;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;

class ServiceProvider
{
    /**
     * The commands provided by the service provider.
     *
     * @var list<class-string<\Illuminate\Console\Command>>
     */
    protected array $commands = [
        InitCommand::class,
        DocsCommand::class,
        //BrainCommand::class,
        StatusCommand::class,
        ScriptCommand::class,
        UpdateCommand::class,
        CompileCommand::class,
        MakeMcpCommand::class,
        MakeSkillCommand::class,
        MakeMasterCommand::class,
        MakeScriptCommand::class,
        ListMastersCommand::class,
        MakeIncludeCommand::class,
        MakeCommandCommand::class,
        ListIncludesCommand::class,
    ];

    /**
     * The singleton bindings provided by the service provider.
     *
     * @var array<string, class-string>
     */
    protected array $singletons = [

    ];

    /**
     * @param  \Illuminate\Console\Application  $app
     * @param  \BrainCLI\Foundation\Application  $laravel
     */
    public function __construct(
        protected Application $app,
        protected LaravelApplication $laravel,
    ) {
    }

    public static function create(
        Application $app, LaravelApplication $laravel,
    ): static {
        return new static($app, $laravel);
    }

    /**
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \ReflectionException
     */
    public function register(): void
    {
        foreach ($this->commands as $command) {
            $this->app->add($this->laravel->make($command));
        }

        foreach ($this->singletons as $name => $singleton) {
            $this->laravel->singleton($name, $singleton);
        }

        $this->laravel->bind('files', Filesystem::class);
    }

    /**
     * Boot the application.
     *
     * @param  \BrainCLI\Foundation\Application  $laravel
     * @return \Illuminate\Console\Application
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public static function bootApplication(LaravelApplication $laravel): Application
    {
        static::loadEnv();

        $laravel->instance('app', $laravel);

        Container::setInstance($laravel);
        Facade::setFacadeApplication($laravel);
        ConfigManager::boot($laravel);

        $events = new Dispatcher($laravel);

        DatabaseManager::boot($laravel, $events);

        $app = new Application($laravel, $events, Brain::version());
        $app->setName('Brain CLI');
        $app->get('completion')->setHidden();
        return $app;
    }

    protected static function loadEnv(): void
    {
        $brain = new Core;
        // Load environment variables
        if (file_exists($brain->workingDirectory('.env'))) {
            $repository = RepositoryBuilder::createWithDefaultAdapters()
                ->addAdapter(PutenvAdapter::class)
                ->immutable()
                ->make();

            $dotenv = Dotenv::create($repository, $brain->workingDirectory());
            $dotenv->load();
        }
    }

    public static function run(Application $app, ServiceProvider $provider): int
    {
        $status = OK;

        try {
            $provider->register();
        } catch (\Throwable $e) {
            if (static::isDebug()) {
                dd($e);
            }
            $status = $e->getCode() ?: ERROR;
        }

        if ($status === OK) {
            try {
                $status = $app->run();
            } catch (\Throwable $e) {
                if (static::isDebug()) {
                    dd($e);
                }
                $status = $e->getCode() ?: ERROR;
            }
        }

        return $status;
    }

    public static function isDebug(): bool
    {
        return !! static::getEnv('BRAIN_CLI_DEBUG')
            || !! static::getEnv('DEBUG');
    }

    public static function hasEnv(string $name): bool
    {
        return getenv(strtoupper($name)) !== false;
    }

    public static function setEnv(string $name, mixed $value = null): bool
    {
        $name = strtoupper($name);
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } elseif (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif ($value === null) {
            $value = 'null';
        }
        return putenv("$name=$value");
    }

    public static function getEnv(string $name): mixed
    {
        $name = strtoupper($name);
        $value = getenv($name);
        if ($value === false) {
            return null;
        }
        if ($value === 'null') {
            return null;
        }
        if (is_numeric($value)) {
            if (str_contains($value, '.')) {
                return (float) $value;
            }
            return (int) $value;
        }
        if (in_array(strtolower($value), ['true', 'false'], true)) {
            return filter_var($value, FILTER_VALIDATE_BOOL);
        }
        if (
            (str_starts_with($value, '[') && str_ends_with($value, ']'))
            || (str_starts_with($value, '{') && str_ends_with($value, '}'))
        ) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $value;
    }
}
