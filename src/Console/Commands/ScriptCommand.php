<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Console\Traits\HelpersTrait;
use BrainCLI\Support\Brain;
use Illuminate\Console\Command;

use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Process\Process;

use function Illuminate\Support\php_binary;

class ScriptCommand extends Command
{
    use HelpersTrait;

    protected $signature = 'script';

    protected $description = 'Run a script';

    protected $aliases = ['s'];

    public function __construct()
    {
        parent::__construct();
        $this->addUsage('script [...arguments] {...--options}');
        $this->ignoreValidationErrors();
    }

    public function handle(): int
    {
        $this->checkWorkingDir();

        // Get all raw arguments after 'script' command
        $argv = $_SERVER['argv'] ?? [];

        // Find 'script' position and take everything after it
        $scriptPos = array_search('script', $argv, true);
        if ($scriptPos === false) {
            foreach ($this->aliases as $alias) {
                $aliasPos = array_search($alias, $argv, true);
                if ($aliasPos !== false) {
                    $scriptPos = $aliasPos;
                    break;
                }
            }
        }
        $args = $scriptPos !== false ? array_slice($argv, $scriptPos + 1) : [];

        $dir = Brain::workingDirectory();

        $command = [
            php_binary(),
            '-d', 'xdebug.mode=off', '-d', 'opcache.enable_cli=1',
            $dir . DS . 'vendor' . DS . 'bin' . DS . 'brain-script',
            ...$args,
        ];

        return (new Process($command, Brain::projectDirectory()))
            ->setTimeout(null)
            ->setPty(Process::isPtySupported())
            ->setTTY(Process::isTTYSupported())
            ->run(function ($type, $output) {
                $this->output->write($output);
            });
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        $dir = Brain::workingDirectory();

        // Get available commands from brain-script
        $process = new Process([
            php_binary(),
            '-d', 'xdebug.mode=off',
            $dir . DS . 'vendor' . DS . 'bin' . DS . 'brain-script',
            'list',
            '--format=json',
        ], Brain::projectDirectory());

        $process->run();

        if ($process->isSuccessful()) {
            $output = $process->getOutput();
            $data = json_decode($output, true);

            if (isset($data['commands'])) {
                foreach ($data['commands'] as $command) {
                    if (isset($command['name']) && $command['name'] !== 'help') {
                        $suggestions->suggestValue($command['name']);
                    }
                }
            }
        }
    }
}

