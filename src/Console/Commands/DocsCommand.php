<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Console\Traits\HelpersTrait;
use BrainCLI\Support\Brain;
use Carbon\Carbon;
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

//        foreach ($files as $file) {
//            $this->line("Path: {$file['path']}");
//            if (isset($file['name'])) {
//                $this->line("Name: {$file['name']}");
//            }
//            if (isset($file['description'])) {
//                $this->line("Description: {$file['description']}");
//            }
//            if (isset($file['part'])) {
//                $this->line("Part: {$file['part']}");
//            }
//            if (isset($file['type'])) {
//                $this->line("Type: {$file['type']}");
//            }
//            $this->line("---");
//        }

        return 0;
    }

    /**
     * @param  string  $dir
     * @param  \Illuminate\Support\Collection  $keywords
     * @return array<array<string, string>>
     *
     * Structure in file:
     * ---
     * name: "Document Title"
     * description: "Brief description"
     * part: 1
     * type: "guide"
     * date: "2025-11-12"
     * version: "1.0.0"
     * ---
     */
    public function getFileList(string $dir, Collection $keywords): array
    {
        $files = File::allFiles($dir);
        $collect = collect(array_map(function (SplFileInfo $file) use ($keywords) {
            $path = $file->getPathname();
            $date = $file->getMTime();
            if (! str_ends_with($path, '.md')) {
                return null;
            }
            $content = file_get_contents($path);
            if (! $content) {
                return null;
            }

            if ($keywords->isNotEmpty()) {
                $found = false;
                foreach ($keywords as $keyword) {
                    if (
                        Str::contains(Str::lower($content), Str::lower($keyword))
                    ) {
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    return null;
                }
            }
            $metadata = [

            ];

            if (preg_match('/^---\s*(.*?)\s*---/s', $content, $matches)) {

                try {
                    $yamlParsed = Yaml::parse($matches[1]);
                    if (is_array($yamlParsed)) {
                        foreach ($yamlParsed as $key => $value) {
                            if ($key === 'name' || $key === 'description') {
                                $metadata[$key] = (string)$value;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    if (Brain::isDebug()) {
                        dd($e);
                    }
                    $this->components->error("Failed to parse {$matches[1]}");
                    exit(ERROR);
                }
            }

            return [
                '.docs' . DS . $file->getRelativePathname() => $metadata,
            ];
        }, $files))->filter()->unique(fn (array $i, string $k) => $k)->values();
        if ($collect->count() > 50) {
            $collect = $collect->map(function (array $data) {
                unset($data['description']);
                return $data;
            });
        }
        return $collect->toArray();
    }
}

