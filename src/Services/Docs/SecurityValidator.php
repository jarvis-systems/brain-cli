<?php

declare(strict_types=1);

namespace BrainCLI\Services\Docs;

use Illuminate\Support\Str;

/**
 * 3-layer security validation for downloaded documentation content.
 *
 * Layer 1: Unicode normalization — NFKC normalization + zero-width char stripping + HTML entity decode
 * Layer 2: Pattern matching — 15+ injection patterns (prompt, script, protocol, elements)
 * Layer 3: Base64 detection — find encoded strings, decode, re-scan through injection patterns
 */
class SecurityValidator
{
    /**
     * Maximum allowed content size in bytes (5MB).
     */
    protected const MAX_SIZE = 5 * 1024 * 1024;

    /**
     * Minimum length for base64 string detection.
     */
    protected const BASE64_MIN_LENGTH = 40;

    /**
     * Allowed URL schemes.
     *
     * @var array<int, string>
     */
    protected const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * Injection patterns — blocked content indicators.
     *
     * @var array<string, string>
     */
    protected const INJECTION_PATTERNS = [
        // Prompt injection
        '/ignore\s+(all\s+)?(previous|above|prior)\s+(instructions?|prompts?|rules?)/i' => 'prompt injection attempt',
        '/forget\s+(all\s+)?(previous|above|prior)?\s*(instructions?|prompts?|rules?|context)/i' => 'prompt injection attempt',
        '/system\s*:\s*you\s+are\s+now/i' => 'system prompt override',
        '/disregard\s+(all\s+)?(previous|above)/i' => 'instruction bypass',
        '/new\s+system\s+prompt/i' => 'system prompt injection',
        '/you\s+are\s+now\s+(a|an|the)\s+/i' => 'role injection attempt',
        '/\bact\s+as\s+(a|an|if)\s+/i' => 'role injection attempt',
        '/\bpretend\s+(you\s+are|to\s+be)\s+/i' => 'role injection attempt',

        // Script injection
        '/\<\s*script[\s>]/i' => 'script injection',
        '/javascript\s*:/i' => 'javascript protocol',
        '/data\s*:\s*text\/html/i' => 'data URI injection',
        '/vbscript\s*:/i' => 'vbscript protocol',

        // Event handler injection
        '/\bon(load|error|click|mouse|focus|blur|submit|change|key|touch|drag|drop|abort|resize|scroll|unload|beforeunload|hashchange|message|storage|popstate|animation|transition)\s*=/i' => 'event handler injection',

        // Dangerous HTML elements
        '/\<\s*iframe[\s>]/i' => 'iframe injection',
        '/\<\s*embed[\s>]/i' => 'embed injection',
        '/\<\s*object[\s>]/i' => 'object injection',
        '/\<\s*form\s+[^>]*action\s*=/i' => 'form action injection',
        '/\<\s*meta\s+[^>]*http-equiv\s*=/i' => 'meta redirect injection',
    ];

    /**
     * Caution patterns — flagged for review but not blocked.
     *
     * @var array<string, string>
     */
    protected const CAUTION_PATTERNS = [
        '/\b(instruction|prompt|system|override|bypass)\b/i' => 'contains AI-related terms',
        '/TODO|FIXME|XXX/i' => 'contains development markers',
    ];

    /**
     * Homoglyph map for common unicode confusables (fallback when intl extension unavailable).
     *
     * @var array<string, string>
     */
    protected const HOMOGLYPH_MAP = [
        "\xD0\xB0" => 'a', // Cyrillic а → Latin a
        "\xD0\xB5" => 'e', // Cyrillic е → Latin e
        "\xD0\xBE" => 'o', // Cyrillic о → Latin o
        "\xD1\x80" => 'p', // Cyrillic р → Latin p
        "\xD1\x81" => 'c', // Cyrillic с → Latin c
        "\xD1\x83" => 'y', // Cyrillic у → Latin y
        "\xD1\x85" => 'x', // Cyrillic х → Latin x
        "\xD0\x90" => 'A', // Cyrillic А → Latin A
        "\xD0\x92" => 'B', // Cyrillic В → Latin B
        "\xD0\x95" => 'E', // Cyrillic Е → Latin E
        "\xD0\x9A" => 'K', // Cyrillic К → Latin K
        "\xD0\x9C" => 'M', // Cyrillic М → Latin M
        "\xD0\x9D" => 'H', // Cyrillic Н → Latin H
        "\xD0\x9E" => 'O', // Cyrillic О → Latin O
        "\xD0\xA0" => 'P', // Cyrillic Р → Latin P
        "\xD0\xA1" => 'C', // Cyrillic С → Latin C
        "\xD0\xA2" => 'T', // Cyrillic Т → Latin T
        "\xD0\xA5" => 'X', // Cyrillic Х → Latin X
        "\xC4\xB0" => 'I', // Latin İ → Latin I
        "\xC4\xB1" => 'i', // Latin ı → Latin i
        "\xCE\x91" => 'A', // Greek Α → Latin A
        "\xCE\x92" => 'B', // Greek Β → Latin B
        "\xCE\x95" => 'E', // Greek Ε → Latin E
        "\xCE\x97" => 'H', // Greek Η → Latin H
        "\xCE\x99" => 'I', // Greek Ι → Latin I
        "\xCE\x9A" => 'K', // Greek Κ → Latin K
        "\xCE\x9C" => 'M', // Greek Μ → Latin M
        "\xCE\x9D" => 'N', // Greek Ν → Latin N
        "\xCE\x9F" => 'O', // Greek Ο → Latin O
        "\xCE\xA1" => 'P', // Greek Ρ → Latin P
        "\xCE\xA4" => 'T', // Greek Τ → Latin T
        "\xCE\xA7" => 'X', // Greek Χ → Latin X
        "\xCE\xB1" => 'a', // Greek α → Latin a
        "\xCE\xBF" => 'o', // Greek ο → Latin o
    ];

