<?php

/**
 * Tests for security fixes — Open Redirect, CSRF, Export filter injection
 */

describe('Open Redirect Protection', function () {

    /**
     * Simulates the redirect validation from login.php
     */
    function safeRedirect(string $redirectRaw): string
    {
        if (!empty($redirectRaw) && !preg_match('#^https?://#i', $redirectRaw) && !str_starts_with($redirectRaw, '//')) {
            return $redirectRaw;
        }
        return '';
    }

    it('allows relative paths', function () {
        expect(safeRedirect('index.php'))->toBe('index.php');
        expect(safeRedirect('dashboard.php?crawl=42'))->toBe('dashboard.php?crawl=42');
        expect(safeRedirect('project.php?id=1'))->toBe('project.php?id=1');
    });

    it('blocks absolute HTTP URLs', function () {
        expect(safeRedirect('https://evil.com'))->toBe('');
        expect(safeRedirect('http://phishing.site/login'))->toBe('');
        expect(safeRedirect('HTTPS://EVIL.COM'))->toBe('');
    });

    it('blocks protocol-relative URLs', function () {
        expect(safeRedirect('//evil.com/path'))->toBe('');
    });

    it('returns empty for empty input', function () {
        expect(safeRedirect(''))->toBe('');
    });

    it('allows paths with dots and slashes', function () {
        expect(safeRedirect('pages/../admin.php'))->toBe('pages/../admin.php');
        expect(safeRedirect('/web/dashboard.php'))->toBe('/web/dashboard.php');
    });
});

describe('Export Filter Column Validation', function () {

    /**
     * Simulates the column whitelist validation from ExportController
     */
    function isValidColumnName(string $field): bool
    {
        return (bool) preg_match('/^[a-z_][a-z0-9_]*$/i', $field);
    }

    it('allows valid column names', function () {
        expect(isValidColumnName('url'))->toBeTrue();
        expect(isValidColumnName('depth'))->toBeTrue();
        expect(isValidColumnName('cat_id'))->toBeTrue();
        expect(isValidColumnName('response_time'))->toBeTrue();
        expect(isValidColumnName('h1_status'))->toBeTrue();
        expect(isValidColumnName('word_count'))->toBeTrue();
    });

    it('blocks SQL injection in field names', function () {
        expect(isValidColumnName("1=1 UNION SELECT password_hash FROM users--"))->toBeFalse();
        expect(isValidColumnName("url; DROP TABLE users"))->toBeFalse();
        expect(isValidColumnName("url' OR '1'='1"))->toBeFalse();
        expect(isValidColumnName("col LIKE '%"))->toBeFalse();
    });

    it('blocks spaces in column names', function () {
        expect(isValidColumnName('url name'))->toBeFalse();
    });

    it('blocks special characters', function () {
        expect(isValidColumnName('url()'))->toBeFalse();
        expect(isValidColumnName('col*'))->toBeFalse();
        expect(isValidColumnName('col.name'))->toBeFalse();
    });
});
