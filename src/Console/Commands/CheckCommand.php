<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Console\Traits\StubGeneratorTrait;
use BrainCLI\Support\Brain;
use Illuminate\Console\Command;

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

        $dir = $dir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

        include $dir;

        $result = app(\Barin\Brain::class)->run();

        dd($result);

        return 0;
    }
}

