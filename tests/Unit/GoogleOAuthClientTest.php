<?php

use App\Google\GoogleOAuthClient;

/**
 * GoogleOAuthClient — the pure bits of the OAuth flow (URL building, PKCE,
 * id_token decoding). Network calls (token exchange / refresh) are not unit
 * tested here.
 */

beforeEach(function () {
    $this->cid = getenv('GOOGLE_OAUTH_CLIENT_ID');
    $this->redir = getenv('GOOGLE_OAUTH_REDIRECT_URI');
    putenv('GOOGLE_OAUTH_CLIENT_ID=test-client.apps.googleusercontent.com');
    putenv('GOOGLE_OAUTH_CLIENT_SECRET=test-secret');
    putenv('GOOGLE_OAUTH_REDIRECT_URI=http://localhost:8080/gsc/callback');
});

afterEach(function () {
    foreach ([['GOOGLE_OAUTH_CLIENT_ID', $this->cid], ['GOOGLE_OAUTH_REDIRECT_URI', $this->redir]] as [$k, $v]) {
        $v === false ? putenv($k) : putenv("$k=$v");
    }
    putenv('GOOGLE_OAUTH_CLIENT_SECRET');
});

it('reports configured when the three env vars are set', function () {
    expect(GoogleOAuthClient::isConfigured())->toBeTrue();
});

it('builds an auth URL with offline access, consent and the webmasters scope', function () {
    $url = GoogleOAuthClient::buildAuthUrl('state123', 'challengeXYZ');
    expect($url)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?');
    parse_str(parse_url($url, PHP_URL_QUERY), $q);
    expect($q['client_id'])->toBe('test-client.apps.googleusercontent.com');
    expect($q['redirect_uri'])->toBe('http://localhost:8080/gsc/callback');
    expect($q['access_type'])->toBe('offline');
    expect($q['prompt'])->toBe('consent');
    expect($q['response_type'])->toBe('code');
    expect($q['code_challenge'])->toBe('challengeXYZ');
    expect($q['code_challenge_method'])->toBe('S256');
    expect($q['state'])->toBe('state123');
    expect($q['scope'])->toContain('webmasters.readonly');
});

it('generates a valid PKCE pair (challenge = base64url(sha256(verifier)))', function () {
    $pkce = GoogleOAuthClient::makePkce();
    expect($pkce)->toHaveKeys(['verifier', 'challenge']);

    $expected = rtrim(strtr(base64_encode(hash('sha256', $pkce['verifier'], true)), '+/', '-_'), '=');
    expect($pkce['challenge'])->toBe($expected);
    // base64url: no +, /, or = padding
    expect($pkce['challenge'])->not->toContain('=');
    expect($pkce['challenge'])->not->toContain('+');
    expect($pkce['challenge'])->not->toContain('/');
});

it('decodes email/sub claims from an id_token payload', function () {
    $b64url = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $payload = $b64url(json_encode(['email' => 'user@example.com', 'sub' => '12345']));
    $jwt = $b64url('{"alg":"RS256"}') . '.' . $payload . '.sig';

    $claims = GoogleOAuthClient::decodeIdToken($jwt);
    expect($claims['email'])->toBe('user@example.com');
    expect($claims['sub'])->toBe('12345');
});

it('returns an empty array for a malformed id_token', function () {
    expect(GoogleOAuthClient::decodeIdToken('not-a-jwt'))->toBe([]);
});
