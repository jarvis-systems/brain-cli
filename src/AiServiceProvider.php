<?php

declare(strict_types=1);

namespace BrainCLI;

use BrainCLI\Console\AiCommands\CustomRunCommand;
use BrainCLI\Console\AiCommands\InstallCommand;
use BrainCLI\Console\AiCommands\LabCommand;
use BrainCLI\Console\AiCommands\MeetingCommand;
use BrainCLI\Console\AiCommands\RunCommand;
use BrainCLI\Console\AiCommands\UpdateCommand;
use BrainCLI\Enums\Agent;
use BrainCLI\Foundation\Application as LaravelApplication;
use BrainCLI\Services\LockFileFactory;
use BrainCLI\Support\Brain;
use Illuminate\Console\Application;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

class AiServiceProvider extends ServiceProvider
{
    /**
     * The commands provided by the service provider.
     *
     * @var list<class-string<\Illuminate\Console\Command>>
     */
    protected array $commands = [
        InstallCommand::class,
        UpdateCommand::class,
        MeetingCommand::class,
        LabCommand::class,
    ];

    /**
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \ReflectionException
     */
    public function register(): void
    {
        parent::register();

        foreach (array_reverse(Agent::enabledCases()) as $agent) {
            $this->app->add(
                $this->laravel->make(RunCommand::class, compact('agent'))
            );
        }

        $agentsFolder = Brain::workingDirectory('agents');
        if (is_dir($agentsFolder)) {
            $files = File::files($agentsFolder);
            foreach ($files as $file) {
                $filename = $file->getFilename();
                if (str_ends_with($filename, '.yaml') || str_ends_with($filename, '.yml')) {
                    if ($content = $file->getContents()) {
                        $data = Yaml::parse($content);
                        $callName = $file->getFilenameWithoutExtension();
                        if ($data && is_array($data) && isset($data['client'])) {
                            $agent = Agent::tryFrom($data['client']);
                            if ($agent) {
                                $this->app->add(
                                    $this->laravel->make(CustomRunCommand::class, compact(
                                         'callName', 'agent', 'data'
                                    ))
                                );
                            }
                        }
                    }
                }
            }
        }

        $default = LockFileFactory::get('last-used-agent', $agent->value ?? null);

        if ($default) {
            $this->app->setDefaultCommand($default);
        }
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
        $app = parent::bootApplication($laravel);

        $app->setName('AI CLI');

        return $app;
    }
}
