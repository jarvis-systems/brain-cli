<?php

declare(strict_types=1);

namespace BrainCLI;

use BrainCLI\Console\Commands\DocsCommand;
use BrainCLI\Console\Commands\ListIncludesCommand;
use BrainCLI\Console\Commands\MakeScriptCommand;
use BrainCLI\Console\Commands\ListMastersCommand;
use BrainCLI\Console\Commands\ScriptCommand;
use BrainCLI\Console\Commands\UpdateCommand;
use BrainCLI\Support\Brain;
use Illuminate\Events\Dispatcher;
use BrainCLI\Config\ConfigManager;
use Illuminate\Console\Application;
use Illuminate\Container\Container;
use BrainCLI\Services\ClaudeCompile;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use BrainCLI\Database\DatabaseManager;
use BrainCLI\Console\Commands\InitCommand;
use BrainCLI\Console\Commands\CompileCommand;
use BrainCLI\Console\Commands\MakeMcpCommand;
use BrainCLI\Console\Commands\MakeSkillCommand;
use BrainCLI\Console\Commands\MakeMasterCommand;
use BrainCLI\Console\Commands\MakeCommandCommand;
use BrainCLI\Console\Commands\MakeIncludeCommand;
use BrainCLI\Foundation\Application as LaravelApplication;
use Dotenv\Dotenv;
use Dotenv\Repository\RepositoryBuilder;
use Dotenv\Repository\Adapter\PutenvAdapter;

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
        'claude:compile' => ClaudeCompile::class,
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
}
