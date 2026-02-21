<?php

declare(strict_types=1);

/**
 * PHPStan bootstrap file for CLI package.
 *
 * Defines constants that are normally set in bin/brain entry point.
 * PHPStan cannot execute bin/brain, so these must be defined here.
 */

if (!defined('OK')) {
    define('OK', 0);
}

if (!defined('ERROR')) {
    define('ERROR', 1);
}

if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}
