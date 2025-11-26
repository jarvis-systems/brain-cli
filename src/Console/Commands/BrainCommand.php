<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Console\Traits\CompilerBridgeTrait;
use BrainCLI\Enums\Agent;
use BrainCLI\Services\LockFileFactory;
use BrainCLI\Support\Brain;
use Illuminate\Console\Command;
use ValueError;

class BrainCommand extends Command
{
    use CompilerBridgeTrait;

    protected $signature = 'brain
        {agent? : Agent for which to start the interactive session}
        {--continue : Continue the interactive session}
        ';

    protected array $descriptorspec = [
        0 => STDIN,
        1 => STDOUT,
        2 => STDERR
    ];

    public function handle(): int
    {
        $agent = null;
        if ($selectAgent = $this->argument('agent')) {
            try {
                $agent = Agent::from($selectAgent);
            } catch (ValueError) {
                $this->components->error("Unsupported agent: {$selectAgent}");
                return ERROR;
            }
        }

        if (! $agent) {
            $lua = LockFileFactory::get('last-used-agent');
            if ($lua && $enum = Agent::tryFrom($lua)) {
                $agent = $enum;
            }
        }

        if (! $agent) {
            $selectAgent = $this->components->choice('Select agent for compilation', Agent::list(), Agent::CLAUDE->value);
            try {
                $agent = Agent::from($selectAgent);
            } catch (ValueError) {
                $this->components->error("Unsupported agent: {$selectAgent}");
                return ERROR;
            }
        }

        $this->initCommand($agent);

        return $this->applyComplier(function () {

            if ($this->option('continue')) {
                $command = $this->compiler->resume();
            } else {
                $command = $this->compiler->run();
            }

            foreach ($command as $key => $item) {
                if (str_contains($item, ' ')) {
                    $command[$key] = '"' . $item . '"';
                }
            }

            $command = implode(' ', $command);
            $env = $this->compiler->commandEnv();
            foreach ($env as $key => $value) {
                $command = "{$key}={$value} {$command}";
            }

            LockFileFactory::save('last-used-agent', $this->agent->value);

            $process = proc_open($command, $this->descriptorspec, $pipes);
            if (is_resource($process)) {
                $exitCode = proc_close($process);
                if ($exitCode === OK) {
                    $this->compiler->exit();
                }
                return $exitCode;
            }

            return OK;
        });
    }
}

