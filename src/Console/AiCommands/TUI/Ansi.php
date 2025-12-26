<?php

declare(strict_types=1);

namespace BrainCLI\Console\AiCommands\TUI;

class Ansi
{
    public static function hideCursor(): void { echo "\033[?25l"; }
    public static function showCursor(): void { echo "\033[?25h"; }
    public static function clearScreen(): void { echo "\033[2J\033[H"; }
    public static function moveTo(int $row, int $col = 1): void { echo "\033[{$row};{$col}H"; }
    public static function clearLine(): void { echo "\033[2K"; }
    public static function reset(): void { echo "\033[0m"; }
    public static function bold(): void { echo "\033[1m"; }
    public static function inverse(): void { echo "\033[7m"; }


}
