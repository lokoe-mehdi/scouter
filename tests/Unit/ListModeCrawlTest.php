<?php

use App\Core\Page;
use App\Core\PageCrawler;
use App\Database\CrawlDatabase;
use App\Database\PostgresDatabase;

// ============================================
// SETUP / TEARDOWN
// ============================================

beforeEach(function () {
    $this->db = PostgresDatabase::getInstance()->getConnection();
    $this->crawlId = 99999;

    // Create test user → project → crawl (FK constraints)
    $this->db->exec("INSERT INTO users (id, email, password_hash) VALUES (9999, 'test-list@test.com', 'hash') ON CONFLICT (id) DO NOTHING");
    $this->db->exec("INSERT INTO projects (id, user_id, name) VALUES (9999, 9999, 'Test List Mode') ON CONFLICT (id) DO NOTHING");
    $this->db->exec("
        INSERT INTO crawls (id, project_id, domain, path, status, crawl_type, config)
        VALUES (99999, 9999, 'example.com', '/test/list-mode-99999', 'running', 'list', '{\"crawl_type\":\"list\"}')
        ON CONFLICT (id) DO NOTHING
    ");

    // Create partitions for this crawl
    $stmt = $this->db->prepare("SELECT create_crawl_partitions(:id)");
    $stmt->execute([':id' => $this->crawlId]);

    // Common config
    $this->listConfig = [
        'crawl_type' => 'list',
        'follow_redirects' => true,
        'respect' => ['canonical' => true],
    ];
    $this->spiderConfig = [
        'crawl_type' => 'spider',
        'follow_redirects' => true,
        'respect' => ['canonical' => true],
    ];
    $this->pattern = ['example.com'];
});

afterEach(function () {
    // Drop partitions then cascade-delete from users
    $this->db->exec("SELECT drop_crawl_partitions(99999)");
    $this->db->exec("DELETE FROM crawls WHERE id = 99999");
    $this->db->exec("DELETE FROM users WHERE id = 9999");
});

// ============================================
// HELPERS
// ============================================

/**
 * Build a fake Page object matching getPage() output structure.
 */
function fakePage(string $url, array $links = [], array $overrides = []): object
{
    $id = hash('crc32', $url, false);
    preg_match('#https?://([^/\?]+)#i', $url, $m);
    $domain = $m[1] ?? '';

    return (object) array_merge([
        'id' => $id,
        'url' => $url,
        'domain' => $domain,
        'domain_id' => hash('crc32', $domain, false),
        'headers' => (object) [
            'http_code' => 200,
            'redirect_to' => '',
            'redirect_hash' => 0,
            'response_time' => 0.1,
            'size' => 1000,
            'content_type' => 'text/html',
        ],
        'config' => [
            'nofollow' => 0,
            'noindex' => 0,
            'canonical' => 1,
        ],
        'domZip' => '',
        'domHash' => sha1('<html></html>'),
        'extracts' => [
            'title' => 'Test',
            'meta_desc' => '',
            'h1' => 'Test',
            'canonical' => $url,
        ],
        'links' => $links,
        'is_html' => true,
        'simhash' => null,
        'h1_multiple' => false,
        'headings_missing' => false,
        'schemas' => [],
        'word_count' => 0,
    ], $overrides);
}

/**
 * Build a link object matching Page parse() output.
 */
function fakeLink(string $target, int $external = 0): object
{
    preg_match('#https?://([^/\?]+)#i', $target, $m);
    $dom = $m[1] ?? '';
    return (object) [
        'target' => $target,
        'target_id' => hash('crc32', $target, false),
        'target_dom' => $dom,
        'target_dom_hash' => hash('crc32', $dom, false),
        'external' => $external,
        'anchor' => 'click here',
        'nofollow' => 0,
        'blocked' => 0,
    ];
}

/**
 * Invoke a private method on PageCrawler via Reflection.
 */
function invokePrivate(object $obj, string $method, array $args = []): mixed
{
    $ref = new ReflectionMethod($obj, $method);
    $ref->setAccessible(true);
    return $ref->invoke($obj, ...$args);
}

/**
 * Set a private property on an object via Reflection.
 */
function setPrivate(object $obj, string $prop, mixed $value): void
{
    $ref = new ReflectionProperty($obj, $prop);
    $ref->setAccessible(true);
    $ref->setValue($obj, $value);
}

// ============================================
// SECTION 1 — Page parsing (sans DB)
// ============================================

test('counts all valid outlinks from HTML', function () {
    $html = '<!DOCTYPE html><html><head><title>Test</title>
        <meta name="description" content="desc">
        <link rel="canonical" href="https://example.com/page">
        </head><body>
        <h1>Hello</h1>
        <a href="https://example.com/page2">Link 1</a>
        <a href="https://example.com/page3">Link 2</a>
        <a href="https://example.com/page4">Link 3</a>
        <a href="https://other.com/page5">Link 4</a>
        <a href="https://other.com/page6">Link 5</a>
        </body></html>';

    $headers = (object) [
        'http_code' => 200,
        'redirect_url' => '',
        'starttransfer_time' => 0.1,
        'total_time' => 0.2,
        'size_download' => strlen($html),
        'content_type' => 'text/html; charset=utf-8',
    ];

    $crawlConfig = ['xPathExtractors' => [], 'regexExtractors' => []];
    $page = new Page('https://example.com/page', $headers, $html, ['example.com'], $crawlConfig);
    $result = $page->getPage();

    expect($result->links)->toHaveCount(5);
});

test('includes both internal and external links', function () {
    $html = '<!DOCTYPE html><html><head><title>Test</title>
        <meta name="description" content="desc">
        <link rel="canonical" href="https://example.com/page">
        </head><body>
        <h1>Hello</h1>
        <a href="https://example.com/internal">Internal</a>
        <a href="https://other.com/external">External</a>
        </body></html>';

    $headers = (object) [
        'http_code' => 200,
        'redirect_url' => '',
        'starttransfer_time' => 0.1,
        'total_time' => 0.2,
        'size_download' => strlen($html),
        'content_type' => 'text/html; charset=utf-8',
    ];

    $crawlConfig = ['xPathExtractors' => [], 'regexExtractors' => []];
    $page = new Page('https://example.com/page', $headers, $html, ['example.com'], $crawlConfig);
    $result = $page->getPage();

    $internals = array_filter($result->links, fn($l) => $l->external === 0);
    $externals = array_filter($result->links, fn($l) => $l->external === 1);

    expect($internals)->not->toBeEmpty();
    expect($externals)->not->toBeEmpty();
});

// ============================================
// SECTION 2 — storeLinks en mode liste (avec PostgreSQL)
// ============================================

test('stores links in links table in list mode', function () {
    $crawlDb = new CrawlDatabase($this->crawlId, $this->listConfig);
    $crawler = new PageCrawler($crawlDb, 0, $this->pattern, $this->listConfig);

    $srcUrl = 'https://example.com/src';
    $links = [
        fakeLink('https://example.com/a'),
        fakeLink('https://example.com/b'),
        fakeLink('https://other.com/c', 1),
    ];

    // Insert source page first (FK-like: src must exist for coherence)
    $crawlDb->insertPage([
        'id' => hash('crc32', $srcUrl, false),
        'url' => $srcUrl,
        'depth' => 0,
        'crawled' => false,
        'external' => false,
    ]);

    // Inject fake page into PageCrawler
    setPrivate($crawler, 'page', fakePage($srcUrl, $links));

    // Call storeLinks
    invokePrivate($crawler, 'storeLinks');

    // Verify links count
    $stmt = $this->db->prepare("SELECT COUNT(*) FROM links WHERE crawl_id = :id");
    $stmt->execute([':id' => $this->crawlId]);
    expect((int) $stmt->fetchColumn())->toBe(3);
});

test('inserts discovered pages with external=true in list mode', function () {
    $crawlDb = new CrawlDatabase($this->crawlId, $this->listConfig);
    $crawler = new PageCrawler($crawlDb, 0, $this->pattern, $this->listConfig);

    $srcUrl = 'https://example.com/src';
    $links = [
        fakeLink('https://example.com/discovered1'),
        fakeLink('https://example.com/discovered2'),
    ];

    $crawlDb->insertPage([
        'id' => hash('crc32', $srcUrl, false),
        'url' => $srcUrl,
        'depth' => 0,
        'crawled' => false,
        'external' => false,
    ]);

    setPrivate($crawler, 'page', fakePage($srcUrl, $links));
    invokePrivate($crawler, 'storeLinks');

    // Discovered pages should have external=true in list mode
    $stmt = $this->db->prepare("
        SELECT external FROM pages
        WHERE crawl_id = :id AND url IN ('https://example.com/discovered1', 'https://example.com/discovered2')
    ");
    $stmt->execute([':id' => $this->crawlId]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    expect($rows)->toHaveCount(2);
    foreach ($rows as $external) {
        expect((bool) $external)->toBeTrue();
    }
});

test('preserves existing list pages on conflict', function () {
    $crawlDb = new CrawlDatabase($this->crawlId, $this->listConfig);
    $crawler = new PageCrawler($crawlDb, 0, $this->pattern, $this->listConfig);

    $existingUrl = 'https://example.com/already-in-list';
    $existingId = hash('crc32', $existingUrl, false);

    // Pre-insert page as part of the user's list (external=false)
    $crawlDb->insertPage([
        'id' => $existingId,
        'url' => $existingUrl,
        'depth' => 0,
        'crawled' => false,
        'external' => false,
    ]);

    // Now discover the same URL via storeLinks (which tries external=true)
    $srcUrl = 'https://example.com/src';
    $crawlDb->insertPage([
        'id' => hash('crc32', $srcUrl, false),
        'url' => $srcUrl,
        'depth' => 0,
        'crawled' => false,
        'external' => false,
    ]);

    $links = [fakeLink($existingUrl)];
    setPrivate($crawler, 'page', fakePage($srcUrl, $links));
    invokePrivate($crawler, 'storeLinks');

    // The page should STILL have external=false (ON CONFLICT DO NOTHING)
    $stmt = $this->db->prepare("SELECT external FROM pages WHERE crawl_id = :id AND id = :pid");
    $stmt->execute([':id' => $this->crawlId, ':pid' => $existingId]);
    expect((bool) $stmt->fetchColumn())->toBeFalse();
});

test('inserts discovered pages with original external flag in spider mode', function () {
    $crawlDb = new CrawlDatabase($this->crawlId, $this->spiderConfig);
    $crawler = new PageCrawler($crawlDb, 0, $this->pattern, $this->spiderConfig);

    $srcUrl = 'https://example.com/src';
    $links = [
        fakeLink('https://example.com/internal', 0),
        fakeLink('https://other.com/external', 1),
    ];

    $crawlDb->insertPage([
        'id' => hash('crc32', $srcUrl, false),
        'url' => $srcUrl,
        'depth' => 0,
        'crawled' => false,
        'external' => false,
    ]);

    setPrivate($crawler, 'page', fakePage($srcUrl, $links));
    invokePrivate($crawler, 'storeLinks');

    // Internal page: external=false
    $stmt = $this->db->prepare("SELECT external FROM pages WHERE crawl_id = :id AND url = 'https://example.com/internal'");
    $stmt->execute([':id' => $this->crawlId]);
    expect((bool) $stmt->fetchColumn())->toBeFalse();

    // External page: external=true
    $stmt = $this->db->prepare("SELECT external FROM pages WHERE crawl_id = :id AND url = 'https://other.com/external'");
    $stmt->execute([':id' => $this->crawlId]);
    expect((bool) $stmt->fetchColumn())->toBeTrue();
});

// ============================================
// SECTION 3 — storeRedirect en mode liste (avec PostgreSQL)
// ============================================

test('forces redirect external=false in list mode', function () {
    $crawlDb = new CrawlDatabase($this->crawlId, $this->listConfig);
    $crawler = new PageCrawler($crawlDb, 0, $this->pattern, $this->listConfig);

    $srcUrl = 'https://example.com/old';
    $redirectUrl = 'https://other-domain.com/new';

    $crawlDb->insertPage([
        'id' => hash('crc32', $srcUrl, false),
        'url' => $srcUrl,
        'depth' => 0,
        'crawled' => false,
        'external' => false,
    ]);

    setPrivate($crawler, 'page', fakePage($srcUrl));
    // storeRedirect(url, external) — external=1 would be the spider result for cross-domain
    invokePrivate($crawler, 'storeRedirect', [$redirectUrl, 1]);

    $redirectId = hash('crc32', $redirectUrl, false);

    // Link should have external=false (forced by list mode)
    $stmt = $this->db->prepare("SELECT external FROM links WHERE crawl_id = :id AND target = :target AND type = 'redirect'");
    $stmt->execute([':id' => $this->crawlId, ':target' => $redirectId]);
    expect((bool) $stmt->fetchColumn())->toBeFalse();

    // Redirect target page should have external=false
    $stmt = $this->db->prepare("SELECT external FROM pages WHERE crawl_id = :id AND id = :pid");
    $stmt->execute([':id' => $this->crawlId, ':pid' => $redirectId]);
    expect((bool) $stmt->fetchColumn())->toBeFalse();
});

test('marks cross-domain redirect as external in spider mode', function () {
    $crawlDb = new CrawlDatabase($this->crawlId, $this->spiderConfig);
    $crawler = new PageCrawler($crawlDb, 0, $this->pattern, $this->spiderConfig);

    $srcUrl = 'https://example.com/old';
    $redirectUrl = 'https://other-domain.com/new';

    $crawlDb->insertPage([
        'id' => hash('crc32', $srcUrl, false),
        'url' => $srcUrl,
        'depth' => 0,
        'crawled' => false,
        'external' => false,
    ]);

    setPrivate($crawler, 'page', fakePage($srcUrl));
    // In spider mode, cross-domain redirect external=1
    invokePrivate($crawler, 'storeRedirect', [$redirectUrl, 1]);

    $redirectId = hash('crc32', $redirectUrl, false);

    // Link should have external=true
    $stmt = $this->db->prepare("SELECT external FROM links WHERE crawl_id = :id AND target = :target AND type = 'redirect'");
    $stmt->execute([':id' => $this->crawlId, ':target' => $redirectId]);
    expect((bool) $stmt->fetchColumn())->toBeTrue();

    // Redirect target page should have external=true
    $stmt = $this->db->prepare("SELECT external FROM pages WHERE crawl_id = :id AND id = :pid");
    $stmt->execute([':id' => $this->crawlId, ':pid' => $redirectId]);
    expect((bool) $stmt->fetchColumn())->toBeTrue();
});

// ============================================
// SECTION 4 — getUrlsToCrawl (avec PostgreSQL)
// ============================================

test('excludes external pages from crawl queue', function () {
    $crawlDb = new CrawlDatabase($this->crawlId, $this->listConfig);

    // 1 page interne (from list)
    $crawlDb->insertPage([
        'id' => hash('crc32', 'https://example.com/list1', false),
        'url' => 'https://example.com/list1',
        'depth' => 0,
        'crawled' => false,
        'external' => false,
    ]);

    // 2 pages discovered (external=true)
    $crawlDb->insertPage([
        'id' => hash('crc32', 'https://example.com/discovered1', false),
        'url' => 'https://example.com/discovered1',
        'depth' => 1,
        'crawled' => false,
        'external' => true,
    ]);
    $crawlDb->insertPage([
        'id' => hash('crc32', 'https://other.com/discovered2', false),
        'url' => 'https://other.com/discovered2',
        'depth' => 1,
        'crawled' => false,
        'external' => true,
    ]);

    $urls = $crawlDb->getUrlsToCrawl(false);
    expect($urls)->toHaveCount(1);
    expect($urls[0])->toBe('https://example.com/list1');
});

test('excludes already crawled pages', function () {
    $crawlDb = new CrawlDatabase($this->crawlId, $this->listConfig);

    // Already crawled
    $crawlDb->insertPage([
        'id' => hash('crc32', 'https://example.com/done', false),
        'url' => 'https://example.com/done',
        'depth' => 0,
        'crawled' => true,
        'external' => false,
    ]);

    // Not yet crawled
    $crawlDb->insertPage([
        'id' => hash('crc32', 'https://example.com/todo', false),
        'url' => 'https://example.com/todo',
        'depth' => 0,
        'crawled' => false,
        'external' => false,
    ]);

    $urls = $crawlDb->getUrlsToCrawl(false);
    expect($urls)->toHaveCount(1);
    expect($urls[0])->toBe('https://example.com/todo');
});

// ============================================
// SECTION 5 — canonical en mode liste (avec PostgreSQL)
// ============================================

test('inserts canonical page with external=true in list mode', function () {
    $crawlDb = new CrawlDatabase($this->crawlId, $this->listConfig);
    $crawler = new PageCrawler($crawlDb, 0, $this->pattern, $this->listConfig);

    $srcUrl = 'https://example.com/duplicate';
    $canonicalUrl = 'https://example.com/canonical';

    $crawlDb->insertPage([
        'id' => hash('crc32', $srcUrl, false),
        'url' => $srcUrl,
        'depth' => 0,
        'crawled' => false,
        'external' => false,
    ]);

    // Page with canonical pointing elsewhere (config.canonical = 0)
    $page = fakePage($srcUrl, [], [
        'config' => [
            'nofollow' => 0,
            'noindex' => 0,
            'canonical' => 0,
        ],
        'extracts' => [
            'title' => 'Duplicate',
            'meta_desc' => '',
            'h1' => 'Dup',
            'canonical' => $canonicalUrl,
        ],
    ]);

    setPrivate($crawler, 'page', $page);
    invokePrivate($crawler, 'storeLinks');

    $canonicalId = hash('crc32', $canonicalUrl, false);

    // Canonical link should be stored
    $stmt = $this->db->prepare("SELECT type FROM links WHERE crawl_id = :id AND target = :target");
    $stmt->execute([':id' => $this->crawlId, ':target' => $canonicalId]);
    expect($stmt->fetchColumn())->toBe('canonical');

    // Canonical page should have external=true in list mode
    $stmt = $this->db->prepare("SELECT external FROM pages WHERE crawl_id = :id AND id = :pid");
    $stmt->execute([':id' => $this->crawlId, ':pid' => $canonicalId]);
    expect((bool) $stmt->fetchColumn())->toBeTrue();
});
