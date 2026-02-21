<?php

declare(strict_types=1);

namespace BrainCLI\Enums\Docs;

/**
 * Backed string enum for code language detection and normalization.
 *
 * Provides canonical language names with alias resolution for
 * common abbreviations and alternative names used in fenced code blocks.
 */
enum CodeLanguage: string
{
    case PHP = 'php';
    case JAVASCRIPT = 'javascript';
    case TYPESCRIPT = 'typescript';
    case PYTHON = 'python';
    case GO = 'go';
    case RUST = 'rust';
    case JAVA = 'java';
    case CSHARP = 'csharp';
    case CPP = 'cpp';
    case C = 'c';
    case RUBY = 'ruby';
    case SWIFT = 'swift';
    case KOTLIN = 'kotlin';
    case SCALA = 'scala';
    case BASH = 'bash';
    case SHELL = 'shell';
    case POWERSHELL = 'powershell';
    case SQL = 'sql';
    case GRAPHQL = 'graphql';
    case HTML = 'html';
    case CSS = 'css';
    case SCSS = 'scss';
    case LESS = 'less';
    case JSON = 'json';
    case YAML = 'yaml';
    case TOML = 'toml';
    case XML = 'xml';
    case MARKDOWN = 'markdown';
    case DOCKERFILE = 'dockerfile';
    case MAKEFILE = 'makefile';
    case LUA = 'lua';
    case PERL = 'perl';
    case R = 'r';
    case DART = 'dart';
    case ELIXIR = 'elixir';
    case HASKELL = 'haskell';
    case CLOJURE = 'clojure';
    case OBJECTIVE_C = 'objective-c';
    case PROTOBUF = 'protobuf';
    case TERRAFORM = 'terraform';
    case NGINX = 'nginx';
    case APACHE = 'apache';
    case INI = 'ini';
    case DIFF = 'diff';
    case PLAINTEXT = 'plaintext';

    /**
     * Alias map for resolving common abbreviations to canonical language names.
     *
     * @return array<string, self>
     */
    public static function aliasMap(): array
    {
        return [
            // JavaScript aliases
            'js' => self::JAVASCRIPT,
            'mjs' => self::JAVASCRIPT,
            'cjs' => self::JAVASCRIPT,
            'jsx' => self::JAVASCRIPT,
            'node' => self::JAVASCRIPT,

            // TypeScript aliases
            'ts' => self::TYPESCRIPT,
            'tsx' => self::TYPESCRIPT,
            'mts' => self::TYPESCRIPT,
            'cts' => self::TYPESCRIPT,

            // Python aliases
            'py' => self::PYTHON,
            'python3' => self::PYTHON,
            'py3' => self::PYTHON,

            // Shell aliases
            'sh' => self::BASH,
            'zsh' => self::BASH,
            'fish' => self::BASH,
            'ksh' => self::BASH,
            'console' => self::BASH,
            'terminal' => self::BASH,

            // C/C++ aliases
            'c++' => self::CPP,
            'cxx' => self::CPP,
            'hpp' => self::CPP,
            'h' => self::C,

            // C# aliases
            'c#' => self::CSHARP,
            'cs' => self::CSHARP,
            'dotnet' => self::CSHARP,

            // Go aliases
            'golang' => self::GO,

            // Ruby aliases
            'rb' => self::RUBY,

            // Rust aliases
            'rs' => self::RUST,

            // Kotlin aliases
            'kt' => self::KOTLIN,
            'kts' => self::KOTLIN,

            // Objective-C aliases
            'objc' => self::OBJECTIVE_C,
            'obj-c' => self::OBJECTIVE_C,
            'objectivec' => self::OBJECTIVE_C,

            // YAML aliases
            'yml' => self::YAML,

            // Markdown aliases
            'md' => self::MARKDOWN,

            // Docker aliases
            'docker' => self::DOCKERFILE,

            // Terraform aliases
            'tf' => self::TERRAFORM,
            'hcl' => self::TERRAFORM,

            // PowerShell aliases
            'ps1' => self::POWERSHELL,
            'pwsh' => self::POWERSHELL,

            // GraphQL aliases
            'gql' => self::GRAPHQL,

            // Protobuf aliases
            'proto' => self::PROTOBUF,

            // Misc aliases
            'text' => self::PLAINTEXT,
            'txt' => self::PLAINTEXT,
            'plain' => self::PLAINTEXT,
            'mysql' => self::SQL,
            'pgsql' => self::SQL,
            'postgresql' => self::SQL,
            'sqlite' => self::SQL,
            'cfg' => self::INI,
            'conf' => self::INI,
            'config' => self::INI,
            'env' => self::INI,
            'patch' => self::DIFF,
            'sass' => self::SCSS,
            'ex' => self::ELIXIR,
            'exs' => self::ELIXIR,
            'hs' => self::HASKELL,
            'clj' => self::CLOJURE,
            'pl' => self::PERL,
            'pm' => self::PERL,
        ];
    }

    /**
     * Resolve a language identifier (declared or alias) to a canonical CodeLanguage.
     *
     * @param string $identifier The language identifier from a fenced code block
     * @return self|null Resolved language or null if unrecognized
     */
    public static function resolve(string $identifier): ?self
    {
        $normalized = strtolower(trim($identifier));

        if ($normalized === '') {
            return null;
        }

        // Direct match
        $direct = self::tryFrom($normalized);
        if ($direct !== null) {
            return $direct;
        }

        // Alias match
        $aliases = self::aliasMap();
        return $aliases[$normalized] ?? null;
    }
}
