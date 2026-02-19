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

    protected $signature = 'docs {keywords?* : Search keywords (OR logic, case-insensitive)}
        {--limit=5 : Max results (0 = unlimited)}
        {--headers=0 : Extract headers with line ranges (1=H1, 2=H1+H2, 3=H1+H2+H3)}
        {--stats : Include file stats (lines, words, size, hash)}
        {--code : Extract code blocks with detected language and line ranges}
        {--snippets : Include preview of header section content (max 200 chars)}
        {--links : Extract internal/external links from document}
        {--keywords : Extract top 10 frequent terms}
        {--matches : Show keyword match locations with context}
        {--undocumented : Scan codebase for classes/methods without docs}
        {--download= : Download doc from URL to .docs/sources/}
        {--as= : Filename for --download (default: URL basename)}
        {--update : Update all downloaded docs from their source URLs}
    ';

    protected $description = 'Index and search .docs folder with rich metadata extraction';

    public function getHelp(): string
    {
        $verbosity = $this->detectHelpVerbosity();

        return match ($verbosity) {
            0 => $this->getHelpMinimal(),
            1 => $this->getHelpMedium(),
            2 => $this->getHelpMega(),
            default => $this->getHelpUltra(),
        };
    }

    protected function detectHelpVerbosity(): int
    {
        $argv = $_SERVER['argv'] ?? [];

        if (in_array('-vvv', $argv, true)) {
            return 3;
        }
        if (in_array('-vv', $argv, true)) {
            return 2;
        }
        if (in_array('-v', $argv, true) || in_array('--verbose', $argv, true)) {
            return 1;
        }

        return 0;
    }

    protected function getHelpMinimal(): string
    {
        return parent::getHelp() . <<<'HELP'

Quick: brain docs [keywords] [--options]
  brain docs api              Search for "api"
  brain docs api --headers=1  Search with headers
  brain docs --update         Refresh downloaded docs
  brain docs --undocumented   Find classes without docs

Cognitive Triggers:
  Using --download?     → Security validation runs. See -vv for details.
  Using --matches?      → Need to know WHERE keywords appear. See -vv.
  Using --undocumented? → Found gaps? Create docs. Prioritize by method_count.
  No results?           → Try 3+ keyword variations (split CamelCase, strip suffixes).

Tip: -v for usage, -vv for patterns, -vvv for internals.
HELP;
    }

    protected function getHelpMedium(): string
    {
        return parent::getHelp() . <<<'HELP'

YAML Front Matter (recommended):
  ---
  name: Document Title
  description: Brief description
  url: https://...  (required for --update)
  date: 2025-02-19
  ---

Output:
  JSON array with: path, name, description, score
  Optional: headers[], stats, code_blocks[], links, keywords[], matches[]

Examples:
  brain docs api --headers=2 --stats    Full metadata
  brain docs api --matches              Find where "api" appears
  brain docs --download=https://...     Download external doc

Cognitive Triggers:
  Before writing code?    → Search docs FIRST. Avoid duplicate work.
  Found interesting URL?  → Download, index, store to vector memory.
  Task mentions feature?  → brain docs feature --headers=2 --code

Tip: -vv for best practices + use cases. -vvv for detection algorithms.
HELP;
    }

    protected function getHelpMega(): string
    {
        return parent::getHelp() . <<<'HELP'

YAML Front Matter (optional but recommended):
  ---
  name: Document Title
  description: Brief description for search ranking
  url: https://example.com/docs/source.md  (required for --update)
  date: 2025-02-19
  ---
  
  Documents without YAML still work, but lack name/description in output.
  Only .md files with valid url in YAML can be updated via --update.

Update Behavior (--update):
  Scans ALL .md files in .docs/ (not just sources/), finds those with valid url
  in YAML, downloads fresh content, preserves existing YAML fields, updates date.

Output:
  JSON array with documents matching keywords. Each document includes:
  - path, name, description (from YAML front matter)
  - score (10=keyword in name, 5=in description, 1=in content)
  - headers[] (if --headers): text, start_line, end_line, snippet?
  - stats (if --stats): lines, words, size, hash, modified
  - code_blocks[] (if --code): language, start_line, end_line (detected, no "text")
  - links (if --links): internal[], external[]
  - keywords[] (if --keywords): top 10 frequent terms
  - matches[] (if --matches): keyword, line, context (50 chars around match)

Best Practices:
  1. Always add YAML with name/description for better search ranking
  2. Use --headers=2 for technical docs to get section structure
  3. Use --matches to find exact keyword locations before reading
  4. Use --code for API docs to see which languages have examples
  5. Keep remote docs in .docs/sources/ with url for --update support

Use Cases:
  Research Workflow:
    1. Find doc URL → brain docs --download=<url>
    2. Search locally: brain docs topic --headers=2
    3. Store insights to vector memory
    → TRIGGER: After download, always check security (-vv). After search, store to memory.
  
  Documentation Indexing:
    1. Download package docs
    2. brain docs <term> --headers=2 --code --matches
    3. Get structured overview with code examples
    → TRIGGER: Missing docs for your code? Create them. Use /doc:work.
  
  Code Research:
    1. brain docs <feature> --matches
    2. Find WHERE in docs feature is mentioned
    3. Read specific lines, not entire file
    → TRIGGER: No matches? Try 3+ keyword variations (aggressive search).
  
  Documentation Gap Analysis:
    1. brain docs --undocumented
    2. Get list of classes without documentation
    3. Prioritize by method_count (most complex first)
    → TRIGGER: Found undocumented classes? Create docs. Start with highest method_count.

Cognitive Triggers (NLP for AI):
  BEFORE code changes:  brain docs <topic> → found? → read first. not found? → proceed.
  AFTER code changes:   Document what changed. If no doc exists → create one.
  DURING research:      Download interesting docs → index → store insights to memory.
  ON download:          Security validated automatically. Blocked patterns logged.
  ON no results:        Split CamelCase, strip suffixes (Test, Controller), try parent context.
  ON task completion:   Run --undocumented. Found gaps? → log in task comment.
  ON no results:        Split CamelCase, strip suffixes (Test, Controller), try parent context.

Common Patterns:
  Quick search:     brain docs query --limit=3
  Deep analysis:    brain docs query --headers=2 --stats --code --keywords
  Find matches:     brain docs query --matches --limit=1
  Structure only:   brain docs --headers=2 --limit=10

Examples:
  brain docs api                      Search for "api" (limit 5)
  brain docs api auth --limit=10      Search "api" OR "auth", max 10 results
  brain docs --headers=2 --stats      List all docs with H1+H2 and stats
  brain docs api --headers=1 --code   Find "api" with headers and code blocks
  brain docs api --matches            Find "api" and show match locations
  brain docs --download=https://raw.githubusercontent.com/owner/repo/README.md
  brain docs --update                 Refresh all downloaded docs

Tip: Use -vvv for internal details (detection logic, edge cases, etc).
HELP;
    }

    protected function getHelpUltra(): string
    {
        return parent::getHelp() . <<<'HELP'

YAML Front Matter (optional but recommended):
  ---
  name: Document Title
  description: Brief description for search ranking
  url: https://example.com/docs/source.md  (required for --update)
  date: 2025-02-19
  ---
  
  Documents without YAML still work, but lack name/description in output.
  Only .md files with valid url in YAML can be updated via --update.

Update Behavior (--update):
  Scans ALL .md files in .docs/ (not just sources/), finds those with valid url
  in YAML, downloads fresh content, preserves existing YAML fields, updates date.

Output:
  JSON array with documents matching keywords. Each document includes:
  - path, name, description (from YAML front matter)
  - score (10=keyword in name, 5=in description, 1=in content)
  - headers[] (if --headers): text, start_line, end_line, snippet?
  - stats (if --stats): lines, words, size, hash, modified
  - code_blocks[] (if --code): language, start_line, end_line (detected, no "text")
  - links (if --links): internal[], external[]
  - keywords[] (if --keywords): top 10 frequent terms
  - matches[] (if --matches): keyword, line, context (50 chars around match)

Security (--download, --update):
  Downloaded content is validated before saving:
  - Max size: 5MB
  - URL scheme: http/https only (no file://, ftp://)
  - Blocked: prompt injection, script injection, event handlers
  - Warning: unusual AI-related terms flagged for review
  - If blocked: download rejected with error message

Best Practices:
  1. Always add YAML with name/description for better search ranking
  2. Use --headers=2 for technical docs to get section structure
  3. Use --matches to find exact keyword locations before reading
  4. Use --code for API docs to see which languages have examples
  5. Keep remote docs in .docs/sources/ with url for --update support

Use Cases:
  Research Workflow:
    1. Find interesting doc URL during research
    2. brain docs --download=<url> --as=topic.md
    3. Search locally: brain docs topic --headers=2
    4. Store insights to vector memory for future reference
  
  Documentation Indexing:
    1. Download multiple package docs
    2. brain docs <term> --headers=2 --code --matches
    3. Get structured overview with code examples

Common Patterns:
  Quick search:     brain docs query --limit=3
  Deep analysis:    brain docs query --headers=2 --stats --code --keywords
  Find matches:     brain docs query --matches --limit=1
  Structure only:   brain docs --headers=2 --limit=10

Examples:
  brain docs api                      Search for "api" (limit 5)
  brain docs api auth --limit=10      Search "api" OR "auth", max 10 results
  brain docs --headers=2 --stats      List all docs with H1+H2 and stats
  brain docs api --headers=1 --code   Find "api" with headers and code blocks
  brain docs api --matches            Find "api" and show match locations
  brain docs --download=https://raw.githubusercontent.com/owner/repo/README.md
  brain docs --update                 Refresh all downloaded docs

────────────────────────────────────────────────────────────────────────────────
Internal Details (for debugging/advanced use):
────────────────────────────────────────────────────────────────────────────────

Security Patterns Blocked:
  - "ignore (all) previous/above instructions/prompts/rules"
  - "system: you are now"
  - "disregard (all) previous/above"
  - "<script" tags
  - "javascript:" protocol
  - Event handlers (onload, onclick, onerror, etc)

Search Logic:
  - Keywords: OR logic, case-insensitive, partial match allowed
  - Keywords parsed: space/comma separated, empty values filtered
  - Score: name=+10, description=+5, content=+1 per keyword occurrence
  - Ranking: sorted by score DESC, then by path

Header Detection (--headers):
  - Regex: /^(#{1,6})\s*(.+)$/m
  - Level filtering: 1=H1 only, 2=H1+H2, 3=H1+H2+H3
  - end_line: line before next header of same or higher level
  - Snippet extraction: skips code blocks, empty lines, tables, YAML "---"

Code Language Detection (--code):
  Order of detection:
    1. Declared language (```json, ```php, etc)
    2. JSON: starts with { or [
    3. PHP: <?php or namespace keyword
    4. Python: def/class/import/from keywords
    5. JavaScript: function/const/let keywords or => arrow
    6. Bash: common CLI commands (git, npm, composer, etc)
    7. Unknown: excluded from output (not shown as "text")

Match Context (--matches):
  - Context: 25 chars before + keyword + 25 chars after
  - Max results: 20 unique (keyword+line) pairs
  - Line number: 1-indexed

Snippet Cleaning (--snippets):
  - Removed: YAML "---", empty lines, code blocks, table rows (|)
  - Max lines: 5 content lines
  - Max chars: 200 characters
  - Whitespace: collapsed to single space

Keyword Extraction (--keywords):
  - Stop words: 100+ common English words filtered
  - Min length: 3 characters
  - Output: top 10 by frequency

Undocumented Scan (--undocumented):
  - Scans: src/, app/, lib/, classes/ for .php files
  - Extracts: class names, FQN, public methods (excluding __magic)
  - Cross-references: checks if class name appears in any .docs/*.md
  - Sort: by method_count DESC (most complex classes first)
  - Output: {classes: [{class, fqn, file, methods[], method_count}], total_scanned, total_undocumented}
  - Use: identify documentation gaps before/after code changes

File Support:
  - Formats: .md only (html/txt mentioned but .md enforced)
  - Encoding: UTF-8 expected
  - YAML parser: Symfony Yaml component

Edge Cases:
  - No YAML header: returns [] for name/description, score from content only
  - Invalid YAML: error logged, file excluded from results
  - Empty file: excluded from results
  - No keywords: returns all files (up to --limit)
  - Duplicate paths: unique by path
  - Security block: download/update rejected with reason

HELP;
    }

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

        if ($this->option('undocumented')) {
            $this->scanUndocumented();
            return 0;
        }

        if ($this->option('as') && !$this->option('download')) {
            $this->components->error('--as requires --download');
            return ERROR;
        }

        $keywords = $this->parseKeywords($this->argument('keywords'));
        $docsDir = Brain::projectDirectory('.docs');

        if (!is_dir($docsDir)) {
            mkdir($docsDir, 0755, true);
        }

        $files = $this->getFileList($docsDir, $keywords);

        if (empty($files)) {
            $this->outputComponents()->warn('No documentation files found.');
            return 0;
        }

        $this->line(json_encode($files, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return 0;
    }

    protected function scanUndocumented(): void
    {
        $projectDir = Brain::projectDirectory();
        $docsDir = Brain::projectDirectory('.docs');

        $existingDocs = [];
        if (is_dir($docsDir)) {
            $docFiles = File::allFiles($docsDir);
            foreach ($docFiles as $file) {
                if (!str_ends_with($file->getPathname(), '.md')) {
                    continue;
                }
                $content = file_get_contents($file->getPathname());
                if ($content) {
                    preg_match_all('/\b([A-Z][a-zA-Z]+(?:Controller|Service|Repository|Model|Helper|Factory|Provider|Middleware|Command|Job|Event|Listener|Policy|Request|Resource|Exception|Master|Trait|Include|Skill|Mcp))\b/', $content, $classMatches);
                    $existingDocs = array_merge($existingDocs, $classMatches[1] ?? []);
                }
            }
        }
        $existingDocs = array_unique($existingDocs);

        $scanDirs = [];
        foreach (['src', 'app', 'lib', 'classes', 'node'] as $dir) {
            $fullPath = $projectDir . DS . $dir;
            if (is_dir($fullPath)) {
                $scanDirs[] = $fullPath;
            }
        }

        foreach (['cli', 'core'] as $package) {
            $packageSrc = $projectDir . DS . $package . DS . 'src';
            if (is_dir($packageSrc)) {
                $scanDirs[] = $packageSrc;
            }
        }

        $undocumented = [
            'classes' => [],
            'total_scanned' => 0,
            'scan_dirs' => array_map(fn($d) => str_replace($projectDir, '', $d), $scanDirs),
        ];

        $excludePatterns = [
            '/vendor/',
            '/.git/',
            '/node_modules/',
            '/.idea/',
            '/storage/',
            '/cache/',
        ];

        foreach ($scanDirs as $scanDir) {
            $phpFiles = File::allFiles($scanDir);

            foreach ($phpFiles as $file) {
                $filePath = $file->getPathname();

                $skip = false;
                foreach ($excludePatterns as $pattern) {
                    if (Str::contains($filePath, $pattern)) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) {
                    continue;
                }

                if (!str_ends_with($filePath, '.php')) {
                    continue;
                }

                $undocumented['total_scanned']++;
                $content = file_get_contents($filePath);
                if (!$content) {
                    continue;
                }

                preg_match('/namespace\s+([^;]+);/', $content, $nsMatch);
                preg_match('/^(?:abstract\s+)?class\s+(\w+)/m', $content, $classMatch);

                if ($classMatch) {
                    $className = $classMatch[1];
                    $fqn = ($nsMatch[1] ?? '') . '\\' . $className;
                    $isDocumented = in_array($className, $existingDocs);

                    if (!$isDocumented) {
                        $publicMethods = [];
                        preg_match_all('/public\s+function\s+(\w+)\s*\(/', $content, $methodMatches);

                        foreach ($methodMatches[1] ?? [] as $method) {
                            if (!Str::startsWith($method, '__')) {
                                $publicMethods[] = $method;
                            }
                        }

                        $undocumented['classes'][] = [
                            'class' => $className,
                            'fqn' => $fqn,
                            'file' => str_replace($projectDir, '', $filePath),
                            'methods' => $publicMethods,
                            'method_count' => count($publicMethods),
                        ];
                    }
                }
            }
        }

        usort($undocumented['classes'], fn($a, $b) => $b['method_count'] <=> $a['method_count']);
        $undocumented['classes'] = array_slice($undocumented['classes'], 0, (int)$this->option('limit') ?: 20);
        $undocumented['total_undocumented'] = count($undocumented['classes']);

        $this->line(json_encode($undocumented, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    protected function parseKeywords(array $input): Collection
    {
        return Str::of(implode(' ', $input))
            ->replace(' ', ',')
            ->replace(',,', ',')
            ->explode(',')
            ->filter()
            ->values();
    }

    protected function updateDocsSources(): void
    {
        $docsDir = base_path('.docs');

        if (!is_dir($docsDir)) {
            $this->components->error('.docs directory does not exist.');
            exit(ERROR);
        }

        $files = File::allFiles($docsDir);
        $updated = 0;
        $skipped = 0;

        foreach ($files as $file) {
            if (!str_ends_with($file->getPathname(), '.md')) {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            if (!$content) {
                continue;
            }

            if (!preg_match('/^---\s*(.*?)\s*---/s', $content, $matches)) {
                $skipped++;
                continue;
            }

            try {
                $yaml = Yaml::parse($matches[1]);
            } catch (\Exception $e) {
                $this->components->warn("YAML error: {$file->getRelativePathname()}");
                continue;
            }

            if (!is_array($yaml) || empty($yaml['url'])) {
                $skipped++;
                continue;
            }

            $url = $yaml['url'];
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                $this->components->warn("Invalid URL: {$file->getRelativePathname()}");
                continue;
            }

            $downloaded = @file_get_contents($url);
            if ($downloaded === false || empty($downloaded)) {
                $this->components->error("Failed: {$file->getRelativePathname()}");
                continue;
            }

            $validation = $this->validateDownloadedContent($downloaded, $url);
            if (!$validation['valid']) {
                $this->components->error("Security: {$file->getRelativePathname()} - {$validation['reason']}");
                continue;
            }
            if (!empty($validation['warnings'])) {
                $this->components->warn("Warning: {$file->getRelativePathname()} - " . implode(', ', $validation['warnings']));
            }

            $downloaded = $this->normalizeHtml($downloaded);
            $yaml['date'] = date('c');

            $yamlHeader = "---\n" . Yaml::dump($yaml, 1, 2) . "---\n\n";
            file_put_contents($file->getPathname(), $yamlHeader . $downloaded);
            $updated++;
        }

        $this->components->info("Updated: {$updated}, Skipped (no url): {$skipped}");
    }

    protected function downloadDocsSources(): void
    {
        $url = $this->option('download');

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $this->components->error('Invalid URL.');
            exit(ERROR);
        }

        $filename = $this->option('as') ?: basename(parse_url($url, PHP_URL_PATH));

        if (!preg_match('/^[\w,\s-]+\.(md|txt|html)$/i', $filename)) {
            $this->components->error('Filename must end with .md, .txt, or .html');
            exit(ERROR);
        }

        $content = @file_get_contents($url);

        if ($content === false || empty($content)) {
            $this->components->error('Download failed or empty.');
            exit(ERROR);
        }

        $validation = $this->validateDownloadedContent($content, $url);
        if (!$validation['valid']) {
            $this->components->error("Security: {$validation['reason']}");
            exit(ERROR);
        }
        if (!empty($validation['warnings'])) {
            $this->components->warn("Warning: " . implode(', ', $validation['warnings']));
        }

        $sourcesDir = base_path('.docs/sources');
        if (!is_dir($sourcesDir)) {
            mkdir($sourcesDir, 0755, true);
        }

        $content = $this->normalizeHtml($content);
        $header = "---\nname: {$filename}\ndescription: Documentation from {$url}\nurl: {$url}\ndate: " . date('c') . "\n---\n\n";

        file_put_contents($sourcesDir . DS . $filename, $header . $content);
        $this->components->info("Saved: .docs/sources/{$filename}");
    }

    protected function normalizeHtml(string $content): string
    {
        if (!Str::contains($content, ['<html', '<body', '<div', '<p', '<br', '<span'])) {
            return $content;
        }

        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($content))));
    }

    protected function validateDownloadedContent(string $content, string $url): array
    {
        $warnings = [];

        if (strlen($content) > 5 * 1024 * 1024) {
            return ['valid' => false, 'reason' => 'File too large (max 5MB)', 'warnings' => []];
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'])) {
            return ['valid' => false, 'reason' => "Invalid URL scheme: {$scheme}", 'warnings' => []];
        }

        $suspiciousPatterns = [
            '/ignore\s+(all\s+)?(previous|above|prior)\s+(instructions?|prompts?|rules?)/i' => 'prompt injection attempt',
            '/system\s*:\s*you\s+are\s+now/i' => 'system prompt override',
            '/disregard\s+(all\s+)?(previous|above)/i' => 'instruction bypass',
            '/\<\s*script\s+/i' => 'script injection',
            '/javascript\s*:/i' => 'javascript protocol',
            '/on(load|error|click|mouse)\s*=/i' => 'event handler injection',
        ];

        $contentLower = Str::lower($content);
        foreach ($suspiciousPatterns as $pattern => $type) {
            if (preg_match($pattern, $content)) {
                return ['valid' => false, 'reason' => "Detected {$type}", 'warnings' => []];
            }
        }

        $cautionPatterns = [
            '/\b(instruction|prompt|system|override|bypass)\b/i' => 'contains AI-related terms',
            '/TODO|FIXME|XXX/i' => 'contains development markers',
        ];

        foreach ($cautionPatterns as $pattern => $type) {
            if (preg_match($pattern, $content) && substr_count($contentLower, preg_match('/\(instruction|prompt\b/i', $content) ? 'instruction' : 'prompt') > 3) {
                $warnings[] = $type;
                break;
            }
        }

        return ['valid' => true, 'reason' => null, 'warnings' => $warnings];
    }

    /**
     * @param  Collection<int, string>  $keywords
     * @return array<int, array<string, mixed>>
     */
    public function getFileList(string $dir, Collection $keywords): array
    {
        $files = File::allFiles($dir);

        return collect(array_map(fn(SplFileInfo $file) => $this->processFile($file, $keywords), $files))
            ->filter()
            ->unique('path')
            ->when($keywords->isNotEmpty(), fn($c) => $c->sortByDesc('score'))
            ->values()
            ->when($this->option('limit') > 0, fn($c) => $c->take((int)$this->option('limit')))
            ->toArray();
    }

    /**
     * @param  Collection<int, string>  $keywords
     * @return array<string, mixed>|null
     */
    protected function processFile(SplFileInfo $file, Collection $keywords): ?array
    {
        if (!str_ends_with($file->getPathname(), '.md')) {
            return null;
        }

        $content = file_get_contents($file->getPathname());
        if (!$content) {
            return null;
        }

        $contentLower = Str::lower($content);

        if ($keywords->isNotEmpty() && !$keywords->contains(fn($kw) => Str::contains($contentLower, Str::lower($kw)))) {
            return null;
        }

        $result = [
            'path' => '.docs' . DS . $file->getRelativePathname(),
        ];

        $result = array_merge($result, $this->parseYamlHeader($content, $file->getRelativePathname()));
        $result['score'] = $keywords->isNotEmpty() ? $this->calculateScore($keywords, $result, $contentLower) : 0;

        if ($this->option('stats')) {
            $result['stats'] = $this->extractStats($content, $file);
        }

        if ($this->option('headers') > 0) {
            $headers = $this->parseMarkdownHeaders($content, (int)$this->option('headers'));
            if (!empty($headers)) {
                $result['headers'] = $headers;
            }
        }

        if ($this->option('code')) {
            $codeBlocks = $this->extractCodeBlocks($content);
            if (!empty($codeBlocks)) {
                $result['code_blocks'] = $codeBlocks;
            }
        }

        if ($this->option('links')) {
            $links = $this->extractLinks($content);
            if (!empty($links['internal']) || !empty($links['external'])) {
                $result['links'] = $links;
            }
        }

        if ($this->option('keywords')) {
            $keywordsExtracted = $this->extractKeywords($content);
            if (!empty($keywordsExtracted)) {
                $result['keywords'] = $keywordsExtracted;
            }
        }

        if ($this->option('matches') && $keywords->isNotEmpty()) {
            $matches = $this->extractMatches($content, $keywords);
            if (!empty($matches)) {
                $result['matches'] = $matches;
            }
        }

        return $result;
    }

    protected function parseYamlHeader(string $content, string $filename): array
    {
        if (!preg_match('/^---\s*(.*?)\s*---/s', $content, $matches)) {
            return [];
        }

        try {
            $parsed = Yaml::parse($matches[1]);
            return is_array($parsed) ? $parsed : [];
        } catch (\Exception $e) {
            if (Brain::isDebug()) {
                dd($e);
            }
            $this->components->error("YAML error: {$filename}");
            exit(ERROR);
        }
    }

    /**
     * @param  Collection<int, string>  $keywords
     */
    protected function calculateScore(Collection $keywords, array $result, string $contentLower): int
    {
        $score = 0;

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

        return $score;
    }

    protected function extractStats(string $content, SplFileInfo $file): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $words = str_word_count(strip_tags($content));

        return [
            'lines' => count($lines),
            'words' => $words,
            'size' => strlen($content),
            'hash' => substr(md5($content), 0, 8),
            'modified' => date('c', $file->getMTime()),
        ];
    }

    protected function extractCodeBlocks(string $content): array
    {
        preg_match_all('/```(\w*)\n(.*?)```/s', $content, $matches, PREG_OFFSET_CAPTURE);

        if (empty($matches[0])) {
            return [];
        }

        $lineOffsets = $this->buildLineOffsets($content);
        $blocks = [];

        foreach ($matches[0] as $index => $match) {
            $declaredLang = $matches[1][$index][0];
            $codeContent = $matches[2][$index][0];
            $startLine = $this->offsetToLine($match[1], $lineOffsets);
            $endLine = $this->offsetToLine($match[1] + strlen($match[0]), $lineOffsets);

            $language = $this->detectCodeLanguage($declaredLang, $codeContent);

            if ($language !== null) {
                $blocks[] = [
                    'language' => $language,
                    'start_line' => $startLine,
                    'end_line' => $endLine,
                ];
            }
        }

        return $blocks;
    }

    protected function detectCodeLanguage(string $declared, string $code): ?string
    {
        if (!empty($declared)) {
            return $declared;
        }

        $code = trim($code);

        if ($code === '') {
            return null;
        }

        if (preg_match('/^\s*\{[\s\S]*\}\s*$/', $code) || preg_match('/^\s*\[[\s\S]*\]\s*$/', $code)) {
            return 'json';
        }

        if (preg_match('/^\s*<\?php/', $code) || preg_match('/^\s*namespace\s+/', $code)) {
            return 'php';
        }

        if (preg_match('/^\s*(def|class|import|from)\s+\w+/', $code)) {
            return 'python';
        }

        if (preg_match('/^\s*function\s+\w+/', $code) || preg_match('/^\s*(const|let|var)\s+\w+/', $code) || preg_match('/=>\s*[\{\[]/', $code)) {
            return 'javascript';
        }

        if (preg_match('/^\s*function\s+\w+\s*\(/', $code) && preg_match('/:\s*(string|int|float|bool|array|void)/', $code)) {
            return 'php';
        }

        if (preg_match('/^\s*(git|npm|yarn|pip|composer|php|docker|kubectl|curl|wget|chmod|mkdir|cd|ls|rm|cp|mv|echo|cat)\s+/', $code)) {
            return 'bash';
        }

        if (preg_match('/^\s*\/\//', $code) || preg_match('/^\s*#/', $code)) {
            return null;
        }

        return null;
    }

    protected function extractLinks(string $content): array
    {
        $internal = [];
        $external = [];

        preg_match_all('/\[([^\]]+)\]\(([^)]+)\)/', $content, $matches);

        foreach ($matches[2] as $url) {
            if (Str::startsWith($url, ['http://', 'https://'])) {
                $external[] = $url;
            } elseif (Str::startsWith($url, ['#', './', '../', '/'])) {
                $internal[] = $url;
            }
        }

        return [
            'internal' => array_values(array_unique($internal)),
            'external' => array_values(array_unique($external)),
        ];
    }

    protected function extractKeywords(string $content): array
    {
        $text = strip_tags($content);
        $text = preg_replace('/[^\w\s]/', ' ', $text);
        $text = Str::lower($text);

        $stopWords = ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'from', 'is', 'are', 'was', 'were', 'be', 'been', 'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should', 'may', 'might', 'must', 'shall', 'can', 'this', 'that', 'these', 'those', 'it', 'its', 'as', 'if', 'then', 'than', 'so', 'such', 'no', 'not', 'only', 'same', 'into', 'also', 'just', 'which', 'who', 'what', 'when', 'where', 'why', 'how', 'all', 'each', 'every', 'both', 'few', 'more', 'most', 'other', 'some', 'any', 'you', 'your', 'we', 'our', 'they', 'their', 'them', 'he', 'she', 'his', 'her', 'him', 'i', 'me', 'my', 'about', 'after', 'before', 'above', 'below', 'between', 'under', 'again', 'further', 'once', 'here', 'there', 'up', 'down', 'out', 'over', 'own'];

        $words = array_filter(explode(' ', $text), fn($word) => strlen($word) > 2 && !in_array($word, $stopWords));
        $frequency = array_count_values($words);
        arsort($frequency);

        return array_slice(array_keys($frequency), 0, 10);
    }

    /**
     * @param  Collection<int, string>  $keywords
     */
    protected function extractMatches(string $content, Collection $keywords): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $matches = [];

        foreach ($lines as $lineNum => $line) {
            $lineLower = Str::lower($line);

            foreach ($keywords as $keyword) {
                $kwLower = Str::lower($keyword);

                if (Str::contains($lineLower, $kwLower)) {
                    $context = $this->extractMatchContext($line, $keyword);

                    $matches[] = [
                        'keyword' => $keyword,
                        'line' => $lineNum + 1,
                        'context' => $context,
                    ];
                }
            }
        }

        $matches = collect($matches)
            ->unique(fn($m) => $m['keyword'] . '-' . $m['line'])
            ->take(20)
            ->values()
            ->toArray();

        return $matches;
    }

    protected function extractMatchContext(string $line, string $keyword): string
    {
        $line = trim($line);
        $kwLower = Str::lower($keyword);
        $pos = stripos($line, $keyword);

        if ($pos === false) {
            return Str::limit($line, 80);
        }

        $start = max(0, $pos - 25);
        $length = strlen($keyword) + 50;

        $context = substr($line, $start, $length);
        $context = preg_replace('/\s+/', ' ', trim($context));

        if ($start > 0) {
            $context = '...' . $context;
        }
        if ($start + $length < strlen($line)) {
            $context = $context . '...';
        }

        return $context;
    }

    protected function parseMarkdownHeaders(string $content, int $maxLevel): array
    {
        preg_match_all('/^(#{1,6})\s*(.+)$/m', $content, $matches, PREG_OFFSET_CAPTURE);

        if (empty($matches[0])) {
            return [];
        }

        $lineOffsets = $this->buildLineOffsets($content);
        $totalLines = count($lineOffsets);
        $allHeaders = [];
        $lines = preg_split('/\r\n|\r|\n/', $content);

        foreach ($matches[0] as $index => $fullMatch) {
            $level = strlen($matches[1][$index][0]);
            $startLine = $this->offsetToLine($matches[0][$index][1], $lineOffsets);

            $header = [
                'text' => trim($matches[2][$index][0]),
                'level' => $level,
                'start_line' => $startLine,
            ];

            if ($this->option('snippets')) {
                $snippet = $this->extractHeaderSnippet($lines, $startLine, $totalLines);
                if (!empty($snippet)) {
                    $header['snippet'] = $snippet;
                }
            }

            $allHeaders[] = $header;
        }

        return $this->calculateHeaderEndLines($allHeaders, $totalLines, $maxLevel);
    }

    /**
     * @param  array<int, string>  $lines
     */
    protected function extractHeaderSnippet(array $lines, int $startLine, int $totalLines): string
    {
        $snippetLines = [];
        $inCodeBlock = false;

        for ($i = $startLine; $i < $totalLines && count($snippetLines) < 50; $i++) {
            $line = $lines[$i] ?? '';

            if (Str::startsWith($line, '```')) {
                $inCodeBlock = !$inCodeBlock;
                continue;
            }

            if ($inCodeBlock) {
                continue;
            }

            if (Str::startsWith($line, '#') && count($snippetLines) > 0) {
                break;
            }

            if (Str::startsWith($line, '#')) {
                continue;
            }

            $trimmed = trim($line);
            if (empty($trimmed) || $trimmed === '---' || Str::startsWith($trimmed, '|')) {
                continue;
            }

            $snippetLines[] = $trimmed;

            if (count($snippetLines) >= 5) {
                break;
            }
        }

        $snippet = implode(' ', $snippetLines);
        $snippet = preg_replace('/\s+/', ' ', $snippet);

        return Str::limit(trim($snippet), 200);
    }

    protected function buildLineOffsets(string $content): array
    {
        $offsets = [];
        $currentOffset = 0;
        $lines = preg_split('/\r\n|\r|\n/', $content);

        foreach ($lines as $line) {
            $offsets[] = $currentOffset;
            $currentOffset += strlen($line) + 1;
        }

        return $offsets;
    }

    protected function offsetToLine(int $offset, array $lineOffsets): int
    {
        $line = 1;
        foreach ($lineOffsets as $lineNumber => $lineOffset) {
            if ($offset >= $lineOffset) {
                $line = $lineNumber + 1;
            } else {
                break;
            }
        }
        return $line;
    }

    /**
     * @param  array<int, array{text: string, level: int, start_line: int, snippet?: string}>  $allHeaders
     * @return array<int, array{text: string, start_line: int, end_line: int, snippet?: string}>
     */
    protected function calculateHeaderEndLines(array $allHeaders, int $totalLines, int $maxLevel): array
    {
        $result = [];
        $headerCount = count($allHeaders);

        foreach ($allHeaders as $index => $header) {
            if ($header['level'] > $maxLevel) {
                continue;
            }

            $endLine = $totalLines;

            for ($nextIndex = $index + 1; $nextIndex < $headerCount; $nextIndex++) {
                if ($allHeaders[$nextIndex]['level'] <= $header['level']) {
                    $endLine = $allHeaders[$nextIndex]['start_line'] - 1;
                    break;
                }
            }

            $item = [
                'text' => $header['text'],
                'start_line' => $header['start_line'],
                'end_line' => $endLine,
            ];

            if (isset($header['snippet'])) {
                $item['snippet'] = $header['snippet'];
            }

            $result[] = $item;
        }

        return $result;
    }
}
