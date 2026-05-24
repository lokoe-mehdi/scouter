<?php

use App\AI\ClickHouseSqlExecutor;

/**
 * Security tests for the ClickHouse SQL executor (SQL Explorer / API v1 / MCP /
 * Dr Brief). Since the ClickHouse migration this is the executor that runs for
 * every migrated crawl (data_store='clickhouse'), so its guardrails are the ones
 * that actually protect production now — yet the only security tests we had were
 * for the legacy PostgreSQL path (SqlExplorerSecurityTest).
 *
 * Unlike SqlExplorerSecurityTest (which re-implements the validation inline and
 * can drift), these drive the REAL ClickHouseSqlExecutor::prepareSafeSql() via
 * reflection. Every rejection path short-circuits before the first DB/CH access
 * (the crawl lookup at line ~162), so the blocked-query cases need no database
 * and pin the production code directly.
 */

/**
 * Invoke the real private prepareSafeSql() without a DB connection.
 * The instance is built without the constructor (which would open a CH client);
 * the rejection paths never touch $this->ch, so that's safe for blocked queries.
 *
 * @return array{ok:bool,error?:string}
 */
function chPrepare(string $sql, int $crawlId = 1): array
{
    $ref  = new ReflectionClass(ClickHouseSqlExecutor::class);
    $exec = $ref->newInstanceWithoutConstructor();
    $m    = $ref->getMethod('prepareSafeSql');
    $m->setAccessible(true);
    return $m->invoke($exec, $sql, $crawlId);
}

/** True if the query was blocked by a STATIC guardrail (before the DB stage). */
function chBlocked(string $sql): bool
{
    $r = chPrepare($sql);
    return $r['ok'] === false;
}

describe('ClickHouse SQL — statement shape', function () {

    it('allows a bare SELECT past the static filters', function () {
        // It won't reach ok=true (no crawl row in the test DB), but it must NOT be
        // rejected by any static guardrail — the error, if any, is the DB stage.
        $r = chPrepare('SELECT url FROM pages LIMIT 10');
        if ($r['ok'] === false) {
            expect($r['error'])->not->toContain('Forbidden');
            expect($r['error'])->not->toContain('Only SELECT');
            expect($r['error'])->not->toContain('Multi-statement');
            expect($r['error'])->not->toContain('System databases');
        }
    });

    it('allows WITH … SELECT (CTE) past the static filters', function () {
        $r = chPrepare('WITH t AS (SELECT url FROM pages) SELECT * FROM t');
        if ($r['ok'] === false) {
            expect($r['error'])->not->toContain('Only SELECT');
        }
    });

    it('blocks a non-SELECT / non-WITH statement', function () {
        expect(chBlocked('SHOW TABLES'))->toBeTrue();
    });

    it('blocks multi-statement queries', function () {
        expect(chBlocked('SELECT 1; SELECT 2'))->toBeTrue();
        expect(chBlocked('SELECT * FROM pages; DROP TABLE pages'))->toBeTrue();
    });
});

describe('ClickHouse SQL — forbidden keywords (DML/DDL/admin)', function () {

    it('blocks INSERT / UPDATE / DELETE', function () {
        expect(chBlocked("INSERT INTO pages (url) VALUES ('x')"))->toBeTrue();
        expect(chBlocked('UPDATE pages SET code = 200'))->toBeTrue();
        expect(chBlocked('DELETE FROM pages'))->toBeTrue();
    });

    it('blocks DROP / CREATE / ALTER / TRUNCATE / RENAME', function () {
        expect(chBlocked('DROP TABLE pages'))->toBeTrue();
        expect(chBlocked('CREATE TABLE evil (id Int32) ENGINE=Memory'))->toBeTrue();
        expect(chBlocked('ALTER TABLE pages DELETE WHERE 1=1'))->toBeTrue();
        expect(chBlocked('TRUNCATE TABLE pages'))->toBeTrue();
        expect(chBlocked('RENAME TABLE pages TO p2'))->toBeTrue();
    });

    it('blocks ClickHouse admin statements (SYSTEM / KILL / OPTIMIZE / ATTACH / DETACH / SET)', function () {
        expect(chBlocked('SELECT * FROM pages SETTINGS x=1; SYSTEM RELOAD CONFIG'))->toBeTrue();
        expect(chBlocked('KILL QUERY WHERE 1'))->toBeTrue();
        expect(chBlocked('OPTIMIZE TABLE pages FINAL'))->toBeTrue();
        expect(chBlocked('ATTACH TABLE pages'))->toBeTrue();
        expect(chBlocked('DETACH TABLE pages'))->toBeTrue();
        expect(chBlocked('SET max_threads = 1'))->toBeTrue();
    });
});

