<?php

declare(strict_types=1);

namespace BrainCLI\Console\Commands;

use BrainCLI\Console\Traits\HelpersTrait;
use BrainCLI\Exceptions\CommandTerminatedException;
use BrainCLI\Services\Docs\ContentScorer;
use BrainCLI\Services\Docs\DocScaffolder;
use BrainCLI\Services\Docs\DocsDirectoryResolver;
use BrainCLI\Services\Docs\DriftDetector;
use BrainCLI\Services\Docs\FreshnessResolver;
use BrainCLI\Services\Docs\MarkdownParser;
use BrainCLI\Services\Docs\SecurityValidator;
use BrainCLI\Services\Docs\TrustResolver;
use BrainCLI\Services\Docs\UndocumentedScanner;
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
        {--exact= : Exact phrase search (case-insensitive, use --strict for case-sensitive)}
        {--strict : Make --exact case-sensitive}
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
        {--validate : Validate documentation files for required fields and quality}
        {--scaffold= : Scaffold doc files for undocumented classes (or specific class name)}
        {--global : Search all .docs/ folders in project subdirectories}
        {--freshness= : Include only docs modified within N days (0 = no filter)}
        {--trust= : Minimum trust level: low|med|high}
    ';

    protected $description = 'Index and search .docs folder with rich metadata extraction';

    public function __construct(
        protected MarkdownParser $markdownParser,
        protected ContentScorer $contentScorer,
        protected SecurityValidator $securityValidator,
        protected UndocumentedScanner $undocumentedScanner,
        protected DocScaffolder $docScaffolder,
        protected DriftDetector $driftDetector,
        protected DocsDirectoryResolver $docsDirectoryResolver,
        protected FreshnessResolver $freshnessResolver,
        protected TrustResolver $trustResolver,
    ) {
        parent::__construct();
    }

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
  brain docs --exact="class not found"  Exact phrase search
  brain docs --exact="Error" --strict   Case-sensitive exact
  brain docs --update         Refresh downloaded docs
  brain docs --undocumented   Find classes without docs
  brain docs --scaffold       Scaffold docs for ALL undocumented classes
  brain docs --scaffold=Name  Scaffold doc for specific class
  brain docs --validate       Validate docs quality
  brain docs api --global     Search ALL .docs/ in subdirectories

Formats: .md, .mdx

Cognitive Triggers:
  Using --download?     → 3-layer security validation runs. See -vv for details.
  Using --matches?      → Need to know WHERE keywords appear. See -vv.
  Using --undocumented? → Polyglot scan (PHP/JS/TS/Python/Go). Prioritize by method_count.
  Using --scaffold?     → Auto-generate doc stubs. Never overwrites existing files.
  Using --validate?     → Fix errors first, then warnings.
  Using --global?       → Mono-repo: finds .docs/ in subdirs (depth 1-3). Works with search/validate/update.
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

