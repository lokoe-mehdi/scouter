<?php

use App\Core\Page;

describe('Page - Data Extraction', function () {

    it('extracts title from HTML', function () {
        $html = '<!DOCTYPE html><html><head><title>My Page Title</title></head><body></body></html>';
        $url = 'https://example.com/page';
        $headers = (object)[
            'http_code' => 200,
            'content_type' => 'text/html',
            'total_time' => 0.5,
            'redirect_url' => '',
            'size_download' => 1024
        ];
        
        $page = new Page($url, $headers, $html, ['example.com'], [
            'xPathExtractors' => [],
            'regexExtractors' => []
        ]);
        $result = $page->getPage();
        
        expect($result->extracts['title'])->toBe('My Page Title');
    });

    it('extracts H1 from HTML', function () {
        $html = '<!DOCTYPE html><html><head><title>Test</title></head><body><h1>Main Heading</h1></body></html>';
        $url = 'https://example.com/page';
        $headers = (object)[
            'http_code' => 200,
            'content_type' => 'text/html',
            'total_time' => 0.5,
            'redirect_url' => '',
            'size_download' => 1024
        ];
        
        $page = new Page($url, $headers, $html, ['example.com'], [
            'xPathExtractors' => [],
            'regexExtractors' => []
        ]);
        $result = $page->getPage();
        
        expect($result->extracts['h1'])->toBe('Main Heading');
    });

    it('extracts meta description', function () {
        $html = '<!DOCTYPE html><html><head><title>Test</title><meta name="description" content="This is the meta description"></head><body></body></html>';
        $url = 'https://example.com/page';
        $headers = (object)[
            'http_code' => 200,
            'content_type' => 'text/html',
            'total_time' => 0.5,
            'redirect_url' => '',
            'size_download' => 1024
        ];
        
        $page = new Page($url, $headers, $html, ['example.com'], [
            'xPathExtractors' => [],
            'regexExtractors' => []
        ]);
        $result = $page->getPage();
        
        expect($result->extracts['meta_desc'])->toBe('This is the meta description');
    });

    it('extracts canonical URL', function () {
        $html = '<!DOCTYPE html><html><head><title>Test</title><link rel="canonical" href="https://example.com/canonical-page"></head><body></body></html>';
        $url = 'https://example.com/page';
        $headers = (object)[
            'http_code' => 200,
            'content_type' => 'text/html',
            'total_time' => 0.5,
            'redirect_url' => '',
            'size_download' => 1024
        ];
        
        $page = new Page($url, $headers, $html, ['example.com'], [
            'xPathExtractors' => [],
            'regexExtractors' => []
        ]);
        $result = $page->getPage();
        
        expect($result->extracts['canonical'])->toBe('https://example.com/canonical-page');
    });

    it('detects noindex directive', function () {
        $html = '<!DOCTYPE html><html><head><title>Test</title><meta name="robots" content="noindex"></head><body></body></html>';
        $url = 'https://example.com/page';
        $headers = (object)[
            'http_code' => 200,
            'content_type' => 'text/html',
            'total_time' => 0.5,
            'redirect_url' => '',
            'size_download' => 1024
        ];
        
        $page = new Page($url, $headers, $html, ['example.com'], [
            'xPathExtractors' => [],
            'regexExtractors' => []
        ]);
        $result = $page->getPage();
        
        expect($result->config['noindex'])->toBe(1);
    });

    it('detects nofollow directive', function () {
        $html = '<!DOCTYPE html><html><head><title>Test</title><meta name="robots" content="nofollow"></head><body></body></html>';
        $url = 'https://example.com/page';
        $headers = (object)[
            'http_code' => 200,
            'content_type' => 'text/html',
            'total_time' => 0.5,
            'redirect_url' => '',
            'size_download' => 1024
        ];
        
        $page = new Page($url, $headers, $html, ['example.com'], [
            'xPathExtractors' => [],
            'regexExtractors' => []
        ]);
        $result = $page->getPage();
        
        expect($result->config['nofollow'])->toBe(1);
    });

    it('extracts links from page', function () {
        $html = '<!DOCTYPE html><html><head><title>Test</title></head><body><a href="https://example.com/page2">Link 1</a><a href="/page3">Link 2</a></body></html>';
        $url = 'https://example.com/page';
        $headers = (object)[
            'http_code' => 200,
            'content_type' => 'text/html',
            'total_time' => 0.5,
            'redirect_url' => '',
            'size_download' => 1024
        ];
        
        $page = new Page($url, $headers, $html, ['example.com'], [
            'xPathExtractors' => [],
            'regexExtractors' => []
        ]);
        $result = $page->getPage();
        
        expect($result->links)->toBeArray();
        expect(count($result->links))->toBeGreaterThanOrEqual(2);
    });

    it('converts relative URLs to absolute', function () {
        $html = '<!DOCTYPE html><html><head><title>Test</title></head><body><a href="/relative-page">Link</a></body></html>';
        $url = 'https://example.com/current/page';
        $headers = (object)[
            'http_code' => 200,
            'content_type' => 'text/html',
            'total_time' => 0.5,
            'redirect_url' => '',
            'size_download' => 1024
        ];
        
        $page = new Page($url, $headers, $html, ['example.com'], [
            'xPathExtractors' => [],
            'regexExtractors' => []
        ]);
        $result = $page->getPage();
        
        // Vérifier qu'au moins un lien a été converti en URL absolue
        $hasAbsoluteUrl = false;
        foreach ($result->links as $link) {
            if (strpos($link->target, 'https://example.com') === 0) {
                $hasAbsoluteUrl = true;
                break;
            }
        }
        expect($hasAbsoluteUrl)->toBeTrue();
    });

    it('filters invalid links (mailto, javascript)', function () {
        $html = '<!DOCTYPE html><html><head><title>Test</title></head><body><a href="mailto:test@example.com">Email</a><a href="javascript:void(0)">JS</a><a href="tel:+33123456789">Phone</a><a href="/valid">Valid</a></body></html>';
        $url = 'https://example.com/page';
        $headers = (object)[
            'http_code' => 200,
            'content_type' => 'text/html',
            'total_time' => 0.5,
            'redirect_url' => '',
            'size_download' => 1024
        ];
        
        $page = new Page($url, $headers, $html, ['example.com'], [
            'xPathExtractors' => [],
            'regexExtractors' => []
        ]);
        $result = $page->getPage();
        
        // Vérifier qu'aucun lien mailto, javascript ou tel n'est présent
        foreach ($result->links as $link) {
            expect($link->target)->not->toContain('mailto:');
            expect($link->target)->not->toContain('javascript:');
            expect($link->target)->not->toContain('tel:');
        }
    });

    it('detects external vs internal links', function () {
        $html = '<!DOCTYPE html><html><head><title>Test</title></head><body><a href="https://example.com/internal">Internal</a><a href="https://external.com/page">External</a></body></html>';
        $url = 'https://example.com/page';
        $headers = (object)[
            'http_code' => 200,
            'content_type' => 'text/html',
            'total_time' => 0.5,
            'redirect_url' => '',
            'size_download' => 1024
        ];
        
        $page = new Page($url, $headers, $html, ['example.com'], [
            'xPathExtractors' => [],
            'regexExtractors' => []
        ]);
        $result = $page->getPage();
        
        $hasInternal = false;
        $hasExternal = false;
        
        foreach ($result->links as $link) {
            if (strpos($link->target, 'example.com') !== false) {
                expect($link->external)->toBe(0);
                $hasInternal = true;
            }
            if (strpos($link->target, 'external.com') !== false) {
                expect($link->external)->toBe(1);
                $hasExternal = true;
            }
        }
        
        expect($hasInternal)->toBeTrue();
        expect($hasExternal)->toBeTrue();
    });

    it('detects multiple H1 tags', function () {
        $html = '<!DOCTYPE html><html><head><title>Test</title></head><body><h1>First H1</h1><h1>Second H1</h1></body></html>';
        $url = 'https://example.com/page';
        $headers = (object)[
            'http_code' => 200,
            'content_type' => 'text/html',
            'total_time' => 0.5,
            'redirect_url' => '',
            'size_download' => 1024
        ];
        
        $page = new Page($url, $headers, $html, ['example.com'], [
            'xPathExtractors' => [],
            'regexExtractors' => []
        ]);
        $result = $page->getPage();
        
        expect($result->h1_multiple)->toBeTrue();
    });

    it('detects missing heading levels', function () {
        // H1 puis H3 directement (H2 manquant)
        $html = '<!DOCTYPE html><html><head><title>Test</title></head><body><h1>Title</h1><h3>Subtitle</h3></body></html>';
        $url = 'https://example.com/page';
        $headers = (object)[
            'http_code' => 200,
            'content_type' => 'text/html',
            'total_time' => 0.5,
            'redirect_url' => '',
            'size_download' => 1024
        ];
        
        $page = new Page($url, $headers, $html, ['example.com'], [
            'xPathExtractors' => [],
            'regexExtractors' => []
        ]);
        $result = $page->getPage();
        
        expect($result->headings_missing)->toBeTrue();
    });

    it('extracts JSON-LD schema types', function () {
        $html = '<!DOCTYPE html><html><head><title>Test</title><script type="application/ld+json">{"@context":"https://schema.org","@type":"Article","headline":"Test"}</script></head><body></body></html>';
        $url = 'https://example.com/page';
        $headers = (object)[
            'http_code' => 200,
            'content_type' => 'text/html',
            'total_time' => 0.5,
            'redirect_url' => '',
            'size_download' => 1024
        ];
        
        $page = new Page($url, $headers, $html, ['example.com'], [
            'xPathExtractors' => [],
            'regexExtractors' => []
        ]);
        $result = $page->getPage();
        
        expect($result->schemas)->toBeArray();
        expect($result->schemas)->toContain('Article');
    });

    it('extracts Microdata schema types (itemscope + itemtype)', function () {
        $html = '<!DOCTYPE html><html><head><title>Test</title></head><body>
            <main itemscope itemtype="https://schema.org/MusicAlbum" itemid="#this">
                <meta itemprop="albumProductionType" content="https://schema.org/StudioAlbum">
                <div itemprop="byArtist" itemscope itemtype="https://schema.org/MusicGroup">
                    <span itemprop="name">Chokebore</span>
                </div>
            </main>
        </body></html>';
        $url = 'https://example.com/album';
        $headers = (object)[
            'http_code' => 200,
            'content_type' => 'text/html',
            'total_time' => 0.5,
            'redirect_url' => '',
            'size_download' => 1024
        ];
        
        $page = new Page($url, $headers, $html, ['example.com'], [
            'xPathExtractors' => [],
            'regexExtractors' => []
        ]);
        $result = $page->getPage();
        
        expect($result->schemas)->toBeArray();
        expect($result->schemas)->toContain('MusicAlbum');
        // MusicGroup a itemprop="byArtist", donc c'est un sous-type, il ne doit PAS être extrait
        expect($result->schemas)->not->toContain('MusicGroup');
    });

    it('extracts RDFa schema types (typeof)', function () {
        $html = '<!DOCTYPE html><html><head><title>Test</title></head><body>
            <div typeof="schema:Product">
                <span property="name">Mon Produit</span>
                <div property="offers" typeof="schema:Offer">
                    <span property="price">29.99</span>
                </div>
            </div>
        </body></html>';
        $url = 'https://example.com/product';
        $headers = (object)[
            'http_code' => 200,
            'content_type' => 'text/html',
            'total_time' => 0.5,
            'redirect_url' => '',
            'size_download' => 1024
        ];
        
        $page = new Page($url, $headers, $html, ['example.com'], [
            'xPathExtractors' => [],
            'regexExtractors' => []
        ]);
        $result = $page->getPage();
        
        expect($result->schemas)->toBeArray();
        expect($result->schemas)->toContain('Product');
        // Offer a property="offers", donc c'est un sous-type, il ne doit PAS être extrait
        expect($result->schemas)->not->toContain('Offer');
    });

});
