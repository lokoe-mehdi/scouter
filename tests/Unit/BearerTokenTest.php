<?php

use App\Http\Router;

/**
 * Router::bearerToken() extracts the API token from the Authorization header.
 * It's private+static, so we exercise it via reflection across the header
 * quirks it must tolerate (nginx HTTP_AUTHORIZATION, the REDIRECT_ fallback,
 * case-insensitive scheme) and the malformed cases it must reject.
 */

function callBearerToken(): ?string
{
    $m = new ReflectionMethod(Router::class, 'bearerToken');
    $m->setAccessible(true);
    return $m->invoke(null);
}

beforeEach(function () {
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
});

afterEach(function () {
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
});

it('extracts a Bearer token from HTTP_AUTHORIZATION', function () {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer sctr_abc123';
    expect(callBearerToken())->toBe('sctr_abc123');
});

it('is case-insensitive on the scheme and trims whitespace', function () {
    $_SERVER['HTTP_AUTHORIZATION'] = '  bearer    sctr_xyz  ';
    expect(callBearerToken())->toBe('sctr_xyz');
});

it('falls back to REDIRECT_HTTP_AUTHORIZATION', function () {
    $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer sctr_redirect';
    expect(callBearerToken())->toBe('sctr_redirect');
});

it('returns null when the header is missing', function () {
    expect(callBearerToken())->toBeNull();
});

it('returns null for a non-Bearer scheme', function () {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';
    expect(callBearerToken())->toBeNull();
});

it('returns null when the scheme is present but the token is empty', function () {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ';
    expect(callBearerToken())->toBeNull();
});
