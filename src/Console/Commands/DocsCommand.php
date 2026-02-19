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

    protected $signature = 'docs {keywords?*}
        {--limit=5 : Limit the number of documentation files returned. Set to 0 for no limit.}
        {--with-headers=0 : Show documentation file list with headers, parsed from [Example: In Markdown files the "# ...", "## ...", "### ..." lines are considered headers. In html files the <h1>, <h2>, <h3> tags are considered headers. This option will extract these headers and include them in the output for better context.]. Set to 1 to enable first level headers extraction and 2 for first and second level headers extraction.}
        {--download?= : If set, will download from URL the file content in ./docs/sources/filename.(md|txt|html) and replace the path with the local file path. [Example: https://raw.githubusercontent.com/owner/repo/refs/heads/master/README.md]}
        {--save-as?= : Optional filename to save the downloaded file as, used in conjunction with --download. Must be a valid filename with .md, .txt, or .html extension. [Example: mydoc.md]}
        {--update : Update the downloaded already exists file in ./docs/sources/ by "url" in the yaml header of the file.}
    ';

    protected $description = 'Documentation index listing of .docs folder';

    public function handle(): int
    {
        $this->checkWorkingDir();

        if ($this->option('update')) {
            $this->updateDocsSources();
            return 0;
        }

        if ($this->option('download')) {
            $this->downloadDocsSources();
            return 0;
        }

        if ($this->option('save-as')) {
            $this->components->error('The --save-as option must be used in conjunction with --download.');
            return ERROR;
        }

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

    protected function updateDocsSources(): void
    {
        $sourcesDir = base_path('.docs/sources');
        if (!is_dir($sourcesDir)) {
            $this->components->error('Sources directory does not exist. Nothing to update.');
            exit(ERROR);
        }
        $files = File::allFiles($sourcesDir);
        foreach ($files as $file) {
            if (!str_ends_with($file->getPathname(), '.md')) {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            if (!$content) {
                continue;
            }
            if (preg_match('/^---\s*(.*?)\s*---/s', $content, $matches)) {
                try {
                    $yamlParsed = Yaml::parse($matches[1]);
                    if (is_array($yamlParsed) && isset($yamlParsed['url'])) {
                        $url = $yamlParsed['url'];
                        try {
                            $downloadedContent = file_get_contents($url);
                            if ($downloadedContent === false) {
                                throw new \Exception('Failed to download content from the URL.');
                            }
                            if (empty($downloadedContent)) {
                                throw new \Exception('Downloaded file is empty.');
                            }
                            $downloadedContent = $this->normalizeHtml($downloadedContent);
                            $yamlHeader = "---\nname: {$file->getFilename()}\ndescription: Documentation file downloaded from {$url}\n---\n\nurl: {$url}\n\ndate: " . date('Y-m-d H:i:s') . "\n\n";
                            $newContent = $yamlHeader . $downloadedContent;
                            file_put_contents($file->getPathname(), $newContent);
                            $this->components->info("File updated: {$file->getFilename()}");
                        } catch (\Exception $e) {
                            $this->components->error("Error updating file {$file->getFilename()}: {$e->getMessage()}");
                        }
                    } else {
                        $this->components->warn("No 'url' found in YAML header of {$file->getFilename()}. Skipping.");
                    }
                } catch (\Exception $e) {
                    if (Brain::isDebug()) {
                        dd($e);
                    }
                    $this->components->error("Failed to parse YAML in {$file->getFilename()}. Skipping.");
                }
            } else {
                $this->components->warn("No YAML header found in {$file->getFilename()}. Skipping.");
            }
        }
    }

    protected function downloadDocsSources(): void
    {
        $url = $this->option('download');
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $this->components->error('Invalid URL provided for download.');
            exit(ERROR);
        }
        $filename = $this->option('save-as') ?: basename(parse_url($url, PHP_URL_PATH));
        if (!preg_match('/^[\w,\s-]+\.(md|txt|html)$/', $filename)) {
            $this->components->error('Invalid file type. Only .md, .txt, and .html files are allowed.');
            exit(ERROR);
        }
        try {
            $content = file_get_contents($url);
            if ($content === false) {
                throw new \Exception('Failed to download content from the URL.');
            }
        } catch (\Exception $e) {
            $this->components->error("Error downloading file: {$e->getMessage()}");
            exit(ERROR);
        }
        if (empty($content)) {
            $this->components->error('Downloaded file is empty.');
            exit(ERROR);
        }
        $sourcesDir = base_path('.docs/sources');
        if (!is_dir($sourcesDir)) {
            mkdir($sourcesDir, 0755, true);
        }
        $localPath = $sourcesDir . DS . $filename;
        try {
            $content = $this->normalizeHtml($content);
            $yamlHeader = "---\nname: {$filename}\ndescription: Documentation file downloaded from {$url}\n---\n\nurl: {$url}\n\ndate: " . date('Y-m-d H:i:s') . "\n\n";
            $content = $yamlHeader . $content;

            file_put_contents($localPath, $content);
        } catch (\Exception $e) {
            $this->components->error("Error saving file: {$e->getMessage()}");
            exit(ERROR);
        }
            $this->components->info("File downloaded and saved to: .docs/sources/{$filename}");
    }

    protected function normalizeHtml(string $content): string
    {
        // Check if content is HTML by looking for common tags
        if (!Str::contains($content, ['<html', '<body', '<div', '<p', '<br', '<span'])) {
            return $content;
        }
        // Remove all HTML tags and decode HTML entities
        $text = html_entity_decode(strip_tags($content));
        // Replace multiple whitespace with a single space
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
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
                        $result = array_merge($result, $yamlParsed);
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

            if ($this->option('with-headers') > 0) {
                $fileType = pathinfo($file->getFilename(), PATHINFO_EXTENSION);
                // check if the file is markdown
                if (strtolower($fileType) === 'md') {
                    preg_match_all('/^(#{1,6})\s*(.+)$/m', $content, $headerMatches);
                    if (!empty($headerMatches[0])) {
                        $headers = [];
                        foreach ($headerMatches[0] as $index => $fullMatch) {
                            $level = strlen($headerMatches[1][$index]);
                            if ($level <= (int)$this->option('with-headers')) {
                                $headers[] = trim($headerMatches[2][$index]);
                            }
                        }
                        if (!empty($headers)) {
                            $result['headers'] = $headers;
                        }
                    }
                } elseif (strtolower($fileType) === 'html') {
                    preg_match_all('/<h([1-6])>(.*?)<\/h\1>/i', $content, $headerMatches);
                    if (!empty($headerMatches[0])) {
                        $headers = [];
                        foreach ($headerMatches[0] as $index => $fullMatch) {
                            $level = (int)$headerMatches[1][$index];
                            if ($level <= (int)$this->option('with-headers')) {
                                $headers[] = trim($headerMatches[2][$index]);
                            }
                        }
                        if (!empty($headers)) {
                            $result['headers'] = $headers;
                        }
                    }
                } else {
                    // For txt files, we can consider lines that are in all caps as headers
                    preg_match_all('/^(.*)$/m', $content, $lines);
                    if (!empty($lines[0])) {
                        $headers = [];
                        foreach ($lines[0] as $line) {
                            if (strtoupper($line) === $line && strlen(trim($line)) > 0) {
                                $headers[] = trim($line);
                            }
                        }
                        if (!empty($headers)) {
                            $result['headers'] = $headers;
                        }
                    }
                }
            }

            return $result;
        }, $files))
            ->filter()
            ->unique('path')
            ->when($keywords->isNotEmpty(), fn ($c) => $c->sortByDesc('score'))
            ->values()
            ->when($this->option('limit') > 0, fn ($c) => $c->take((int)$this->option('limit')));

        return $collect->toArray();
    }
}

