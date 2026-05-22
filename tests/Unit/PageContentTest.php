<?php

use App\Api\PageContent;

/**
 * Unit tests for the readable-content extractor behind GET /crawls/{id}/content.
 * Pure (operates on an HTML string) — no DB needed.
 */

it('decodes a base64 + gzip blob back to the original HTML', function () {
    $html = '<html><body><h1>Hi</h1></body></html>';
    $blob = base64_encode(gzdeflate($html));
    expect(PageContent::decode($blob))->toBe($html);
    expect(PageContent::decode(null))->toBeNull();
    expect(PageContent::decode(''))->toBeNull();
});

it('extracts title, ordered headings and visible text', function () {
    $html = '<html><head><title>  My Page </title></head>'
          . '<body><h1>Main</h1><p>Hello world.</p><h2>Sub</h2><div>Second paragraph.</div></body></html>';
    $r = PageContent::extract($html);

    expect($r['title'])->toBe('My Page');
    expect($r['headings'])->toBe([
        ['level' => 1, 'text' => 'Main'],
        ['level' => 2, 'text' => 'Sub'],
    ]);
    expect($r['text'])->toContain('Hello world.');
    expect($r['text'])->toContain('Second paragraph.');
    expect($r['word_count'])->toBeGreaterThan(0);
    expect($r['truncated'])->toBeFalse();
});

it('strips scripts, styles and svg from the visible text', function () {
    $html = '<html><head><style>.x{color:red}</style></head>'
          . '<body><script>var secret=42;</script><svg><path d="M0"/></svg>'
          . '<p>Visible text only.</p><script type="application/ld+json">{"@type":"Product"}</script></body></html>';
    $r = PageContent::extract($html);

    expect($r['text'])->toContain('Visible text only.');
    expect($r['text'])->not->toContain('secret');
    expect($r['text'])->not->toContain('color:red');
    expect($r['text'])->not->toContain('Product');
});

it('returns empty structures for empty HTML', function () {
    $r = PageContent::extract('');
    expect($r['headings'])->toBe([]);
    expect($r['word_count'])->toBe(0);
    expect($r['text'])->toBe('');
});
