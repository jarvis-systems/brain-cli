<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Console\Traits\HelpersTrait;
use BrainCLI\Support\Brain;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Yaml\Yaml;

class DocsCommand extends Command
{
    use HelpersTrait;

    protected $signature = 'docs {keywords?*}';

    protected $description = 'Documentation index listing of .docs folder';

    public function handle(): int
    {
        $this->checkWorkingDir();

        $input = $this->argument('keywords');
        $keywords = Str::of(implode(' ', (array)$input));

        $keywords = Str::of($keywords)
            ->replace(' ', ',')
            ->replace(',,', ',')
            ->explode(',')
            ->filter();
        $projectDocsDirectory = Brain::projectDirectory('.docs');

        if (!is_dir($projectDocsDirectory)) {
            mkdir($projectDocsDirectory, 0755, true);
        }

        $files = $this->getFileList($projectDocsDirectory, $keywords);

        if (empty($files)) {
            $this->outputComponents()->warn('No documentation files found.');
            return 0;
        }

        $this->line(
            json_encode($files, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return 0;
    }

    /**
     * @param  string  $dir
     * @param  Collection<int, string>  $keywords
     * @return array<int, array{path: string, name?: string, description?: string, score?: int}>
     */
    public function getFileList(string $dir, Collection $keywords): array
    {
        $files = File::allFiles($dir);
        $collect = collect(array_map(function (SplFileInfo $file) use ($keywords) {
            if (! str_ends_with($file->getPathname(), '.md')) {
                return null;
            }
            $content = file_get_contents($file->getPathname());
            if (! $content) {
                return null;
            }

            $score = 0;
            $contentLower = Str::lower($content);

            if ($keywords->isNotEmpty()) {
                $found = false;
                foreach ($keywords as $keyword) {
                    if (Str::contains($contentLower, Str::lower($keyword))) {
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    return null;
                }
            }

            $result = [
                'path' => '.docs' . DS . $file->getRelativePathname(),
            ];

            if (preg_match('/^---\s*(.*?)\s*---/s', $content, $matches)) {
                try {
                    $yamlParsed = Yaml::parse($matches[1]);
                    if (is_array($yamlParsed)) {
                        if (isset($yamlParsed['name'])) {
                            $result['name'] = (string) $yamlParsed['name'];
                        }
                        if (isset($yamlParsed['description'])) {
                            $result['description'] = (string) $yamlParsed['description'];
                        }
                    }
                } catch (\Exception $e) {
                    if (Brain::isDebug()) {
                        dd($e);
                    }
                    $this->components->error("Failed to parse YAML in {$file->getRelativePathname()}");
                    exit(ERROR);
                }
            }

            if ($keywords->isNotEmpty()) {
                foreach ($keywords as $keyword) {
                    $kw = Str::lower($keyword);
                    if (isset($result['name']) && Str::contains(Str::lower($result['name']), $kw)) {
                        $score += 10;
                    }
                    if (isset($result['description']) && Str::contains(Str::lower($result['description']), $kw)) {
                        $score += 5;
                    }
                    if (Str::contains($contentLower, $kw)) {
                        $score += 1;
                    }
                }
                $result['score'] = $score;
            }

            return $result;
        }, $files))
            ->filter()
            ->unique('path')
            ->when($keywords->isNotEmpty(), fn ($c) => $c->sortByDesc('score'))
            ->values();

        if ($collect->count() > 50) {
            $collect = $collect->map(function (array $item) {
                unset($item['description']);
                return $item;
            });
        }

        return $collect->toArray();
    }
}

