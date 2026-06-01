<?php

use App\Database\PostgresDatabase;

beforeEach(function () {
    $this->db = PostgresDatabase::getInstance()->getConnection();

    // Create test user → project → crawl
    $this->db->exec("INSERT INTO users (id, email, password_hash) VALUES (8888, 'test-delete@test.com', 'hash') ON CONFLICT (id) DO NOTHING");
    $this->db->exec("INSERT INTO projects (id, user_id, name) VALUES (8888, 8888, 'Delete Test Project') ON CONFLICT (id) DO NOTHING");
    $this->db->exec("
        INSERT INTO crawls (id, project_id, domain, path, status, config)
        VALUES (88888, 8888, 'delete-test.com', '/test/delete-88888', 'finished', '{\"general\":{\"start\":\"https://delete-test.com\"}}')
        ON CONFLICT (id) DO NOTHING
    ");
    $this->db->exec("
        INSERT INTO crawls (id, project_id, domain, path, status, config)
        VALUES (88889, 8888, 'delete-test.com', '/test/delete-88889', 'finished', '{\"general\":{\"start\":\"https://delete-test.com\"}}')
        ON CONFLICT (id) DO NOTHING
    ");

    // Create partitions for the crawls
    $this->db->exec("SELECT create_crawl_partitions(88888)");
    $this->db->exec("SELECT create_crawl_partitions(88889)");
});

afterEach(function () {
    // Clean up — drop partitions first, then records
    try { $this->db->exec("SELECT drop_crawl_partitions(88888)"); } catch (Exception $e) {}
    try { $this->db->exec("SELECT drop_crawl_partitions(88889)"); } catch (Exception $e) {}
    $this->db->exec("DELETE FROM crawls WHERE id IN (88888, 88889)");
    $this->db->exec("DELETE FROM projects WHERE id = 8888");
    $this->db->exec("DELETE FROM users WHERE id = 8888");
});

// ============================================
// Soft-delete behavior
// ============================================

test('crawl with deleting status is excluded from getAll', function () {
    $repo = new \App\Database\CrawlRepository();

    // Before: crawl is visible
    $all = $repo->getAll();
    $ids = array_map(fn($c) => $c->id, $all);
    expect($ids)->toContain(88888);

    // Mark as deleting
    $this->db->exec("UPDATE crawls SET status = 'deleting' WHERE id = 88888");

    // After: crawl is hidden
    $all = $repo->getAll();
    $ids = array_map(fn($c) => $c->id, $all);
    expect($ids)->not->toContain(88888);
});

test('crawl with deleting status is excluded from getByProjectId', function () {
    $repo = new \App\Database\CrawlRepository();

    $this->db->exec("UPDATE crawls SET status = 'deleting' WHERE id = 88888");

    $crawls = $repo->getByProjectId(8888);
    $ids = array_map(fn($c) => $c->id, $crawls);
    expect($ids)->not->toContain(88888);
    expect($ids)->toContain(88889); // Other crawl still visible
});

test('project with deleted_at is excluded from getForUser', function () {
    $repo = new \App\Database\ProjectRepository();

    // Before: project is visible
    $projects = $repo->getForUser(8888);
    $ids = array_map(fn($p) => $p->id, $projects);
    expect($ids)->toContain(8888);

    // Soft-delete project
    $this->db->exec("UPDATE projects SET deleted_at = NOW() WHERE id = 8888");

    // After: project is hidden
    $projects = $repo->getForUser(8888);
    $ids = array_map(fn($p) => $p->id, $projects);
    expect($ids)->not->toContain(8888);
});

test('soft-deleted project is excluded from isOwner', function () {
    $repo = new \App\Database\ProjectRepository();

    expect($repo->isOwner(8888, 8888))->toBeTrue();

    $this->db->exec("UPDATE projects SET deleted_at = NOW() WHERE id = 8888");

    expect($repo->isOwner(8888, 8888))->toBeFalse();
});

test('getOrCreate creates new project when old one is soft-deleted', function () {
    $repo = new \App\Database\ProjectRepository();

    // Soft-delete original project
    $this->db->exec("UPDATE projects SET deleted_at = NOW() WHERE id = 8888");

    // getOrCreate should create a NEW project (not reuse the soft-deleted one)
    $newId = $repo->getOrCreate(8888, 'Delete Test Project');
    expect($newId)->not->toBe(8888);

    // Cleanup
    $this->db->exec("DELETE FROM projects WHERE id = $newId");
});

// ============================================
// Deletion command logic
// ============================================

test('drop_crawl_partitions removes partition tables', function () {
    // Verify partitions exist
    $stmt = $this->db->query("SELECT tablename FROM pg_tables WHERE tablename = 'pages_88888'");
    expect($stmt->fetch())->toBeTruthy();

    // Drop partitions
    $this->db->exec("SELECT drop_crawl_partitions(88888)");

    // Verify partitions are gone
    $stmt = $this->db->query("SELECT tablename FROM pg_tables WHERE tablename = 'pages_88888'");
    expect($stmt->fetch())->toBeFalsy();

    $stmt = $this->db->query("SELECT tablename FROM pg_tables WHERE tablename = 'links_88888'");
    expect($stmt->fetch())->toBeFalsy();

    $stmt = $this->db->query("SELECT tablename FROM pg_tables WHERE tablename = 'html_88888'");
    expect($stmt->fetch())->toBeFalsy();
});

test('deleting status is a valid crawl status', function () {
    // Should not throw a constraint violation
    $this->db->exec("UPDATE crawls SET status = 'deleting' WHERE id = 88888");

    $stmt = $this->db->prepare("SELECT status FROM crawls WHERE id = :id");
    $stmt->execute([':id' => 88888]);
    expect($stmt->fetchColumn())->toBe('deleting');
});

// ============================================
// HTML blob purge when a crawl is deleted
// ============================================

test('deleting a crawl purges its HTML blobs and leaves other crawls untouched', function () {
    // Route the Storage singleton at a temp directory so we can assert on real
    // files. Same factory backs S3 in prod; here we exercise LocalStorage so the
    // test is hermetic. Cmder::deleteCrawl must call deletePrefix("html/<id>/")
    // — otherwise crawl deletion leaks orphan blobs in S3/local.
    $root = sys_get_temp_dir() . '/scouter-delete-html-' . getmypid() . '-' . uniqid();
    \App\Storage\Storage::set(new \App\Storage\LocalStorage($root));

    try {
        // Seed one blob for each test crawl (88888 doomed, 88889 must survive).
        \App\Storage\Storage::instance()->put('html/88888/page-a', '<html>doomed</html>');
        \App\Storage\Storage::instance()->put('html/88889/page-b', '<html>kept</html>');

        // Cmder::deleteCrawl is the worker entry point (scouter.php delete-crawl).
        // arg format = "delete-crawl:<id>". It also drops partitions + DB row.
        \App\Cli\Cmder::deleteCrawl('delete-crawl:88888');

        // Doomed crawl: row + blob both gone.
        $exists = $this->db->query("SELECT COUNT(*) FROM crawls WHERE id = 88888")->fetchColumn();
        expect((int)$exists)->toBe(0);
        expect(\App\Storage\Storage::instance()->get('html/88888/page-a'))->toBeNull();

        // Sibling crawl: row + blob untouched (no collateral damage).
        $exists2 = $this->db->query("SELECT COUNT(*) FROM crawls WHERE id = 88889")->fetchColumn();
        expect((int)$exists2)->toBe(1);
        expect(\App\Storage\Storage::instance()->get('html/88889/page-b'))->toContain('kept');
    } finally {
        // Reset singleton so other tests don't see the temp backend.
        \App\Storage\Storage::set(null);
    }
});
