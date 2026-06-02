<?php

/**
 * Guard tests for the authorization options declared on the REST routes in
 * web/api/index.php.
 *
 * The MCP & public API are meant to be usable by EVERY authenticated user
 * (each acting strictly within their own role/permissions). Personal API-key
 * management (/keys) was opened from admin-only to any logged-in user, while
 * genuinely administrative surfaces (/users, /settings) MUST stay admin-only.
 *
 * These tests parse the route table statically (no DB) so a regression that
 * re-locks key management — or accidentally unlocks user/settings management —
 * fails loudly.
 */

/** Extract the options array literal declared for a given METHOD + path. */
function routeOptionsFor(string $routes, string $method, string $path): ?string
{
    // Matches e.g. $router->get('/keys', [Ctrl::class, 'm'], ['auth' => true]);
    $p = preg_quote($path, '#');
    $re = '#\$router->' . strtolower($method) . '\(\s*\'' . $p . '\'\s*,\s*\[[^\]]*\]\s*,\s*(\[[^\]]*\])\s*\)#';
    return preg_match($re, $routes, $m) ? $m[1] : null;
}

beforeEach(function () {
    // The route table is plain source we assert against (no DB needed).
    $this->routes = file_get_contents(__DIR__ . '/../../web/api/index.php');
});

describe('API key management routes — open to all authenticated users', function () {

    it('GET /keys requires auth but is NOT admin-only', function () {
        $opts = routeOptionsFor($this->routes, 'get', '/keys');
        expect($opts)->not->toBeNull();
        expect($opts)->toContain("'auth' => true");
        expect($opts)->not->toContain("'admin' => true");
    });

    it('POST /keys requires auth but is NOT admin-only', function () {
        $opts = routeOptionsFor($this->routes, 'post', '/keys');
        expect($opts)->not->toBeNull();
        expect($opts)->toContain("'auth' => true");
        expect($opts)->not->toContain("'admin' => true");
    });

    it('DELETE /keys/{id} requires auth but is NOT admin-only', function () {
        $opts = routeOptionsFor($this->routes, 'delete', '/keys/{id}');
        expect($opts)->not->toBeNull();
        expect($opts)->toContain("'auth' => true");
        expect($opts)->not->toContain("'admin' => true");
    });
});

describe('Administrative routes stay admin-only (regression guard)', function () {

    it('GET /users is still admin-only', function () {
        $opts = routeOptionsFor($this->routes, 'get', '/users');
        expect($opts)->not->toBeNull();
        expect($opts)->toContain("'admin' => true");
    });

    it('POST /users is still admin-only', function () {
        $opts = routeOptionsFor($this->routes, 'post', '/users');
        expect($opts)->not->toBeNull();
        expect($opts)->toContain("'admin' => true");
    });

    it('GET /settings is still admin-only', function () {
        $opts = routeOptionsFor($this->routes, 'get', '/settings');
        expect($opts)->not->toBeNull();
        expect($opts)->toContain("'admin' => true");
    });

    it('POST /settings/budget is still admin-only', function () {
        $opts = routeOptionsFor($this->routes, 'post', '/settings/budget');
        expect($opts)->not->toBeNull();
        expect($opts)->toContain("'admin' => true");
    });
});

describe('Public API v1 stays Bearer-token authenticated (no session, no admin gate)', function () {

    it('every /v1/ route is declared with the token guard', function () {
        // Pull every $router->verb('/v1/...', ..., [opts]) line and check opts.
        preg_match_all(
            '#\$router->\w+\(\s*\'(/v1/[^\']*)\'\s*,\s*\[[^\]]*\]\s*,\s*(\[[^\]]*\])\s*\)#',
            $this->routes,
            $m,
            PREG_SET_ORDER
        );
        expect(count($m))->toBeGreaterThan(0);
        foreach ($m as $route) {
            [$_, $path, $opts] = $route;
            // Every public API route is Bearer-token authenticated…
            expect($opts)->toContain("'token' => true");
            // …and never also locked behind a cookie-session admin gate.
            expect($opts)->not->toContain("'admin' => true");
        }
    });
});
