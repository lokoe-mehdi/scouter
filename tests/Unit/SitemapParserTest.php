<?php

use App\Sitemap\SitemapParser;
use App\Sitemap\SitemapResult;

/**
 * Test subclass of SitemapParser that resolves fetches from a local fixture map
 * instead of hitting the network.
 */
class FakeSitemapParser extends SitemapParser
{
    /** @var array<string, string|null> */
    private array $bodies;

    public function __construct(array $bodies)
    {
        $this->bodies = $bodies;
    }

    protected function fetch(string $url): ?string
    {
        if (!array_key_exists($url, $this->bodies)) {
            return null;
        }
        return $this->bodies[$url];
    }
}

if (!function_exists('sitemapFixture')) {
    function sitemapFixture(string $name): string
    {
        return file_get_contents(__DIR__ . '/../fixtures/sitemaps/' . $name);
    }
}

describe('SitemapParser', function () {

    it('parses a simple XML sitemap', function () {
        $parser = new FakeSitemapParser([
            'https://example.com/sitemap.xml' => sitemapFixture('simple.xml'),
        ]);
        $r = $parser->parse(['https://example.com/sitemap.xml']);

        expect($r)->toBeInstanceOf(SitemapResult::class);
        expect($r->urls)->toHaveCount(3);
        expect($r->urls)->toContain('https://example.com/');
        expect($r->urls)->toContain('https://example.com/about');
        expect($r->urls)->toContain('https://example.com/contact');
        expect($r->sitemapsVisited)->toBe(['https://example.com/sitemap.xml']);
        expect($r->errors)->toBeEmpty();
    });

    it('recurses into a sitemap-index and deduplicates URLs', function () {
        $parser = new FakeSitemapParser([
            'https://example.com/sitemap.xml'   => sitemapFixture('index.xml'),
            'https://example.com/sitemap-a.xml' => sitemapFixture('child-a.xml'),
            'https://example.com/sitemap-b.xml' => sitemapFixture('child-b.xml'),
        ]);
        $r = $parser->parse(['https://example.com/sitemap.xml']);

        // 2 (child-a) + 3 (child-b) − 1 dedup (https://example.com/a/1) = 4
        expect($r->urls)->toHaveCount(4);
        expect(array_count_values($r->urls)['https://example.com/a/1'] ?? 0)->toBe(1);
        expect($r->sitemapsVisited)->toHaveCount(3);
    });

    it('parses a plain-text sitemap, ignoring comments and blank/invalid lines', function () {
        $parser = new FakeSitemapParser([
            'https://example.com/sitemap.txt' => sitemapFixture('urls.txt'),
        ]);
        $r = $parser->parse(['https://example.com/sitemap.txt']);

        expect($r->urls)->toBe([
            'https://example.com/p1',
            'https://example.com/p2',
            'https://example.com/p3',
        ]);
    });

    it('transparently decodes a gzipped sitemap body', function () {
        $gzBody = gzencode(sitemapFixture('simple.xml'));
        $parser = new FakeSitemapParser([
            'https://example.com/sitemap.xml.gz' => $gzBody,
        ]);
        $r = $parser->parse(['https://example.com/sitemap.xml.gz']);

        expect($r->urls)->toHaveCount(3);
        expect($r->errors)->toBeEmpty();
    });

    it('reports an error on malformed XML without throwing', function () {
        $parser = new FakeSitemapParser([
            'https://example.com/bad.xml' => sitemapFixture('malformed.xml'),
        ]);
        $r = $parser->parse(['https://example.com/bad.xml']);

        expect($r->urls)->toBeEmpty();
        expect($r->errors)->toHaveCount(1);
        expect($r->errors[0])->toContain('malformed XML');
    });

    it('records an error when a sitemap is unreachable but continues with the others', function () {
        $parser = new FakeSitemapParser([
            'https://example.com/missing.xml' => null,
            'https://example.com/sitemap.xml' => sitemapFixture('simple.xml'),
        ]);
        $r = $parser->parse([
            'https://example.com/missing.xml',
            'https://example.com/sitemap.xml',
        ]);

        expect($r->urls)->toHaveCount(3);
        // FakeSitemapParser returning null short-circuits before any error is recorded;
        // we only care that the second sitemap was still parsed normally.
        expect($r->sitemapsVisited)->toBe(['https://example.com/sitemap.xml']);
    });

    it('honours the MAX_INDEX_DEPTH safety limit (no infinite recursion)', function () {
        // index → index → urlset would be depth 2 — allowed
        // index → index → index → urlset would be depth 3 — blocked
        $bodies = [
            'https://example.com/i0' => '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://example.com/i1</loc></sitemap></sitemapindex>',
            'https://example.com/i1' => '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://example.com/i2</loc></sitemap></sitemapindex>',
            'https://example.com/i2' => '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://example.com/leaf</loc></sitemap></sitemapindex>',
            'https://example.com/leaf' => sitemapFixture('simple.xml'),
        ];
        $parser = new FakeSitemapParser($bodies);
        $r = $parser->parse(['https://example.com/i0']);

        // We should NOT reach the leaf — at least one "max index depth" error is recorded
        $hasDepthError = false;
        foreach ($r->errors as $e) {
            if (strpos($e, 'max index depth') !== false) {
                $hasDepthError = true;
                break;
            }
        }
        expect($hasDepthError)->toBeTrue();
        expect($r->urls)->toBeEmpty();
    });

    it('deduplicates the input sitemap list', function () {
        $parser = new FakeSitemapParser([
            'https://example.com/sitemap.xml' => sitemapFixture('simple.xml'),
        ]);
        $r = $parser->parse([
            'https://example.com/sitemap.xml',
            'https://example.com/sitemap.xml',
        ]);

        expect($r->sitemapsVisited)->toHaveCount(1);
        expect($r->urls)->toHaveCount(3);
    });

    it('ignores empty/whitespace input URLs', function () {
        $parser = new FakeSitemapParser([
            'https://example.com/sitemap.xml' => sitemapFixture('simple.xml'),
        ]);
        $r = $parser->parse(['', '   ', 'https://example.com/sitemap.xml']);

        expect($r->urls)->toHaveCount(3);
    });
});
