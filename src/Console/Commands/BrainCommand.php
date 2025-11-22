<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Console\Traits\HelpersTrait;
use Illuminate\Console\Command;

class BrainCommand extends Command
{
    use HelpersTrait;

    protected $signature = 'brain';

    public function handle(): int
    {
        $this->checkWorkingDir();

        $descriptorspec = [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR
        ];

        $process = proc_open('claude', $descriptorspec, $pipes);
        if (is_resource($process)) {
            $exitCode = proc_close($process);
            $this->components->success('Agent process exited with code ' . $exitCode);
            return $exitCode;
        }

        return OK;
    }
}

