<?php

declare(strict_types=1);

namespace BrainCLI\Services\Docs;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Calculates search relevance scores for documentation files.
 *
 * Scoring strategy:
 * - YAML name match: +10 (auto name: H1=+7, H2=+6, ..., H6=+2)
 * - YAML description match: +5 (auto description: +3)
 * - Content frequency: min(ceil(log2(count + 1)), 10) per keyword
 */
class ContentScorer
{
    /**
     * Maximum points from frequency-based content scoring per keyword.
     */
    protected const MAX_FREQUENCY_SCORE = 10;

    /**
     * Calculate search relevance score for a document.
     *
     * @param Collection<int, string> $keywords Search keywords
     * @param array<string, mixed> $result Document result array with name, description, _auto_name, _auto_description
     * @param string $contentLower Lowercased full document content
     * @return int Calculated score
     */
    public function calculate(Collection $keywords, array $result, string $contentLower): int
    {
        $score = 0;

        foreach ($keywords as $keyword) {
            $kw = Str::lower($keyword);

            $score += $this->scoreNameMatch($kw, $result);
            $score += $this->scoreDescriptionMatch($kw, $result);
            $score += $this->scoreContentFrequency($kw, $contentLower);
        }

        return $score;
    }

    /**
     * Score name/title match for a keyword.
     */
    protected function scoreNameMatch(string $keyword, array $result): int
    {
        if (!isset($result['name']) || !Str::contains(Str::lower($result['name']), $keyword)) {
            return 0;
        }

        if (isset($result['_auto_name'])) {
            return match ((int) $result['_auto_name']) {
                1 => 7,
                2 => 6,
                3 => 5,
                4 => 4,
                5 => 3,
                6 => 2,
                default => 7,
            };
        }

        return 10;
    }

    /**
     * Score description match for a keyword.
     */
    protected function scoreDescriptionMatch(string $keyword, array $result): int
    {
        if (!isset($result['description']) || !Str::contains(Str::lower($result['description']), $keyword)) {
            return 0;
        }

        return isset($result['_auto_description']) ? 3 : 5;
    }

    /**
     * Score content frequency match using logarithmic scaling.
     *
     * Formula: min(ceil(log2(count + 1)), MAX_FREQUENCY_SCORE)
     * - 1 match = 1pt
     * - 3 matches = 2pt
     * - 7 matches = 3pt
     * - 15 matches = 4pt
     * - 31 matches = 5pt
     * - 50+ matches capped at 10pt
     */
    protected function scoreContentFrequency(string $keyword, string $contentLower): int
    {
        if (!Str::contains($contentLower, $keyword)) {
            return 0;
        }

        $count = substr_count($contentLower, $keyword);

        if ($count <= 0) {
            return 0;
        }

        return min(
            (int) ceil(log($count + 1, 2)),
            self::MAX_FREQUENCY_SCORE,
        );
    }
}
