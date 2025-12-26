<?php

namespace BrainCLI\Console\AiCommands\Lab\Traits;

use Laravel\Prompts\Output\BufferedConsoleOutput;

use function Termwind\render;
use function Termwind\renderUsing;

trait TermwindTrait
{
    protected function termwind(string $html)
    {
        renderUsing($output = new BufferedConsoleOutput);

        render($html);

        return $this->restoreEscapeSequences($output->fetch());
    }

    protected function restoreEscapeSequences(string $string)
    {
        return preg_replace('/\[(\d+)m/', "\e[".'\1m', $string);
    }
}
