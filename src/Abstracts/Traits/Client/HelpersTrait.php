<?php

declare(strict_types=1);

namespace BrainCLI\Abstracts\Traits\Client;

use Bfg\Dto\Dto;
use BrainCLI\Support\Brain;

trait HelpersTrait
{
    protected array $backups = [];

    protected bool $temporalSystemAppend = true;

    protected function extractJson(string $value): array
    {
        $original = $value;
        $value = preg_replace('/.*?([{|\[].*[}|\]]).*/ms', '$1', $value);
        $value = trim($value);
        if ($value && Dto::isJson($value)) {
            try {
                $result = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                return is_array($result) ? $result : throw new \JsonException('Decoded JSON is not an array', 0);
            } catch (\JsonException $e) {
                return ['error' => $e->getCode(), 'message' => $e->getMessage(), 'original' => $original, 'value' => $value];
            }
        }
        return ['error' => 'invalid_json', 'message' => 'The provided content is not valid JSON.', 'original' => $original, 'value' => $value];
    }


    protected function generateWithYamlHeader(array $parameters = [], string|array|null $structure = '', int $tab = 0): string
    {
        $parameters = array_filter($parameters, fn ($value) => !! $value);

        $header = "";
        $tabs = str_repeat("  ", $tab);

        if (is_array($structure)) {
            $structure = json_encode($structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (count($parameters) > 0) {
            if (! $tab) {
                $header .= "---\n";
            }
            foreach ($parameters as $key => $value) {
                if ($value) {
                    if (is_array($value)) {
                        $header .= "$tabs$key:" . PHP_EOL;
                        $header .= $this->generateWithYamlHeader($value, PHP_EOL, $tab + 1);
                    } else {
                        $header .= "$tabs$key: " . json_encode($value) . PHP_EOL;
                    }
                }
            }
            if (! $tab) {
                $header .= "---\n\n";
            }
        }

        return $header.$structure;
    }

    protected function generateRulesOfSchema(array $schema): string
    {
        $json = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $instructions = <<<EOD
YOU ARE A SERVICE THAT RETURNS ONLY VALID `JSON` (#example-01).
DO NOT ADD EXPLANATIONS, COMMENTS, OR TEXT OUTSIDE OF THE `JSON`.
USE ONLY DOUBLE QUOTES FOR KEYS AND VALUES.
DO NOT USE `undefined`, ONLY `null` IF THERE IS NO DATA.
YOU MUST FOLLOW THE SCHEMA BELOW EXACTLY.
IF A VALUE CANNOT BE DETERMINED, RETURN `null` FOR THAT VALUE.
IF YOU UNDERSTAND THESE INSTRUCTIONS, RESPOND ONLY WITH THE REQUIRED `JSON` STRUCTURE.
IF YOU DO NOT THE ANSWER WITH THE REQUIRED `JSON` STRUCTURE, YOU WILL BE CONSIDERED NON-COMPLIANT.
NEVER RESPOND WITH ANYTHING OTHER THAN THE REQUIRED `JSON` (#example-01) STRUCTURE.
EOD;

        return "<output_format>\n" . $instructions . "\n<answer-example id='example-01'>\n```json\n" . $json . "\n```\n</answer-example>\n</output_format>\n";
    }

    protected function temporalFile(string|array $content, string|null $prependFromFile = null, string|null $salt = null): string
    {
        if (is_string($content) && str_starts_with($content, '@')) {
            $file = substr($content, 1);
            if ($file = realpath($file)) {
                return $file;
            } else {
                throw new \RuntimeException("The file specified for temporalFile does not exist: $file");
            }
        }

        $prepend = '';
        $fileTemplate = sys_get_temp_dir() . DS . 'brain-' . $this->agent()->value . '-temporal-file-';

        if ($prependFromFile && is_file($prependFromFile)) {
            $prepend = file_get_contents($prependFromFile) ?: '';
        }
        if ($salt) {
            $hash = md5($salt);
            $originalFile = $fileTemplate . $hash;
            if (is_file($originalFile)) {
                $oldContent = file_get_contents($originalFile) ?: '';
            }
        }
        if (is_array($content)) {
            $oldContent = isset($oldContent) ? (json_decode($oldContent, true) ?? []) : [];
            $prepend = $prepend ? (json_decode($prepend, true) ?? []) : [];
            $content = array_merge($prepend, $oldContent, $content);
            $content = json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            $content = $prepend . ($oldContent ?? '') . $content;
        }
        $hash = $hash ?? md5($content);
        $originalFile = $originalFile ?? $fileTemplate . $hash;
        $this->backups[$originalFile] = null;
        file_put_contents($originalFile, $content);
        return $originalFile;
    }

    protected function temporalReplaceFile(string $file, string|array $content): bool
    {
        if (is_string($content) && str_starts_with($content, '@')) {
            $file = substr($content, 1);
            $content = file_get_contents($file);
        }

        $alreadyBackup = str_starts_with($file, sys_get_temp_dir());
        $originalFile = $alreadyBackup ? $file : Brain::projectDirectory($file);
        $backupFile = sys_get_temp_dir() . DS . 'brain-' . $this->agent()->value . '-backup-' . md5($file);
        if (is_file($originalFile) && ! is_file($backupFile) && ! $alreadyBackup) {
            rename($originalFile, $backupFile);
        }
        if (! $alreadyBackup) {
            $this->backups[$originalFile] = $backupFile;
        }
        if (is_array($content)) {
            $content = json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return !! file_put_contents($originalFile, $content);
    }

    protected function temporalAppendFile(string $file, string|array $content): bool
    {
        if (is_string($content) && str_starts_with($content, '@')) {
            $file = substr($content, 1);
            $content = file_get_contents($file);
        }

        $alreadyBackup = str_starts_with($file, sys_get_temp_dir());
        $originalFile = $alreadyBackup ? $file : Brain::projectDirectory($file);
        $originalContent = is_file($originalFile) ? (file_get_contents($originalFile) ?? '') : '';
        if (is_array($content)) {
            $originalContent = json_decode($originalContent, true) ?? [];
            $content = array_merge($originalContent, $content);
        } else {
            $replaced = $this->temporalSystemAppend ? '</system>' : '<system>';
            if (str_contains($originalContent, $replaced)) {
                $insert = PHP_EOL . "<iron_rule>" .  PHP_EOL . $content . PHP_EOL . "</iron_rule>" . PHP_EOL;
                $insert = $this->temporalSystemAppend ? $insert . PHP_EOL . $replaced : $replaced . PHP_EOL . $insert;
                $content = str_replace($replaced, $insert, $originalContent);
            } else {
                $content = (! empty($originalContent) ? $originalContent . PHP_EOL : '') . $content;
            }
        }
        return $this->temporalReplaceFile($file, $content);
    }

    protected function restoreTemporalFiles(): void
    {
        if (count($this->backups) > 0) {
            foreach ($this->backups as $originalFile => $backupFile) {
                if (is_file($originalFile)) {
                    unlink($originalFile);
                }
                if ($backupFile && is_file($backupFile)) {
                    rename($backupFile, $originalFile);
                }
            }
        }
    }
}
