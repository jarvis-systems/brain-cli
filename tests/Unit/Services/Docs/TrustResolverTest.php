<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Services\Docs;

use BrainCLI\Services\Docs\TrustResolver;
use PHPUnit\Framework\TestCase;

class TrustResolverTest extends TestCase
{
    protected TrustResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new TrustResolver();
    }

    public function test_local_doc_is_high_trust(): void
    {
        $result = $this->resolver->resolve('operations/release.md', '.docs', null);

        $this->assertSame('high', $result['trust']['level']);
        $this->assertSame('Local project documentation', $result['trust']['reason']);
    }

    public function test_downloaded_with_url_is_medium_trust(): void
    {
        $result = $this->resolver->resolve('sources/readme.md', '.docs', 'https://example.com/readme.md');

        $this->assertSame('med', $result['trust']['level']);
        $this->assertSame('Downloaded from known URL', $result['trust']['reason']);
    }

    public function test_downloaded_without_url_is_low_trust(): void
    {
        $result = $this->resolver->resolve('sources/readme.md', '.docs', null);

        $this->assertSame('low', $result['trust']['level']);
        $this->assertSame('Downloaded without source URL', $result['trust']['reason']);
    }

    public function test_external_doc_is_medium_trust(): void
    {
        $result = $this->resolver->resolve('api.md', 'packages/core/.docs', null);

        $this->assertSame('med', $result['trust']['level']);
        $this->assertSame('External project documentation', $result['trust']['reason']);
    }

    public function test_source_local_for_root_docs(): void
    {
        $result = $this->resolver->resolve('product/feature.md', '.docs', null);

        $this->assertSame('local', $result['source']);
    }

    public function test_source_downloaded_for_sources_dir(): void
    {
        $result = $this->resolver->resolve('sources/external.md', '.docs', null);

        $this->assertSame('downloaded', $result['source']);
    }

    public function test_source_downloaded_for_yaml_url(): void
    {
        $result = $this->resolver->resolve('custom/doc.md', '.docs', 'https://example.com/doc.md');

        $this->assertSame('downloaded', $result['source']);
    }

    public function test_source_external_for_non_root_prefix(): void
    {
        $result = $this->resolver->resolve('api.md', 'cli/.docs', null);

        $this->assertSame('external', $result['source']);
    }
}