    /**
     * Zero-width characters to strip.
     *
     * @var array<int, string>
     */
    protected const ZERO_WIDTH_CHARS = [
        "\xE2\x80\x8B", // Zero-width space
        "\xE2\x80\x8C", // Zero-width non-joiner
        "\xE2\x80\x8D", // Zero-width joiner
        "\xE2\x81\xA0", // Word joiner
        "\xEF\xBB\xBF", // BOM
        "\xC2\xAD",     // Soft hyphen
        "\xE2\x80\x8E", // Left-to-right mark
        "\xE2\x80\x8F", // Right-to-left mark
        "\xE2\x80\xAA", // Left-to-right embedding
        "\xE2\x80\xAB", // Right-to-left embedding
        "\xE2\x80\xAC", // Pop directional formatting
        "\xE2\x80\xAD", // Left-to-right override
        "\xE2\x80\xAE", // Right-to-left override
    ];

    /**
     * Validate downloaded content for security threats.
     *
     * @param string $content Downloaded content
     * @param string $url Source URL
     * @return array{valid: bool, reason: string|null, warnings: array<int, string>}
     */
    public function validate(string $content, string $url): array
    {
        // Size check
        if (strlen($content) > self::MAX_SIZE) {
            return ['valid' => false, 'reason' => 'File too large (max 5MB)', 'warnings' => []];
        }

        // URL scheme check
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            return ['valid' => false, 'reason' => "Invalid URL scheme: {$scheme}", 'warnings' => []];
        }

        // Layer 1: Unicode normalization
        $normalized = $this->normalizeUnicode($content);

        // Layer 2: Pattern matching on normalized content
        $patternResult = $this->scanInjectionPatterns($normalized);
        if ($patternResult !== null) {
            return ['valid' => false, 'reason' => "Detected {$patternResult}", 'warnings' => []];
        }

        // Layer 3: Base64 detection
        $base64Result = $this->scanBase64Payloads($normalized);
        if ($base64Result !== null) {
            return ['valid' => false, 'reason' => "Detected {$base64Result} (base64 encoded)", 'warnings' => []];
        }

        // Caution patterns
        $warnings = $this->scanCautionPatterns($normalized);

        return ['valid' => true, 'reason' => null, 'warnings' => $warnings];
    }

    /**
     * Layer 1: Normalize unicode content for consistent pattern matching.
     *
     * - NFKC normalization (if intl available) or homoglyph fallback
     * - Strip zero-width characters
     * - Decode HTML entities
     */
    protected function normalizeUnicode(string $content): string
    {
        // NFKC normalization (handles compatibility decomposition)
        if (extension_loaded('intl') && class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($content, \Normalizer::FORM_KC);
            if ($normalized === false) {
                $normalized = $content;
            }
        } else {
            $normalized = $content;
        }

        // Homoglyph replacement (always applied — NFKC doesn't handle cross-script lookalikes)
        $normalized = str_replace(
            array_keys(self::HOMOGLYPH_MAP),
            array_values(self::HOMOGLYPH_MAP),
            $normalized,
        );

        // Strip zero-width characters
        $normalized = str_replace(self::ZERO_WIDTH_CHARS, '', $normalized);

        // Decode HTML entities
        $normalized = html_entity_decode($normalized, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $normalized;
    }

    /**
     * Layer 2: Scan content against injection patterns.
     *
     * @return string|null Threat type if found, null if clean
     */
    protected function scanInjectionPatterns(string $content): ?string
    {
        foreach (self::INJECTION_PATTERNS as $pattern => $type) {
            if (preg_match($pattern, $content)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Layer 3: Find base64-encoded strings, decode, and re-scan for injection.
     *
     * @return string|null Threat type if found in decoded content, null if clean
     */
    protected function scanBase64Payloads(string $content): ?string
    {
        // Find potential base64 strings (40+ chars of valid base64 alphabet)
        if (!preg_match_all('/[A-Za-z0-9+\/]{' . self::BASE64_MIN_LENGTH . ',}={0,2}/', $content, $matches)) {
            return null;
        }

        foreach ($matches[0] as $candidate) {
            $decoded = base64_decode($candidate, true);

            if ($decoded === false) {
                continue;
            }

            // Check if decoded content looks like text (not binary)
            if (!mb_check_encoding($decoded, 'UTF-8')) {
                continue;
            }

            // Re-scan decoded content for injection patterns
            $threat = $this->scanInjectionPatterns($decoded);
            if ($threat !== null) {
                return $threat;
            }
        }

        return null;
    }

    /**
     * Scan for caution patterns (warnings, not blocks).
     *
     * @return array<int, string>
     */
    protected function scanCautionPatterns(string $content): array
    {
        $warnings = [];
        $contentLower = Str::lower($content);

        foreach (self::CAUTION_PATTERNS as $pattern => $type) {
            if (preg_match($pattern, $content)) {
                // Only warn if terms appear frequently (>3 occurrences)
                $keyTerm = preg_match('/instruction|prompt/i', $content) ? 'instruction' : 'prompt';
                if (substr_count($contentLower, $keyTerm) > 3) {
                    $warnings[] = $type;
                    break;
                }
            }
        }

        return $warnings;
    }
}
