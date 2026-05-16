<?php

namespace App\Sitemap;

class SitemapResult
{
    /** @var array<string> Deduplicated list of URLs found across all sitemaps */
    public array $urls = [];

    /** @var array<string> URLs of sitemap files actually fetched and parsed */
    public array $sitemapsVisited = [];

    /** @var array<string> Sitemaps that could not be fetched/parsed (with reason) */
    public array $errors = [];

    /** @var bool Whether one of the safety limits was reached */
    public bool $truncated = false;
}
