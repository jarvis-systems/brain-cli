<?php

declare(strict_types=1);

namespace BrainCLI\Services\Compile;

use BrainCLI\Dto\Compile\Data;
use BrainCLI\Enums\CompiledData\Format;
use Symfony\Component\Yaml\Yaml;

class NativeSkillCollector
{
    /**
     * @return list<Data>
     */
    public function collect(string $skillsRoot, string $workingDirectoryRelative = '.brain'): array
    {
        if (!is_dir($skillsRoot)) {
            return [];
        }

        $skills = [];
        $entries = scandir($skillsRoot);

        if ($entries === false) {
            return [];
        }

        sort($entries);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            // Skip hidden / staging folders. Dot-prefixed names under
            // node/Skills/ are reserved for tooling-internal use and MUST NOT
            // be picked up as compilable skills. Retained as defensive coding
            // even though the proposal-staging folders were removed in the
            // direct-write refactor of the skill flow.
            if (str_starts_with($entry, '.')) {
                continue;
            }

            $skillDirectory = $skillsRoot . DIRECTORY_SEPARATOR . $entry;
            $skillFile = $skillDirectory . DIRECTORY_SEPARATOR . 'SKILL.md';

            if (!is_dir($skillDirectory) || !is_file($skillFile)) {
                continue;
            }

            $skills[] = $this->parse($skillFile, $entry, $workingDirectoryRelative);
        }

        return $skills;
    }

    private function parse(string $skillFile, string $folderName, string $workingDirectoryRelative): Data
    {
        $content = (string) file_get_contents($skillFile);
        [$frontMatter, $body] = $this->splitFrontMatter($content, $skillFile);

        $meta = Yaml::parse($frontMatter);

        if (!is_array($meta)) {
            throw new \RuntimeException("Native skill {$skillFile} front matter must be a YAML mapping.");
        }

        $name = $meta['name'] ?? null;
        $description = $meta['description'] ?? null;

        if (!is_string($name) || trim($name) === '') {
            throw new \RuntimeException("Native skill {$skillFile} missing required front matter key: name.");
        }

        if (!is_string($description) || trim($description) === '') {
            throw new \RuntimeException("Native skill {$skillFile} missing required front matter key: description.");
        }

        $relativeFile = $this->relativeSkillFile($skillFile, $workingDirectoryRelative);
        $id = $this->slug($folderName);

        return Data::fromAssoc([
            'id' => $id . '-skill',
            'file' => $relativeFile,
            'class' => 'BrainNode\\Skills\\' . str_replace(' ', '', ucwords(str_replace('-', ' ', $id))) . 'NativeSkill',
            'meta' => [
                ...$meta,
                'id' => $id,
                '_native' => true,
                '_native_source_dir' => dirname($relativeFile),
            ],
            'namespace' => 'BrainNode\\Skills',
            'namespaceType' => 'Skills',
            'classBasename' => str_replace(' ', '', ucwords(str_replace('-', ' ', $id))) . 'NativeSkill',
            'format' => Format::XML,
            'structure' => ltrim($body),
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitFrontMatter(string $content, string $skillFile): array
    {
        if (!str_starts_with($content, "---\n")) {
            throw new \RuntimeException("Native skill {$skillFile} must start with YAML front matter.");
        }

        $end = strpos($content, "\n---", 4);

        if ($end === false) {
            throw new \RuntimeException("Native skill {$skillFile} has unterminated YAML front matter.");
        }

        $frontMatter = substr($content, 4, $end - 4);
        $body = substr($content, $end + 4);

        return [$frontMatter, $body === false ? '' : $body];
    }

    private function relativeSkillFile(string $skillFile, string $workingDirectoryRelative): string
    {
        $projectRoot = getcwd() ?: '';
        $realProjectRoot = realpath($projectRoot);
        $realSkillFile = realpath($skillFile);

        if ($realProjectRoot !== false && $realSkillFile !== false && str_starts_with($realSkillFile, $realProjectRoot)) {
            return ltrim(substr($realSkillFile, strlen($realProjectRoot)), DIRECTORY_SEPARATOR);
        }

        return trim($workingDirectoryRelative, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'node' . DIRECTORY_SEPARATOR . 'Skills'
            . DIRECTORY_SEPARATOR . basename(dirname($skillFile))
            . DIRECTORY_SEPARATOR . 'SKILL.md';
    }

    private function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? $value;
        $value = trim($value, '-');

        if ($value === '') {
            throw new \RuntimeException('Native skill folder name must contain at least one alpha-numeric character.');
        }

        return $value;
    }
}
