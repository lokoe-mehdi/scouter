<?php

/**
 * Tests for SQL Explorer security — validates that dangerous queries are blocked.
 *
 * These tests simulate the validation logic from QueryController::execute()
 * without needing a database connection.
 */

/**
 * Simulates the SQL validation from QueryController.
 * Returns null if query passes validation, or the error message if blocked.
 */
function validateSqlQuery(string $query): ?string
{
    $queryClean = preg_replace('/\/\*.*?\*\//s', ' ', $query);
    $queryClean = preg_replace('/--.*$/m', ' ', $queryClean);
    $queryUpper = strtoupper(trim($queryClean));

    if (strpos($queryUpper, 'SELECT') !== 0) {
        return 'Not a SELECT';
    }

    if (preg_match('/;\s*(INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|TRUNCATE|GRANT|REVOKE|COPY|SET|DO|CALL)/i', $queryClean)) {
        return 'Multi-statement';
    }

    $forbiddenKeywords = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER',
        'TRUNCATE', 'REPLACE', 'RENAME', 'ATTACH', 'DETACH',
        'VACUUM', 'REINDEX', 'GRANT', 'REVOKE', 'COPY'
    ];
    foreach ($forbiddenKeywords as $keyword) {
        if (preg_match('/\b' . $keyword . '\b/i', $queryClean)) {
            return 'Forbidden keyword: ' . $keyword;
        }
    }

    $forbiddenFunctions = [
        'pg_sleep', 'pg_read_file', 'pg_read_binary_file', 'pg_ls_dir',
        'pg_stat_file', 'lo_import', 'lo_export', 'dblink',
        'pg_advisory_lock', 'pg_terminate_backend', 'pg_cancel_backend',
        'set_config', 'current_setting'
    ];
    foreach ($forbiddenFunctions as $fn) {
        if (preg_match('/\b' . preg_quote($fn, '/') . '\s*\(/i', $queryClean)) {
            return 'Forbidden function: ' . $fn;
        }
    }

    if (preg_match('/\b(pg_catalog|information_schema|pg_roles|pg_authid|pg_shadow|users)\b/i', $queryClean)) {
        return 'System table access';
    }

    return null; // Passed
}

describe('SQL Explorer — Valid queries', function () {

    it('allows basic SELECT', function () {
        expect(validateSqlQuery('SELECT * FROM pages LIMIT 10'))->toBeNull();
    });

    it('allows SELECT with JOIN', function () {
        expect(validateSqlQuery('SELECT p.url, c.cat FROM pages p LEFT JOIN crawl_categories c ON c.id = p.cat_id'))->toBeNull();
    });

    it('allows SELECT with subquery', function () {
        expect(validateSqlQuery('SELECT url FROM pages WHERE cat_id IN (SELECT id FROM crawl_categories)'))->toBeNull();
    });

    it('allows SELECT with aggregation', function () {
        expect(validateSqlQuery('SELECT code, COUNT(*) FROM pages GROUP BY code ORDER BY COUNT(*) DESC'))->toBeNull();
    });

    it('allows SELECT with CASE WHEN', function () {
        expect(validateSqlQuery("SELECT CASE WHEN code = 200 THEN 'OK' ELSE 'Error' END FROM pages"))->toBeNull();
    });

    it('allows COALESCE and standard functions', function () {
        expect(validateSqlQuery('SELECT COALESCE(title, h1, url), LENGTH(url) FROM pages'))->toBeNull();
    });
});

