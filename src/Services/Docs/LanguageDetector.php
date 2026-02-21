<?php

declare(strict_types=1);

namespace BrainCLI\Services\Docs;

use BrainCLI\Enums\Docs\CodeLanguage;

/**
 * Detects programming language from code block content using heuristic rules.
 *
 * Detection strategy:
 * 1. If a language is declared (```php), resolve via CodeLanguage enum
 * 2. If undeclared, apply ordered heuristic rules
 * 3. Return null for genuinely undetectable content
 */
class LanguageDetector
{
    /**
     * Heuristic rules ordered by specificity (most unique patterns first).
     * Each rule: [pattern, language].
     *
     * @var array<int, array{string, CodeLanguage}>
     */
    protected const HEURISTIC_RULES = [
        // JSON — structural
        ['/^\s*[\{\[]/s', CodeLanguage::JSON],

        // PHP — unique markers
        ['/^\s*<\?php/m', CodeLanguage::PHP],
        ['/^\s*namespace\s+[A-Z][\w\\\\]+\s*;/m', CodeLanguage::PHP],
        ['/\$this\s*->/m', CodeLanguage::PHP],

        // Dockerfile — unique keyword
        ['/^\s*FROM\s+\S+/m', CodeLanguage::DOCKERFILE],

        // Makefile — target with recipe or .PHONY
        ['/^[\w\-]+\s*:.*\n\t/m', CodeLanguage::MAKEFILE],
        ['/^\.PHONY\s*:/m', CodeLanguage::MAKEFILE],

        // TOML — structural (before YAML, more specific)
        ['/^\s*\[[\w\.\-]+\]\s*$/m', CodeLanguage::TOML],

        // SQL — DDL/DML
        ['/^\s*(SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|WITH)\s+/mi', CodeLanguage::SQL],

        // Rust — unique syntax
        ['/\bfn\s+\w+\s*\([^)]*\)\s*(->\s*[\w<>&]+)?\s*\{/m', CodeLanguage::RUST],
        ['/\blet\s+mut\s+/m', CodeLanguage::RUST],
        ['/\bimpl\s+\w+/m', CodeLanguage::RUST],

        // Go — unique syntax
        ['/^\s*package\s+\w+\s*$/m', CodeLanguage::GO],
        ['/\bfunc\s+(\(\w+\s+\*?\w+\)\s+)?\w+\s*\(/m', CodeLanguage::GO],
        ['/\btype\s+\w+\s+struct\s*\{/m', CodeLanguage::GO],

        // TypeScript — must check before JavaScript
        ['/:\s*(string|number|boolean|void|any|never|unknown)\b/m', CodeLanguage::TYPESCRIPT],
        ['/\binterface\s+\w+\s*\{/m', CodeLanguage::TYPESCRIPT],
        ['/\b(type|enum)\s+\w+\s*[=\{]/m', CodeLanguage::TYPESCRIPT],

        // Python — unique syntax
        ['/^\s*(def|class)\s+\w+[\s\(]/m', CodeLanguage::PYTHON],
        ['/^\s*(import|from)\s+\w+/m', CodeLanguage::PYTHON],

        // Kotlin — unique syntax
        ['/\bfun\s+\w+\s*\(/m', CodeLanguage::KOTLIN],
        ['/\bval\s+\w+\s*[=:]/m', CodeLanguage::KOTLIN],
        ['/\bvar\s+\w+\s*:\s*\w+/m', CodeLanguage::KOTLIN],

        // Swift — unique syntax
        ['/\bfunc\s+\w+\s*\([^)]*\)\s*->\s*/m', CodeLanguage::SWIFT],
        ['/\bguard\s+let\s+/m', CodeLanguage::SWIFT],

        // Java — unique patterns
        ['/\bpublic\s+class\s+\w+/m', CodeLanguage::JAVA],
        ['/^\s*@(Override|Autowired|Bean|Component)/m', CodeLanguage::JAVA],

        // Ruby — unique syntax
        ['/^\s*(require|gem)\s+["\'][\w\/]+["\']/m', CodeLanguage::RUBY],
        ['/^\s*class\s+\w+\s*<\s*\w+/m', CodeLanguage::RUBY],
        ['/\bdo\s*\|[\w,\s]+\|/m', CodeLanguage::RUBY],

        // Elixir — unique syntax
        ['/^\s*defmodule\s+/m', CodeLanguage::ELIXIR],
        ['/\bdef\s+\w+.*\bdo\s*$/m', CodeLanguage::ELIXIR],

        // Haskell — unique syntax
        ['/^\s*module\s+[\w\.]+\s+where/m', CodeLanguage::HASKELL],
        ['/\b::\s*[\w\[\]()-> ]+$/m', CodeLanguage::HASKELL],

        // GraphQL — unique syntax
        ['/^\s*(query|mutation|subscription|type|schema)\s+\w*\s*\{/m', CodeLanguage::GRAPHQL],

        // Terraform — unique syntax
        ['/^\s*(resource|variable|output|provider|data)\s+"[\w_]+"/m', CodeLanguage::TERRAFORM],

        // YAML — requires multiple key: value lines (avoids false positives)
        ['/^[\w\-]+\s*:\s+\S.*\n[\w\-]+\s*:\s+\S/m', CodeLanguage::YAML],

        // CSS/SCSS
        ['/^\s*[\.\#\@][\w\-]+\s*\{[^}]*\}/ms', CodeLanguage::CSS],
        ['/\$[\w\-]+\s*:\s*.+;/m', CodeLanguage::SCSS],

        // HTML — structural
        ['/^\s*<!DOCTYPE\s+html/mi', CodeLanguage::HTML],
        ['/^\s*<(html|head|body|div|span|p|a|table|form)\b/mi', CodeLanguage::HTML],

        // XML — structural
        ['/^\s*<\?xml\s+/m', CodeLanguage::XML],

        // JavaScript — broadest match, last among JS-family
        ['/^\s*(const|let|var)\s+\w+\s*=/m', CodeLanguage::JAVASCRIPT],
        ['/^\s*function\s+\w+\s*\(/m', CodeLanguage::JAVASCRIPT],
        ['/=>\s*[\{\(]/m', CodeLanguage::JAVASCRIPT],
        ['/^\s*(export|import)\s+/m', CodeLanguage::JAVASCRIPT],

        // Bash — broadest match
        ['/^\s*(git|npm|yarn|pnpm|pip|composer|php|docker|kubectl|curl|wget|chmod|mkdir|cd|ls|rm|cp|mv|echo|cat|grep|sed|awk|make|brew|apt|yum|dnf|pacman|cargo|go|rustc|python|python3|ruby|node|npx|bun|deno)\s+/m', CodeLanguage::BASH],
        ['/^\s*#!\/bin\/(bash|sh|zsh)/m', CodeLanguage::BASH],

        // INI/Config — very generic
        ['/^\s*\[[\w\s]+\]\s*$/m', CodeLanguage::INI],

        // Diff — unique prefix
        ['/^[+-]{3}\s+\w/m', CodeLanguage::DIFF],
        ['/^@@\s+[\-\+\d,]+\s+@@/m', CodeLanguage::DIFF],
    ];

    /**
     * Detect the language of a code block.
     *
     * @param string $declared The declared language from the fenced code block (may be empty)
     * @param string $code The code content to analyze
     * @return CodeLanguage|null Detected language or null if undetectable
     */
    public function detect(string $declared, string $code): ?CodeLanguage
    {
        if ($declared !== '') {
            return CodeLanguage::resolve($declared);
        }

        $code = trim($code);

        if ($code === '') {
            return null;
        }

        foreach (self::HEURISTIC_RULES as [$pattern, $language]) {
            if (preg_match($pattern, $code)) {
                return $language;
            }
        }

        return null;
    }
}
