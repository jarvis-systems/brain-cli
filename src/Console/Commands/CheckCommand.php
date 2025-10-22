<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Console\Traits\StubGeneratorTrait;
use BrainCLI\Support\Brain;
use Illuminate\Console\Command;

use Symfony\Component\Process\Process;

use function Illuminate\Support\php_binary;

class CheckCommand extends Command
{
    use StubGeneratorTrait;

    protected $signature = 'check';

    protected $description = 'Check Brain installation and generate necessary files';

    public function handle(): int
    {
        $dir = Brain::workingDirectory();

        if (! is_dir($dir)) {
            $dir = dirname($dir);
        }

        $core = $dir . DS . 'vendor' . DS . 'bin' . DS . 'brain-core';

        $php = php_binary();

        $command = [$php, $core, 'build'];

        $result = (new Process($command, $dir))
            ->setTimeout(null)
            ->setPty(true)
            ->run(function ($type, $output) {
                $this->output->write($output);
            });

        return $result;
    }
}