describe('SQL Explorer — Blocked DDL/DML', function () {

    it('blocks INSERT', function () {
        expect(validateSqlQuery("INSERT INTO pages (url) VALUES ('test')"))->not->toBeNull();
    });

    it('blocks UPDATE', function () {
        expect(validateSqlQuery("UPDATE pages SET code = 200"))->not->toBeNull();
    });

    it('blocks DELETE', function () {
        expect(validateSqlQuery("DELETE FROM pages WHERE id = 1"))->not->toBeNull();
    });

    it('blocks DROP TABLE', function () {
        expect(validateSqlQuery("DROP TABLE pages"))->not->toBeNull();
    });

    it('blocks CREATE TABLE', function () {
        expect(validateSqlQuery("CREATE TABLE evil (id INT)"))->not->toBeNull();
    });

    it('blocks ALTER TABLE', function () {
        expect(validateSqlQuery("ALTER TABLE pages ADD COLUMN hack TEXT"))->not->toBeNull();
    });

    it('blocks TRUNCATE', function () {
        expect(validateSqlQuery("TRUNCATE pages"))->not->toBeNull();
    });

    it('blocks GRANT', function () {
        expect(validateSqlQuery("GRANT ALL ON pages TO public"))->not->toBeNull();
    });

    it('blocks COPY', function () {
        expect(validateSqlQuery("COPY pages TO '/tmp/dump.csv'"))->not->toBeNull();
    });
});

describe('SQL Explorer — Blocked injection attempts', function () {

    it('blocks multi-statement with DROP', function () {
        expect(validateSqlQuery("SELECT 1; DROP TABLE users; --"))->not->toBeNull();
    });

    it('blocks multi-statement with INSERT', function () {
        expect(validateSqlQuery("SELECT * FROM pages; INSERT INTO users VALUES (1, 'hack')"))->not->toBeNull();
    });

    it('blocks comment-hidden DROP', function () {
        // Block comments are stripped before keyword check
        expect(validateSqlQuery("SELECT /* hidden */ 1; DROP TABLE pages"))->not->toBeNull();
    });

    it('blocks line-comment-hidden attack', function () {
        expect(validateSqlQuery("SELECT 1 -- comment\n; DELETE FROM pages"))->not->toBeNull();
    });
});

describe('SQL Explorer — Blocked dangerous functions', function () {

    it('blocks pg_sleep (DoS)', function () {
        expect(validateSqlQuery("SELECT pg_sleep(30)"))->not->toBeNull();
    });

    it('blocks pg_read_file (file read)', function () {
        expect(validateSqlQuery("SELECT pg_read_file('/etc/passwd')"))->not->toBeNull();
    });

    it('blocks pg_ls_dir (directory listing)', function () {
        expect(validateSqlQuery("SELECT pg_ls_dir('/etc')"))->not->toBeNull();
    });

    it('blocks lo_export (large object export)', function () {
        expect(validateSqlQuery("SELECT lo_export(12345, '/tmp/dump')"))->not->toBeNull();
    });

    it('blocks dblink (remote query)', function () {
        expect(validateSqlQuery("SELECT * FROM dblink('host=evil', 'SELECT 1')"))->not->toBeNull();
    });

    it('blocks pg_terminate_backend', function () {
        expect(validateSqlQuery("SELECT pg_terminate_backend(1234)"))->not->toBeNull();
    });

    it('blocks set_config', function () {
        expect(validateSqlQuery("SELECT set_config('log_statement', 'all', false)"))->not->toBeNull();
    });
});

describe('SQL Explorer — Blocked system table access', function () {

    it('blocks users table', function () {
        expect(validateSqlQuery("SELECT * FROM users"))->not->toBeNull();
    });

    it('blocks pg_roles', function () {
        expect(validateSqlQuery("SELECT rolname, rolpassword FROM pg_roles"))->not->toBeNull();
    });

    it('blocks information_schema', function () {
        expect(validateSqlQuery("SELECT * FROM information_schema.tables"))->not->toBeNull();
    });

    it('blocks pg_authid', function () {
        expect(validateSqlQuery("SELECT * FROM pg_authid"))->not->toBeNull();
    });

    it('blocks pg_shadow', function () {
        expect(validateSqlQuery("SELECT * FROM pg_shadow"))->not->toBeNull();
    });

    it('blocks pg_catalog prefix', function () {
        expect(validateSqlQuery("SELECT * FROM pg_catalog.pg_class"))->not->toBeNull();
    });
});
