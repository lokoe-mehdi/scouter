<?php

use App\Analysis\ReportPrecompute;
use App\Database\PostgresDatabase;

/**
 * Tests for the report-precompute refresh routine that keeps the precomputed,
 * category-dependent report fragments (crawl_report_cache) in sync after a
 * categorization change.
 *
 * Regression context: saving a categorization from the API/MCP
 * (ApiV1Controller::setCategorization) used to skip this routine entirely, so
 * the reports kept rendering the OLD categories. These tests pin down both that
 * the routine actually rewrites a stored fragment, and that the API path now
 * invokes it (mirroring the UI save flow).
 *
 * DB-backed (Postgres). ClickHouse is disabled in tests, so recompute uses the
 * PG report shim — we keep the stored query off the `pages` table so it needs
 * no partitions.
 */

beforeEach(function () {
    $this->db = PostgresDatabase::getInstance()->getConnection();

    $this->email = 'precompute-' . uniqid() . '@example.test';
    $this->db->prepare("INSERT INTO users (email, password_hash, role) VALUES (:e, 'x', 'admin')")
        ->execute([':e' => $this->email]);
    $this->uid = (int) $this->db->query("SELECT id FROM users WHERE email = " . $this->db->quote($this->email))->fetchColumn();

    $this->pid = (int) $this->db->query(
        "INSERT INTO projects (user_id, name) VALUES ({$this->uid}, 'precompute-proj') RETURNING id"
    )->fetchColumn();

    $this->cid = (int) $this->db->query(
        "INSERT INTO crawls (project_id, domain, path, status, config)
         VALUES ({$this->pid}, 'precompute.test', 'precompute-test-{$this->pid}', 'finished', '{}') RETURNING id"
    )->fetchColumn();
});

afterEach(function () {
    $this->db->exec("DELETE FROM crawl_report_cache WHERE crawl_id = " . (int) $this->cid);
    $this->db->exec("DELETE FROM crawl_categories WHERE project_id = " . (int) $this->pid);
    $this->db->exec("DELETE FROM crawls WHERE id = " . (int) $this->cid);
    $this->db->exec("DELETE FROM projects WHERE id = " . (int) $this->pid);
    $this->db->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $this->uid]);
});

/** Seed a stored fragment whose query counts the project's categories. */
function seedFragment(PDO $db, int $crawlId, int $projectId, string $key, int $stale, int $categoryDependent): void
{
    $sql    = 'SELECT count(*)::int AS c FROM crawl_categories WHERE project_id = :pid';
    $params = json_encode([':pid' => $projectId]);
    $payload = json_encode([['c' => $stale]]);
    $db->prepare("
        INSERT INTO crawl_report_cache (crawl_id, report_key, payload, query_sql, query_params, category_dependent, updated_at)
        VALUES (:c, :k, :p, :sql, :prm, :cd, CURRENT_TIMESTAMP)
        ON CONFLICT (crawl_id, report_key) DO UPDATE SET payload = :p2, query_sql = :sql2, query_params = :prm2, category_dependent = :cd2
    ")->execute([
        ':c' => $crawlId, ':k' => $key, ':p' => $payload, ':sql' => $sql, ':prm' => $params, ':cd' => $categoryDependent,
        ':p2' => $payload, ':sql2' => $sql, ':prm2' => $params, ':cd2' => $categoryDependent,
    ]);
}

function cachedCount(PDO $db, int $crawlId, string $key): ?int
{
    $raw = $db->query("SELECT payload FROM crawl_report_cache WHERE crawl_id = {$crawlId} AND report_key = " . $db->quote($key))->fetchColumn();
    if ($raw === false || $raw === null) return null;
    $rows = json_decode((string) $raw, true);
    return isset($rows[0]['c']) ? (int) $rows[0]['c'] : null;
}

it('rewrites a category-dependent fragment so reports reflect the new categories', function () {
    // Stored fragment says 0; reality (after a categorization change) is 2.
    seedFragment($this->db, $this->cid, $this->pid, 'codes_by_category', 0, 1);
    $this->db->exec("INSERT INTO crawl_categories (project_id, cat, color) VALUES ({$this->pid}, 'blog', '#111'), ({$this->pid}, 'product', '#222')");

    expect(cachedCount($this->db, $this->cid, 'codes_by_category'))->toBe(0); // stale before

    ReportPrecompute::recompute($this->cid, true);

    // Recompute re-ran the stored query and rewrote the cached payload in place.
    expect(cachedCount($this->db, $this->cid, 'codes_by_category'))->toBe(2);
});

it('leaves non-category-dependent fragments untouched when only refreshing category ones', function () {
    seedFragment($this->db, $this->cid, $this->pid, 'static_fragment', 0, 0); // category_dependent = 0
    $this->db->exec("INSERT INTO crawl_categories (project_id, cat, color) VALUES ({$this->pid}, 'blog', '#111')");

    ReportPrecompute::recompute($this->cid, true); // onlyCategoryDependent = true

    // cd=0 entries are not re-executed in the category-only pass → stays stale.
    expect(cachedCount($this->db, $this->cid, 'static_fragment'))->toBe(0);

    // A full recompute DOES refresh it.
    ReportPrecompute::recompute($this->cid, false);
    expect(cachedCount($this->db, $this->cid, 'static_fragment'))->toBe(1);
});

describe('API categorization triggers the report-precompute routine (regression guard)', function () {

    it('ApiV1Controller::setCategorization recomputes this crawl and queues the project job', function () {
        $src = file_get_contents(__DIR__ . '/../../app/Http/Controllers/ApiV1Controller.php');
        // Isolate the setCategorization method body.
        $start = strpos($src, 'public function setCategorization');
        expect($start)->not->toBeFalse();
        // Next public method after it marks the end of the body.
        $end = strpos($src, 'public function crawlStatus', $start);
        $body = substr($src, $start, $end - $start);

        // Synchronous refresh of the current crawl's category-dependent fragments…
        expect($body)->toContain('ReportPrecompute::recompute');
        // …and the async project-wide refresh job, same as the UI save flow.
        expect($body)->toContain('precompute-reports-project:');
    });
});
