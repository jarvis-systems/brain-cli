<?php

declare(strict_types=1);

namespace BrainCLI\Console\AiCommands\TUI;

use Illuminate\Console\Command;

class Screen
{
    public function __construct(
        protected int $rows,
        protected int $cols,
        protected int $headerHeight = 3,
        protected int $footerHeight = 3,
        protected Command $command,
    ) {
        Ansi::hideCursor();
        register_shutdown_function(fn() => Ansi::showCursor());
        Ansi::clearScreen();
    }


    public function pad(string $s, int $width): string
    {
        $len = mb_strlen($s);
        if ($len >= $width) return mb_substr($s, 0, $width);
        return $s . str_repeat(' ', $width - $len);
    }

    public function drawHeader(string $title): void
    {
        for ($r = 1; $r <= $this->headerHeight; $r++) {
            Ansi::moveTo($r);
            Ansi::clearLine();
            Ansi::inverse();
            echo $this->pad($r === 2 ? "  $title" : "", $this->cols);
            Ansi::reset();
        }
    }

    public function drawFooter(string|callable $hint): void
    {
        for ($r = $this->footerTop(); $r <= $this->rows; $r++) {
            Ansi::moveTo($r, 1);
            Ansi::clearLine();
            Ansi::inverse();
            echo $this->pad($r === $this->footerTop() ? "  $hint" : "", $this->cols);
            Ansi::reset();
        }
    }

    public function clearBody(): void
    {
        for ($r = $this->bodyTop(); $r <= $this->bodyBottom(); $r++) {
            Ansi::moveTo($r, 1);
            Ansi::clearLine();
            echo $this->pad("", $this->cols);
        }
    }

    public function drawBody(array $lines): void
    {
        $r = $this->bodyTop();
        foreach ($lines as $line) {
            if ($r > $this->bodyBottom()) break;
            Ansi::moveTo($r++, 1);
            Ansi::clearLine();
            echo $this->pad("  " . $line, $this->cols);
        }
    }

    protected function bodyTop(): int
    {
        return $this->headerHeight + 1;
    }

    protected function bodyBottom(): int
    {
        return $this->rows - $this->footerHeight;
    }

    protected function footerTop(): int
    {
        return $this->rows - $this->footerHeight + 1;
    }
}