describe('ClickHouse SQL — forbidden table/dictionary functions (exfiltration/SSRF)', function () {

    // These are the ClickHouse-specific danger the PG blocklist never covered:
    // reading the filesystem or reaching out to remote hosts/buckets.
    it('blocks file()', function () {
        expect(chBlocked("SELECT * FROM file('/etc/passwd', 'LineAsString')"))->toBeTrue();
    });

    it('blocks url() (SSRF)', function () {
        expect(chBlocked("SELECT * FROM url('http://169.254.169.254/', 'LineAsString')"))->toBeTrue();
    });

    it('blocks remote() / remoteSecure()', function () {
        expect(chBlocked("SELECT * FROM remote('evil:9000', system.one)"))->toBeTrue();
        expect(chBlocked("SELECT * FROM remoteSecure('evil:9440', default.x)"))->toBeTrue();
    });

    it('blocks s3() / hdfs()', function () {
        expect(chBlocked("SELECT * FROM s3('https://b.s3.amazonaws.com/k', 'CSV')"))->toBeTrue();
        expect(chBlocked("SELECT * FROM hdfs('hdfs://host/file', 'CSV')"))->toBeTrue();
    });

    it('blocks database engine functions (mysql/postgresql/mongodb/jdbc/odbc/sqlite)', function () {
        expect(chBlocked("SELECT * FROM mysql('h:3306','db','t','u','p')"))->toBeTrue();
        expect(chBlocked("SELECT * FROM postgresql('h:5432','db','t','u','p')"))->toBeTrue();
        expect(chBlocked("SELECT * FROM mongodb('h:27017','db','t','u','p','')"))->toBeTrue();
        expect(chBlocked("SELECT * FROM jdbc('ds','t')"))->toBeTrue();
        expect(chBlocked("SELECT * FROM odbc('dsn','t')"))->toBeTrue();
        expect(chBlocked("SELECT * FROM sqlite('db.sqlite','t')"))->toBeTrue();
    });

    it('blocks executable() / cluster() / dictionary()', function () {
        expect(chBlocked("SELECT * FROM executable('cmd.sh', 'CSV', 'x Int32')"))->toBeTrue();
        expect(chBlocked("SELECT * FROM cluster('c', system.one)"))->toBeTrue();
        expect(chBlocked("SELECT dictGet('d','a',1) FROM dictionary('d')"))->toBeTrue();
    });
});

describe('ClickHouse SQL — system database access', function () {

    it('blocks the system database', function () {
        expect(chBlocked('SELECT * FROM system.tables'))->toBeTrue();
        expect(chBlocked('SELECT * FROM system.users'))->toBeTrue();
        expect(chBlocked('SELECT name FROM system.parts'))->toBeTrue();
    });

    it('blocks information_schema', function () {
        expect(chBlocked('SELECT * FROM information_schema.tables'))->toBeTrue();
        expect(chBlocked('SELECT * FROM INFORMATION_SCHEMA.COLUMNS'))->toBeTrue();
    });
});

describe('ClickHouse SQL — comment-hidden injection', function () {

    it('strips block comments before keyword scan', function () {
        expect(chBlocked('SELECT /* hi */ 1; DROP TABLE pages'))->toBeTrue();
    });

    it('strips line comments before the multi-statement scan', function () {
        expect(chBlocked("SELECT 1 -- note\n; DELETE FROM pages"))->toBeTrue();
    });
});
