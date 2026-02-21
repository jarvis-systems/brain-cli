<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Services\Docs;

use BrainCLI\Services\Docs\ContentScorer;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class ContentScorerTest extends TestCase
{
    protected ContentScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new ContentScorer();
    }

    public function test_yaml_name_match_scores_10(): void
    {
        $keywords = new Collection(['api']);
        $result = ['name' => 'API Documentation', 'description' => 'Some desc'];

        $score = $this->scorer->calculate($keywords, $result, 'api documentation content');

        // name match (10) + content frequency
        $this->assertGreaterThanOrEqual(10, $score);
    }

    public function test_auto_name_h1_scores_7(): void
    {
        $keywords = new Collection(['api']);
        $result = ['name' => 'API Guide', '_auto_name' => 1];

        $score = $this->scorer->calculate($keywords, $result, 'api guide content');

        // H1 auto name (7) + content frequency
        $this->assertGreaterThanOrEqual(7, $score);
    }

    public function test_auto_name_h3_scores_5(): void
    {
        $keywords = new Collection(['api']);
        $result = ['name' => 'API Endpoints', '_auto_name' => 3];

        $score = $this->scorer->calculate($keywords, $result, 'api endpoints content');

        $this->assertGreaterThanOrEqual(5, $score);
    }

    public function test_yaml_description_match_scores_5(): void
    {
        $keywords = new Collection(['auth']);
        $result = ['name' => 'Guide', 'description' => 'Authentication module docs'];

        $score = $this->scorer->calculate($keywords, $result, 'auth module documentation');

        // description match (5) + content frequency
        $this->assertGreaterThanOrEqual(5, $score);
    }

    public function test_auto_description_match_scores_3(): void
    {
        $keywords = new Collection(['auth']);
        $result = ['name' => 'Guide', 'description' => 'Authentication docs', '_auto_description' => true];

        $score = $this->scorer->calculate($keywords, $result, 'auth documentation');

        // auto description (3) + content frequency
        $this->assertGreaterThanOrEqual(3, $score);
    }

    public function test_frequency_scoring_single_match(): void
    {
        $keywords = new Collection(['unique']);
        $result = [];

        $score = $this->scorer->calculate($keywords, $result, 'this is a unique word in the content');

        // 1 occurrence: ceil(log2(1 + 1)) = ceil(1) = 1
        $this->assertSame(1, $score);
    }

    public function test_frequency_scoring_multiple_matches(): void
    {
        $keywords = new Collection(['api']);
        $result = [];

        // 7 occurrences: ceil(log2(7 + 1)) = ceil(3) = 3
        $content = 'api api api api api api api';

        $score = $this->scorer->calculate($keywords, $result, $content);

        $this->assertSame(3, $score);
    }

    public function test_frequency_scoring_many_matches_capped_at_10(): void
    {
        $keywords = new Collection(['x']);
        $result = [];

        // 1000+ occurrences should cap at 10
        $content = str_repeat('x ', 1100);

        $score = $this->scorer->calculate($keywords, $result, $content);

        $this->assertSame(10, $score);
    }

    public function test_no_match_scores_zero(): void
    {
        $keywords = new Collection(['nonexistent']);
        $result = ['name' => 'Something', 'description' => 'Other stuff'];

        $score = $this->scorer->calculate($keywords, $result, 'no matching content here');

        $this->assertSame(0, $score);
    }

    public function test_multiple_keywords_accumulate(): void
    {
        $keywords = new Collection(['api', 'auth']);
        $result = ['name' => 'API Auth Guide'];

        $score = $this->scorer->calculate($keywords, $result, 'api auth guide content');

        // Both keywords match name (10 + 10) + content frequency for each
        $this->assertGreaterThanOrEqual(20, $score);
    }

    public function test_deterministic_scoring(): void
    {
        $keywords = new Collection(['test']);
        $result = ['name' => 'Test Document'];
        $content = 'test test test content';

        $score1 = $this->scorer->calculate($keywords, $result, $content);
        $score2 = $this->scorer->calculate($keywords, $result, $content);

        $this->assertSame($score1, $score2);
    }
}
