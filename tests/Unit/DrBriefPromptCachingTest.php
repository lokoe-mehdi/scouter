<?php

use App\AI\DrBriefPrompt;

/**
 * Guards the prompt-caching split (cost optimization).
 *
 * The system prompt is sent on every tool iteration AND every turn, so we cache
 * a stable prefix and keep the volatile page snapshot OUT of it. If the page
 * snapshot ever leaks back into the cacheable prefix, it would bust the cache on
 * every navigation — this test is the regression guard for that contract.
 */

it('keeps the page snapshot OUT of the cacheable prefix', function () {
    $crawl = (object)['id' => 1, 'domain' => 'example.com', 'urls' => 5, 'crawled' => 5, 'depth_max' => 2];
    $parts = DrBriefPrompt::buildParts($crawl, 'PAGE_SNAPSHOT_MARKER_XYZ', 'fr', []);

    expect($parts)->toHaveKeys(['cacheable', 'page_context']);
    expect($parts['cacheable'])->toBeString()->not->toBe('');
    // The volatile snapshot must NOT be in the cached prefix...
    expect(str_contains($parts['cacheable'], 'PAGE_SNAPSHOT_MARKER_XYZ'))->toBeFalse();
    // ...but must be in its own (uncached) block.
    expect(str_contains($parts['page_context'], 'PAGE_SNAPSHOT_MARKER_XYZ'))->toBeTrue();
});

it('emits an empty page_context block when no snapshot is provided', function () {
    $crawl = (object)['id' => 7, 'domain' => 'mysite.io', 'urls' => 1, 'crawled' => 1, 'depth_max' => 0];
    $parts = DrBriefPrompt::buildParts($crawl, null, 'en', []);

    expect($parts['page_context'])->toBe('');
    // Crawl facts belong to the (stable-per-conversation) cacheable prefix.
    expect($parts['cacheable'])->toContain('mysite.io');
});