Fallback (for legacy docs without YAML):
  - Name: First header — ATX (#) or setext (underline ===, ---)
    Scoring: H1=+7, H2=+6, H3=+5, etc.
  - Description: First paragraph (≥5 words, +3 score)
  - Indicators: _auto_name (header level), _auto_description (true)

Output:
  JSON array with: path, name, description, score
  Optional: headers[], stats, code_blocks[], links, keywords[], matches[]

Scoring:
  YAML name match: +10, YAML description: +5
  Auto name: H1=+7 to H6=+2, Auto description: +3
  Content: frequency-based log2 scaling (1 match=1pt, 7=3pt, 50+=~6pt, max=10pt)

Search Modes:
  Keywords (default):    brain docs api auth        → OR logic, case-insensitive
  Exact phrase:          brain docs --exact="class not found"
  Case-sensitive exact:  brain docs --exact="Error" --strict

Headers (--headers):
  ATX (# Title) and setext (Title\n===) supported. Code-block aware.

Code Blocks (--code):
  30+ languages detected. Aliases: js→javascript, ts→typescript, py→python, sh→bash
  Undeclared+undetected blocks included without language key (not dropped).

Security (--download, --update):
  3-layer: unicode normalization → 15+ injection patterns → base64 decode+rescan

Undocumented (--undocumented):
  Polyglot scanner: PHP, JavaScript/TypeScript, Python, Go
  Configurable directories via DOCS_SCAN_DIRS env variable

Validation (--validate):
  Critical errors: missing YAML, missing name
  Warnings: missing description, short description, empty content, no H1, duplicate names
  Drift detection: cross-references ### method headers under ## Methods with actual source code
    - Requires > **Source:** `path` line (scaffold template format)
    - Detects: methods documented but renamed/deleted in source code
    - Skips: docs without ## Methods section or Source reference

Scaffold (--scaffold):
  brain docs --scaffold              Scaffold ALL undocumented classes (respects --limit)
  brain docs --scaffold=ClassName    Scaffold specific class
  Output: JSON with created/skipped arrays, never overwrites existing files
  Template: YAML front matter + H1 + FQN + Source + Overview + Methods stubs

Global Search (--global):
  Discovers all .docs/ directories at depth 1-3 from project root.
  Excludes: vendor/, node_modules/, .git/, .idea/, storage/, cache/, dist/, build/
  Paths in output: relative to project root (e.g., packages/core/.docs/file.md)
  Works with: search, --validate, --update
  Does NOT affect: --download, --undocumented, --scaffold (these always use root .docs/)

File Support:
  Formats: .md, .mdx (MDX with React/JSX components supported)

Examples:
  brain docs api --headers=2 --stats    Full metadata
  brain docs api --matches              Find where "api" appears
  brain docs --exact="Class Not Found"  Find exact phrase
  brain docs --download=https://...     Download external doc
  brain docs --scaffold --limit=5       Scaffold up to 5 undocumented classes
  brain docs --validate                 Check docs quality
  brain docs api --global               Search all .docs/ in subdirectories
  brain docs --validate --global        Validate all .docs/ across project

Cognitive Triggers:
  Before writing code?    → Search docs FIRST. Avoid duplicate work.
  Found interesting URL?  → Download, index, store to vector memory.
  Task mentions feature?  → brain docs feature --headers=2 --code
  Found gaps?             → brain docs --scaffold to auto-generate stubs.
  Before commit?          → Run --validate, fix critical errors.
  Mono-repo project?      → Use --global for cross-subproject doc search.

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
  Only .md/.mdx files with valid url in YAML can be updated via --update.

Update Behavior (--update):
  Scans ALL .md/.mdx files in .docs/ (not just sources/), finds those with valid url
  in YAML, downloads fresh content, preserves existing YAML fields, updates date.

Output:
  JSON array with documents matching keywords. Each document includes:
  - path, name, description (from YAML front matter)
  - score (frequency-based: YAML name=+10, desc=+5, content=log2 scaled, max 10pt/keyword)
  - headers[] (if --headers): text, start_line, end_line, snippet?
  - stats (if --stats): lines, words, size, hash, modified
  - code_blocks[] (if --code): language, start_line, end_line (or no language key if undetected)
  - links (if --links): internal[], external[]
  - keywords[] (if --keywords): top 10 frequent terms
  - matches[] (if --matches): keyword, line, context (50 chars around match)

Header Detection (--headers):
  - ATX headers: # H1, ## H2, ### H3 (code-block aware — headers inside ``` ignored)
  - Setext headers: Title\n==== (H1), Title\n---- (H2) with YAML/table guards
  - Level filtering: --headers=1 (H1 only), =2 (H1+H2), =3 (H1+H2+H3)

Code Language Detection (--code):
  - 30+ languages via CodeLanguage enum with 60+ aliases (js→javascript, ts→typescript, etc.)
  - Declared language trusted as-is; undeclared uses heuristic rules
  - Undetected blocks included WITHOUT language key (never dropped)

Security (--download, --update):
  3-layer validation pipeline:
    Layer 1: Unicode normalization (NFKC + homoglyph map + zero-width stripping + HTML entity decode)
    Layer 2: 15+ injection patterns (prompt injection, script/protocol, event handlers, iframes, forms)
    Layer 3: Base64 detection (40+ char strings decoded and re-scanned through Layer 2)

Undocumented Scan (--undocumented):
  Polyglot scanner: PHP, JavaScript/TypeScript, Python, Go
  - PHP: class, abstract class, public methods (excluding __magic)
  - JS/TS: class, export class, export function
  - Python: class, def (excluding _private)
  - Go: type struct, func
  Configurable directories: DOCS_SCAN_DIRS env (comma-separated), fallback: src/,app/,lib/,classes/,node/
  Sort: method_count DESC — most complex classes first (prioritize tech debt)

Scaffold (--scaffold):
  Generates documentation stubs for undocumented classes.
  - brain docs --scaffold              Scaffold ALL undocumented (respects --limit, default 20)
  - brain docs --scaffold=ClassName    Scaffold specific class (scans unlimited to find it)
  - Never overwrites existing .docs/*.md files (skip with warning)
  - Template: YAML front matter → H1 → FQN/Source → Overview → Methods stubs
  - Output: JSON {created: [], skipped: [], total_created, total_skipped}
  - Workflow: --undocumented → review → --scaffold → --validate → commit

MDX Support:
  Both .md and .mdx files are indexed, searched, and validated.
  MDX files may contain JSX components (<Component />) — these are treated as regular
  non-header, non-code-block content. Header and code block parsing is unaffected.

Global Search (--global):
  Discovers all .docs/ directories at depth 1-3 from project root.
  Useful for mono-repo projects with multiple subprojects, each having their own .docs/.
  Excludes package directories: vendor/, node_modules/, .git/, .idea/, storage/, cache/, dist/, build/
  Paths in output: relative to project root (e.g., packages/core/.docs/api.md)
  Works with: search, --validate, --update
  Does NOT affect: --download (always saves to root .docs/sources/),
                   --undocumented, --scaffold (scan source code, not .docs/)
  Results from all directories are merged, deduplicated by path, and re-sorted by score.

Best Practices:
  1. Always add YAML with name/description for better search ranking
  2. Use --headers=2 for technical docs to get section structure
  3. Use --matches to find exact keyword locations before reading
  4. Use --code for API docs to see which languages have examples
  5. Keep remote docs in .docs/sources/ with url for --update support
  6. Use --global for mono-repo projects to search docs across all subprojects

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
    2. Get polyglot list of classes/structs without documentation
    3. Prioritize by method_count (most complex first)
    4. brain docs --scaffold to auto-generate stubs
    5. brain docs --validate to verify generated files
    → TRIGGER: Found undocumented classes? Run --scaffold. Then fill TODOs.

  Mono-repo Documentation:
    1. brain docs api --global → search across all subproject .docs/
    2. brain docs --validate --global → validate all docs in project tree
    3. brain docs --update --global → refresh downloaded docs across subprojects
    → TRIGGER: Multi-repo project? Always add --global for complete coverage.

Cognitive Triggers (NLP for AI):
  BEFORE code changes:  brain docs <topic> → found? → read first. not found? → proceed.
  AFTER code changes:   Document what changed. If no doc exists → create one.
  DURING research:      Download interesting docs → index → store insights to memory.
  ON download:          3-layer security validated automatically. Blocked patterns logged.
  ON no results:        Split CamelCase, strip suffixes (Test, Controller), try parent context.
  ON task completion:   Run --undocumented. Found gaps? → log in task comment.
  ON mono-repo:         Use --global to search all .docs/ directories.

Common Patterns:
  Quick search:     brain docs query --limit=3
  Deep analysis:    brain docs query --headers=2 --stats --code --keywords
  Find matches:     brain docs query --matches --limit=1
  Structure only:   brain docs --headers=2 --limit=10
  Global search:    brain docs query --global --limit=10

Examples:
  brain docs api                      Search for "api" (limit 5)
  brain docs api auth --limit=10      Search "api" OR "auth", max 10 results
  brain docs --headers=2 --stats      List all docs with H1+H2 and stats
  brain docs api --headers=1 --code   Find "api" with headers and code blocks
  brain docs api --matches            Find "api" and show match locations
  brain docs --download=https://raw.githubusercontent.com/owner/repo/README.md
  brain docs --update                 Refresh all downloaded docs
  brain docs --scaffold               Scaffold docs for all undocumented classes
  brain docs --scaffold=UserService   Scaffold doc for specific class
  brain docs api --global             Search all .docs/ in project subdirectories
  brain docs --validate --global      Validate docs across all subprojects
  brain docs --update --global        Update downloaded docs in all .docs/ folders

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
  Only .md/.mdx files with valid url in YAML can be updated via --update.

Update Behavior (--update):
  Scans ALL .md/.mdx files in .docs/ (not just sources/), finds those with valid url
  in YAML, downloads fresh content, preserves existing YAML fields, updates date.

Output:
  JSON array with documents matching keywords. Each document includes:
  - path, name, description (from YAML front matter)
  - score (10=keyword in name, 5=in description, frequency-based content scoring)
  - headers[] (if --headers): text, start_line, end_line, snippet?
  - stats (if --stats): lines, words, size, hash, modified
  - code_blocks[] (if --code): language, start_line, end_line (detected or no language key)
  - links (if --links): internal[], external[]
  - keywords[] (if --keywords): top 10 frequent terms
  - matches[] (if --matches): keyword, line, context (50 chars around match)

Search Modes:
  Keywords (default):
    - OR logic: brain docs api auth → matches "api" OR "auth"
    - Case-insensitive: API = api = Api
    - Scoring: YAML name=+10, YAML desc=+5, auto name=+2-7, auto desc=+3
    - Content scoring: frequency-based with log2 scaling (1 match=1pt, 7=3pt, 50+=~6pt, max=10pt)

  Exact phrase (--exact):
    - Matches entire phrase as-is: brain docs --exact="class not found"
    - Case-insensitive by default
    - Use --strict for case-sensitive: brain docs --exact="Error" --strict
    - Can combine with keywords for refinement

Validation (--validate):
  Returns JSON with validation results for all .md files in .docs/

  Critical errors (document invalid):
    - Missing YAML front matter (must start with ---)
    - Missing required field: name

  Warnings (quality issues):
    - Missing recommended field: description
    - Description too short (< 10 characters)
    - Empty content after YAML
    - No H1 header in document
    - Duplicate name across documents
    - Documentation drift: method documented but renamed/deleted in source code

  Drift detection (during --validate):
    - Cross-references ### method headers under ## Methods with actual source code
    - Requires > **Source:** `path` line in document (scaffold template format)
    - Detects: methods documented but renamed/deleted in source code
    - Skips: docs without ## Methods section or Source reference

  Output: {documents: [{path, valid, errors[], warnings[]}], summary: {total, valid, invalid, warnings}}

Security (--download, --update):
  3-layer validation:
    Layer 1: Unicode normalization (NFKC + zero-width stripping + HTML entity decode)
    Layer 2: 15+ injection patterns (prompt, script, protocol, element, event handlers)
    Layer 3: Base64 detection (40+ char strings decoded and re-scanned)

  Blocked patterns:
    - "ignore (all) previous/above instructions/prompts/rules"
    - "forget instructions/prompts/rules/context"
    - "system: you are now" / "new system prompt"
    - "you are now a/an/the" / "act as" / "pretend to be"
    - "<script", "javascript:", "data:text/html", "vbscript:"
    - Event handlers (onload, onclick, onerror, onfocus, etc)
    - <iframe>, <embed>, <object>, <form action=>, <meta http-equiv=>
    - Base64-encoded injection payloads

Best Practices:
  1. Always add YAML with name/description for better search ranking
  2. Use --headers=2 for technical docs to get section structure
  3. Use --matches to find exact keyword locations before reading
  4. Use --code for API docs to see which languages have examples
  5. Keep remote docs in .docs/sources/ with url for --update support
  6. Use --global in mono-repo projects for cross-subproject coverage

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

  Mono-repo Documentation:
    1. brain docs api --global → search across all subproject .docs/
    2. brain docs --validate --global → validate all docs in project tree
    3. brain docs --update --global → refresh downloaded docs across subprojects

Common Patterns:
  Quick search:     brain docs query --limit=3
  Deep analysis:    brain docs query --headers=2 --stats --code --keywords
  Find matches:     brain docs query --matches --limit=1
  Structure only:   brain docs --headers=2 --limit=10
  Validate docs:    brain docs --validate
  Exact phrase:     brain docs --exact="class not found"
  Scaffold all:     brain docs --scaffold
  Scaffold one:     brain docs --scaffold=ClassName
  Global search:    brain docs query --global

Examples:
  brain docs api                      Search for "api" (limit 5)
  brain docs api auth --limit=10      Search "api" OR "auth", max 10 results
  brain docs --exact="Class Not Found"              Find exact phrase (case-insensitive)
  brain docs --exact="Error" --strict               Find "Error" with exact case
  brain docs --headers=2 --stats      List all docs with H1+H2 and stats
  brain docs api --headers=1 --code   Find "api" with headers and code blocks
  brain docs api --matches            Find "api" and show match locations
  brain docs --download=https://raw.githubusercontent.com/owner/repo/README.md
  brain docs --update                 Refresh all downloaded docs
  brain docs --validate               Check all docs for errors and warnings
  brain docs --scaffold               Scaffold docs for all undocumented classes
  brain docs --scaffold=UserService   Scaffold doc for specific class
  brain docs api --global             Search all .docs/ in project subdirectories
  brain docs --validate --global      Validate docs across all subprojects

Note: Legacy docs without YAML get auto name/description from headers/paragraphs.

────────────────────────────────────────────────────────────────────────────────
Internal Details (for debugging/advanced use):
────────────────────────────────────────────────────────────────────────────────

Search Logic:
  - Keywords: OR logic, case-insensitive, partial match allowed
  - Keywords parsed: space/comma separated, empty values filtered
  - Score weights:
    * YAML name: +10, auto name: H1=+7, H2=+6, H3=+5, H4=+4, H5=+3, H6=+2
    * YAML description: +5, auto description (first paragraph): +3
    * Content frequency: min(ceil(log2(count + 1)), 10) per keyword
  - Ranking: sorted by score DESC, then by path
  - Exact phrase (--exact): entire phrase must appear in document
  - Exact case-sensitivity: default insensitive, --strict enables case-sensitive
  - Exact + Keywords: both conditions must match (AND logic)

Header Detection (--headers):
  - ATX headers: /^(#{1,6})\s+(.+)$/ (code-block aware)
  - Setext headers: Title\n==== (H1), Title\n---- (H2)
  - Setext guards: previous line not empty/header/table/separator/underline
  - Level filtering: 1=H1 only, 2=H1+H2, 3=H1+H2+H3
  - end_line: line before next header of same or higher level
  - Snippet extraction: skips code blocks, empty lines, tables, YAML "---"

Code Language Detection (--code):
  Order of detection:
    1. Declared language (```json, ```php, etc) — resolved via CodeLanguage enum
    2. Heuristic rules (30+ languages, ordered by specificity)
    3. Undeclared + undetected: block included WITHOUT language key

  Language aliases: js→javascript, ts→typescript, py→python, sh→bash, etc.

Match Context (--matches):
  - Context: 25 chars before + keyword + 25 chars after
  - Max results: 20 unique (keyword+line) pairs
  - Line number: 1-indexed

Snippet Cleaning (--snippets):
  - Removed: YAML "---", empty lines, code blocks, table rows (|), setext underlines
  - Max lines: 5 content lines
  - Max chars: 200 characters
  - Whitespace: collapsed to single space

Keyword Extraction (--keywords):
  - Stop words: 100+ common English words filtered
  - Min length: 3 characters
  - Output: top 10 by frequency

Undocumented Scan (--undocumented):
  - Polyglot: PHP, JavaScript/TypeScript, Python, Go
  - Scans: src/, app/, lib/, classes/, node/, cli/src/, core/src/ (configurable via DOCS_SCAN_DIRS env)
  - Extracts: classes/structs, public methods/functions
  - Cross-references: checks if class name appears in any .docs/*.md or .docs/*.mdx
  - Sort: by method_count DESC (most complex classes first)
  - Output: {classes: [{class, fqn, file, methods[], method_count}], total_scanned, total_undocumented}

Scaffold (--scaffold):
  - Invokes UndocumentedScanner internally, then generates .docs/*.md files
  - Template structure: YAML front matter → H1 → FQN/Source blockquote → Overview → Methods
  - YAML fields: name (class name), description ("API reference for ..."), type ("api"), date
  - Methods: each public method gets ### heading + <!-- TODO --> placeholder
  - File naming: .docs/{ClassName}.md
  - Safety: never overwrites existing files (skip + reason in output)
  - --scaffold without value: scaffold ALL undocumented (respects --limit)
  - --scaffold=Name: scaffold specific class (case-insensitive match, scans unlimited)
  - Output: {created: [{class, path, methods}], skipped: [{class, path, reason}], total_created, total_skipped}

Drift Detection (during --validate):
  - Cross-references ### method headers under ## Methods with actual source code
  - Requires > **Source:** `path` line in document (scaffold template format)
  - Detects: methods documented but renamed/deleted in source code (stale methods)
  - Skips: docs without ## Methods section or Source reference (non-scaffold docs)
  - Language support: PHP, JavaScript/TypeScript, Python, Go (same as --undocumented)
  - Method extraction: same regex patterns as UndocumentedScanner (excluding __magic and _private)
  - Output: warnings[] with "Documentation drift: method 'X' documented but not found in source (path)"

File Support:
  - Formats: .md, .mdx (MDX with React/JSX components)
  - MDX handling: JSX tags treated as non-header, non-code-block content. Parser ignores them.
  - Encoding: UTF-8 expected
  - YAML parser: Symfony Yaml component

Global Search (--global):
  - Discovers all .docs/ directories at depth 1-3 from project root using Symfony Finder
  - Finder config: ignoreDotFiles(false) (required for .docs), ignoreVCS(false), depth 1-3
  - Excluded directories: vendor, node_modules, .git, .idea, storage, cache, dist, build, __pycache__, .venv
  - Root .docs/ always listed first, subdirectory results sorted alphabetically by prefix
  - Path format: prefix + DS + relative path (e.g., packages/core/.docs/api.md)
  - Works with: search (merged+re-sorted+re-limited), --validate, --update
  - Does NOT affect: --download, --undocumented, --scaffold
  - Edge: root .docs/ missing but subdirs exist → works. No dirs at all → warning.
  - Results from all directories merged, deduplicated by path, re-sorted by score, then limited.

Edge Cases:
  - No YAML header: returns [] for name/description, score from content only
  - Invalid YAML: warning logged, file indexed without metadata (auto_name/auto_description fallback)
  - Empty file: excluded from results
  - No keywords: returns all files (up to --limit)
  - Duplicate paths: unique by path
  - Security block: download/update rejected with reason
  - MDX JSX components: ignored by header/code block parsers, treated as regular text

HELP;
    }

    public function handle(): int
    {
        return \BrainCLI\Console\Kernel\CommandKernel::run(
            fn () => $this->executeCommand(),
            'docs',
        );
    }

    protected function executeCommand(): int
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

        if ($this->option('validate')) {
            $this->validateDocs();
            return 0;
        }

        if ($this->hasScaffoldOption()) {
            $this->scaffoldDocs();
            return 0;
        }

        if ($this->option('as') && !$this->option('download')) {
            $this->components->error('--as requires --download');
            return ERROR;
        }

        $keywords = $this->parseKeywords($this->argument('keywords'));
        $isGlobal = (bool) $this->option('global');
        $docsDirs = $this->docsDirectoryResolver->resolve($isGlobal);

        if (empty($docsDirs) && !$isGlobal) {
            $rootDocs = Brain::projectDirectory('.docs');
            mkdir($rootDocs, 0755, true);
            $docsDirs = [['dir' => $rootDocs, 'prefix' => '.docs']];
        }

        if (empty($docsDirs)) {
            $this->outputComponents()->warn('No .docs/ directories found.');
            return 0;
        }

        foreach ($docsDirs as $docsDir) {
            $this->freshnessResolver->warmDirectory($docsDir['dir']);
        }

        $allFiles = [];
        foreach ($docsDirs as $docsDir) {
            $dirFiles = $this->getFileList($docsDir['dir'], $keywords, $docsDir['prefix']);
            $allFiles = array_merge($allFiles, $dirFiles);
        }

        $files = collect($allFiles)
            ->unique('path')
            ->when($this->option('freshness') !== null, function ($c) {
                $maxDays = (int) $this->option('freshness');

                return $c->filter(fn ($r) => ($r['freshness']['days_ago'] ?? 0) <= $maxDays);
            })
            ->when($this->option('trust') !== null, function ($c) {
                $trustOrder = ['low' => 0, 'med' => 1, 'high' => 2];
                $minLevel = $trustOrder[(string) $this->option('trust')] ?? 0;

                return $c->filter(fn ($r) => ($trustOrder[$r['trust']['level'] ?? 'low'] ?? 0) >= $minLevel);
            })
            ->when($keywords->isNotEmpty(), fn ($c) => $c->sort(
                fn ($a, $b) => ($b['score'] <=> $a['score']) ?: strcmp($a['path'], $b['path']),
            ))
            ->values()
            ->when($this->option('limit') > 0, fn ($c) => $c->take((int) $this->option('limit')))
            ->toArray();

        if (empty($files)) {
            $this->outputComponents()->warn('No documentation files found.');
            return 0;
        }

        $this->line(json_encode($files, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return 0;
    }

    protected function scanUndocumented(): void
    {
        $limit = (int) $this->option('limit') ?: 20;
        $result = $this->undocumentedScanner->scan($limit);

        $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    protected function scaffoldDocs(): void
    {
        $scaffoldValue = $this->option('scaffold');
        $limit = (int) $this->option('limit') ?: 20;

        // When --scaffold without value, scan all; when --scaffold=Name, scan unlimited to find the class
        $scanLimit = (is_string($scaffoldValue) && $scaffoldValue !== '') ? 0 : $limit;
        $scanResult = $this->undocumentedScanner->scan($scanLimit);
        $classes = $scanResult['classes'];

        if (is_string($scaffoldValue) && $scaffoldValue !== '') {
            $classes = array_values(array_filter(
                $classes,
                fn(array $c) => strcasecmp($c['class'], $scaffoldValue) === 0,
            ));

            if (empty($classes)) {
                $this->components->warn("Class '{$scaffoldValue}' not found in undocumented scan results.");
                return;
            }
        }

        $result = $this->docScaffolder->scaffoldAll($classes);

        $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    protected function validateDocs(): void
    {
        $isGlobal = (bool) $this->option('global');
        $docsDirs = $this->docsDirectoryResolver->resolve($isGlobal);

        if (empty($docsDirs)) {
            $this->components->error('.docs directory does not exist.');
            throw new CommandTerminatedException();
        }

        $results = [];
        $allNames = [];
        $totalValid = 0;
        $totalInvalid = 0;

        foreach ($docsDirs as $docsDir) {
            $files = File::allFiles($docsDir['dir']);

            foreach ($files as $file) {
                if (!$this->isMarkdownFile($file->getPathname())) {
                    continue;
                }

                $content = file_get_contents($file->getPathname());
                if ($content === false) {
                    continue;
                }

                $relativePath = $docsDir['prefix'] . DS . $file->getRelativePathname();
            $errors = [];
            $warnings = [];

            $hasYaml = preg_match('/^---\s*\n/', $content);
            if (!$hasYaml) {
                $errors[] = 'Missing YAML front matter (document must start with ---)';
            } else {
                $yamlResult = $this->parseYamlHeader($content, $file->getRelativePathname());

                if (!isset($yamlResult['name']) || empty(trim($yamlResult['name']))) {
                    $errors[] = 'Missing required field: name';
                } else {
                    $allNames[$relativePath] = $yamlResult['name'];
                }

                if (!isset($yamlResult['description']) || empty(trim($yamlResult['description']))) {
                    $warnings[] = 'Missing recommended field: description (reduces search ranking)';
                } elseif (strlen(trim($yamlResult['description'])) < 10) {
                    $warnings[] = 'Description is too short (< 10 chars), consider adding more detail';
                }

                $contentAfterYaml = preg_replace('/^---\s*.*?\s*---\s*/s', '', $content);
                $contentAfterYaml = trim($contentAfterYaml);

                if (empty($contentAfterYaml)) {
                    $warnings[] = 'Empty content after YAML front matter';
                } elseif (!preg_match('/^#\s+.+/m', $contentAfterYaml)) {
                    $warnings[] = 'No H1 header found in document content';
                }
            }

            // Drift detection: cross-reference documented methods with source code
            $drift = $this->driftDetector->detect($content, Brain::projectDirectory());
            if ($drift !== null) {
                foreach ($drift['stale_methods'] as $method) {
                    $warnings[] = "Documentation drift: method '{$method}' documented but not found in source ({$drift['source']})";
                }
            }

            $isValid = empty($errors);
            if ($isValid) {
                $totalValid++;
            } else {
                $totalInvalid++;
            }

            if (!$isValid || !empty($warnings)) {
                $result = [
                    'path' => $relativePath,
                    'valid' => $isValid,
                ];

                if (!empty($errors)) {
                    $result['errors'] = $errors;
                }
                if (!empty($warnings)) {
                    $result['warnings'] = $warnings;
                }

                $results[] = $result;
            }
            }
        }

        $duplicateNames = [];
        $nameCounts = array_count_values($allNames);
        foreach ($nameCounts as $name => $count) {
            if ($count > 1) {
                $duplicateNames[$name] = array_keys($allNames, $name);
            }
        }

        if (!empty($duplicateNames)) {
            foreach ($results as &$result) {
                $path = $result['path'];
                if (isset($allNames[$path])) {
                    $name = $allNames[$path];
                    if (isset($duplicateNames[$name])) {
                        if (!isset($result['warnings'])) {
                            $result['warnings'] = [];
                        }
                        $result['warnings'][] = "Duplicate name '{$name}' used in multiple documents";
                    }
                }
            }
            unset($result);

            foreach ($allNames as $path => $name) {
                if (!isset($duplicateNames[$name])) {
                    continue;
                }
                $exists = false;
                foreach ($results as $r) {
                    if ($r['path'] === $path) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $results[] = [
                        'path' => $path,
                        'valid' => true,
                        'warnings' => ["Duplicate name '{$name}' used in multiple documents"],
                    ];
                }
            }
        }

        $totalWarnings = 0;
        foreach ($results as $result) {
            $totalWarnings += count($result['warnings'] ?? []);
        }

        $output = [
            'documents' => $results,
            'summary' => [
                'total' => count($results),
                'valid' => $totalValid,
                'invalid' => $totalInvalid,
                'warnings' => $totalWarnings,
            ],
        ];

        if (!empty($duplicateNames)) {
            $output['summary']['duplicate_names'] = array_keys($duplicateNames);
        }

        $this->line(json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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
        $isGlobal = (bool) $this->option('global');
        $docsDirs = $this->docsDirectoryResolver->resolve($isGlobal);

        if (empty($docsDirs)) {
            $this->components->error('.docs directory does not exist.');
            throw new CommandTerminatedException();
        }

        $updated = 0;
        $skipped = 0;

        foreach ($docsDirs as $docsDir) {
            $files = File::allFiles($docsDir['dir']);

            foreach ($files as $file) {
                if (!$this->isMarkdownFile($file->getPathname())) {
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
                    Brain::debugException($e, 'brain-debug:updateDocsSources');
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

                $validation = $this->securityValidator->validate($downloaded, $url);
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
        }

        $this->components->info("Updated: {$updated}, Skipped (no url): {$skipped}");
    }

    protected function downloadDocsSources(): void
    {
        $url = $this->option('download');

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $this->components->error('Invalid URL.');
            throw new CommandTerminatedException();
        }

        $filename = $this->option('as') ?: basename(parse_url($url, PHP_URL_PATH));

        if (!preg_match('/^[\w,\s-]+\.(md|txt|html)$/i', $filename)) {
            $this->components->error('Filename must end with .md, .txt, or .html');
            throw new CommandTerminatedException();
        }

        $content = @file_get_contents($url);

        if ($content === false || empty($content)) {
            $this->components->error('Download failed or empty.');
            throw new CommandTerminatedException();
        }

        $validation = $this->securityValidator->validate($content, $url);
        if (!$validation['valid']) {
            $this->components->error("Security: {$validation['reason']}");
            throw new CommandTerminatedException();
        }
        if (!empty($validation['warnings'])) {
            $this->components->warn("Warning: " . implode(', ', $validation['warnings']));
        }

        $sourcesDir = Brain::projectDirectory('.docs/sources');
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

    /**
     * @param  Collection<int, string>  $keywords
     * @return array<int, array<string, mixed>>
     */
    public function getFileList(string $dir, Collection $keywords, string $pathPrefix = '.docs'): array
    {
        $files = File::allFiles($dir);

        return collect(array_map(
            fn(SplFileInfo $file) => $this->processFile($file, $keywords, $pathPrefix),
            $files,
        ))
            ->filter()
            ->unique('path')
            ->when($keywords->isNotEmpty(), fn($c) => $c->sortByDesc('score'))
            ->values()
            ->toArray();
    }

    /**
     * @param  Collection<int, string>  $keywords
     * @return array<string, mixed>|null
     */
    protected function processFile(SplFileInfo $file, Collection $keywords, string $pathPrefix = '.docs'): ?array
    {
        if (!$this->isMarkdownFile($file->getPathname())) {
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

        $exactPhrase = $this->option('exact');
        if ($exactPhrase !== null && $exactPhrase !== '') {
            $isStrict = $this->option('strict');
            $searchContent = $isStrict ? $content : $contentLower;
            $searchPhrase = $isStrict ? $exactPhrase : Str::lower($exactPhrase);

            if (!Str::contains($searchContent, $searchPhrase)) {
                return null;
            }
        }

        $result = [
            'path' => $pathPrefix . DS . $file->getRelativePathname(),
        ];

        $yamlData = $this->parseYamlHeader($content, $file->getRelativePathname());
        $result = array_merge($result, $yamlData);

        $hasYamlName = isset($result['name']) && !empty(trim($result['name']));
        $hasYamlDescription = isset($result['description']) && !empty(trim($result['description']));

        $contentAfterYaml = $content;
        if (preg_match('/^---\s*.*?\s*---\s*/s', $content)) {
            $contentAfterYaml = preg_replace('/^---\s*.*?\s*---\s*/s', '', $content);
        }

        if (!$hasYamlName) {
            $autoName = $this->markdownParser->extractAutoName($contentAfterYaml);
            if ($autoName !== null) {
                $result['name'] = $autoName['text'];
                $result['_auto_name'] = $autoName['level'];
            }
        }

        if (!$hasYamlDescription) {
            $autoDescription = $this->markdownParser->extractAutoDescription($contentAfterYaml);
            if ($autoDescription !== null) {
                $result['description'] = $autoDescription;
                $result['_auto_description'] = true;
            }
        }

        $result['score'] = $keywords->isNotEmpty()
            ? $this->contentScorer->calculate($keywords, $result, $contentLower)
            : 0;

        $absolutePath = $file->getPathname();
        $relativePath = $file->getRelativePathname();
        $docsDir = substr($absolutePath, 0, -strlen($relativePath) - 1);

        $result['source'] = $this->trustResolver->inferSource($relativePath, $pathPrefix, $result['url'] ?? null);
        $result['freshness'] = $this->freshnessResolver->resolve($absolutePath, $docsDir);
        $result['trust'] = $this->trustResolver->inferTrust($result['source'], $result['url'] ?? null);

        if ($this->option('stats')) {
            $result['stats'] = $this->extractStats($content, $file);
        }

        if ($this->option('headers') > 0) {
            $headers = $this->markdownParser->parseHeaders(
                $content,
                (int) $this->option('headers'),
                (bool) $this->option('snippets'),
            );
            if (!empty($headers)) {
                $result['headers'] = $headers;
            }
        }

        if ($this->option('code')) {
            $codeBlocks = $this->markdownParser->extractCodeBlocks($content);
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
            Brain::debugException($e);
            $this->components->warn("YAML parse error in {$filename}, indexing without metadata");
            return [];
        }
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

        return collect($matches)
            ->unique(fn($m) => $m['keyword'] . '-' . $m['line'])
            ->take(20)
            ->values()
            ->toArray();
    }

    /**
     * Check if --scaffold was passed in argv (distinguishes from "not provided" null).
     */
    protected function hasScaffoldOption(): bool
    {
        $argv = $_SERVER['argv'] ?? [];

        foreach ($argv as $arg) {
            if ($arg === '--scaffold' || str_starts_with($arg, '--scaffold=')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a file path has a supported markdown extension (.md or .mdx).
     */
    protected function isMarkdownFile(string $path): bool
    {
        return str_ends_with($path, '.md') || str_ends_with($path, '.mdx');
    }

    protected function extractMatchContext(string $line, string $keyword): string
    {
        $line = trim($line);
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
}
