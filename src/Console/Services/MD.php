<?php

declare(strict_types=1);

namespace BrainCLI\Console\Services;

class MD
{
    public static function fromArray(array $data): string
    {
        $md = '';
        $iterationHeaders = 0;
        foreach ($data as $key => $value) {
            if ($value) {
                if (! is_int($key)) {
                    if ($md !== '') {
                        $md .= PHP_EOL;
                    }
                    $header = $iterationHeaders > 0 ? '##' : '#';
                    $md .= "$header $key" . PHP_EOL;
                    $iterationHeaders++;
                }
                if (is_array($value)) {
                    foreach ($value as $subKey => $subValue) {
                        $subValue = is_array($subValue)
                            ? json_encode($subValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                            : $subValue;
                        if (is_int($subKey)) {
                            $md .= " - $subValue" . PHP_EOL;
                        } else {
                            $md .= " - **$subKey**: $subValue" . PHP_EOL;
                        }
                    }
                } else {
                    $md .= $value . PHP_EOL;
                }
            }
        }
        return $md;
    }
}
