<?php

use App\Api\OAuthServer;
use App\Api\ApiKeyService;
use App\Database\PostgresDatabase;

/**
 * Unit tests for the OAuth 2.0 Authorization Server backing the MCP connector.
 * DB-backed: a throwaway user consents; we assert metadata shape, PKCE, dynamic
 * registration, and the full authorization-code → access-token exchange — where
 * the access token must be a working `sctr_` key bound to that user.
 */

beforeEach(function () {
    $this->db = PostgresDatabase::getInstance()->getConnection();
    $this->email = 'oauth-test-' . uniqid() . '@example.test';
    $this->db->prepare("INSERT INTO users (email, password_hash, role) VALUES (:e, 'x', 'user')")
        ->execute([':e' => $this->email]);
    $this->uid = (int) $this->db->query("SELECT id FROM users WHERE email = " . $this->db->quote($this->email))->fetchColumn();

    // PKCE pair (S256).
    $this->verifier  = 'verifier-' . bin2hex(random_bytes(24));
    $this->challenge = rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '=');
});

afterEach(function () {
    $this->db->exec("DELETE FROM oauth_auth_codes WHERE user_id = " . (int) $this->uid);
    $this->db->exec("DELETE FROM api_keys WHERE user_id = " . (int) $this->uid);
    $this->db->exec("DELETE FROM oauth_clients WHERE client_name = 'pest-client'");
    $this->db->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $this->uid]);
});

function registerPestClient(string $redirect = 'https://claude.ai/api/mcp/auth_callback'): array
{
    return OAuthServer::registerClient(['client_name' => 'pest-client', 'redirect_uris' => [$redirect]]);
}

it('advertises the expected authorization-server metadata', function () {
    $m = OAuthServer::authServerMetadata('https://host.example');
    expect($m['issuer'])->toBe('https://host.example');
    expect($m['authorization_endpoint'])->toBe('https://host.example/oauth/authorize');
    expect($m['token_endpoint'])->toBe('https://host.example/oauth/token');
    expect($m['registration_endpoint'])->toBe('https://host.example/oauth/register');
    expect($m['code_challenge_methods_supported'])->toBe(['S256']);
    expect($m['response_types_supported'])->toBe(['code']);
});

it('points protected-resource metadata at the MCP endpoint + AS', function () {
    $m = OAuthServer::protectedResourceMetadata('https://host.example');
    expect($m['resource'])->toBe('https://host.example/mcp');
    expect($m['authorization_servers'])->toBe(['https://host.example']);
});

it('verifies a correct PKCE verifier and rejects a wrong one', function () {
    expect(OAuthServer::verifyPkce($this->verifier, $this->challenge))->toBeTrue();
    expect(OAuthServer::verifyPkce('not-the-verifier', $this->challenge))->toBeFalse();
});

it('registers a client via DCR and finds it back', function () {
    $reg = registerPestClient();
    expect($reg['client_id'])->toStartWith('mcp_');
    expect($reg['token_endpoint_auth_method'])->toBe('none');

    $client = OAuthServer::findClient($reg['client_id']);
    expect($client)->not->toBeNull();
    expect(OAuthServer::redirectUriAllowed($client, 'https://claude.ai/api/mcp/auth_callback'))->toBeTrue();
    expect(OAuthServer::redirectUriAllowed($client, 'https://evil.example/callback'))->toBeFalse();
});

it('rejects registration without a valid redirect_uri', function () {
    expect(OAuthServer::registerClient([])['error'])->toBe('invalid_redirect_uri');
    expect(OAuthServer::registerClient(['redirect_uris' => ['not-a-url']])['error'])->toBe('invalid_redirect_uri');
});

it('exchanges an auth code for a working sctr_ access token', function () {
    $reg = registerPestClient();
    $redirect = 'https://claude.ai/api/mcp/auth_callback';
    $code = OAuthServer::issueCode($reg['client_id'], $this->uid, $redirect, $this->challenge, 'mcp');

    $res = OAuthServer::exchangeCode([
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => $redirect,
        'client_id'     => $reg['client_id'],
        'code_verifier' => $this->verifier,
    ]);

    expect($res['token_type'])->toBe('Bearer');
    expect($res['access_token'])->toStartWith('sctr_');

    // The issued token must authenticate as our user via the normal API path.
    $verified = ApiKeyService::verify($res['access_token']);
    expect($verified)->not->toBeNull();
    expect((int) $verified['user']->id)->toBe($this->uid);
});

it('issues a token that preserves a read-only viewer role (no privilege escalation)', function () {
    // A viewer must be able to connect the MCP connector, but the resulting key
    // must keep acting as a viewer downstream — never gain write access.
    $email = 'oauth-viewer-' . uniqid() . '@example.test';
    $this->db->prepare("INSERT INTO users (email, password_hash, role) VALUES (:e, 'x', 'viewer')")
        ->execute([':e' => $email]);
    $vid = (int) $this->db->query("SELECT id FROM users WHERE email = " . $this->db->quote($email))->fetchColumn();

    try {
        $reg = registerPestClient();
        $redirect = 'https://claude.ai/api/mcp/auth_callback';
        $code = OAuthServer::issueCode($reg['client_id'], $vid, $redirect, $this->challenge, 'mcp');

        $res = OAuthServer::exchangeCode([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirect,
            'client_id'     => $reg['client_id'],
            'code_verifier' => $this->verifier,
        ]);
        expect($res['access_token'])->toStartWith('sctr_');

        $verified = ApiKeyService::verify($res['access_token']);
        expect($verified)->not->toBeNull();
        expect((int) $verified['user']->id)->toBe($vid);
        expect($verified['user']->role)->toBe('viewer');
    } finally {
        $this->db->exec("DELETE FROM oauth_auth_codes WHERE user_id = " . $vid);
        $this->db->exec("DELETE FROM api_keys WHERE user_id = " . $vid);
        $this->db->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $vid]);
    }
});

it('rejects a code exchange with a bad PKCE verifier', function () {
    $reg = registerPestClient();
    $redirect = 'https://claude.ai/api/mcp/auth_callback';
    $code = OAuthServer::issueCode($reg['client_id'], $this->uid, $redirect, $this->challenge, 'mcp');

    $res = OAuthServer::exchangeCode([
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => $redirect,
        'client_id'     => $reg['client_id'],
        'code_verifier' => 'wrong-verifier',
    ]);
    expect($res['error'])->toBe('invalid_grant');
});

it('rejects a redirect_uri mismatch at token time', function () {
    $reg = registerPestClient();
    $code = OAuthServer::issueCode($reg['client_id'], $this->uid, 'https://claude.ai/api/mcp/auth_callback', $this->challenge, 'mcp');

    $res = OAuthServer::exchangeCode([
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => 'https://claude.ai/other',
        'client_id'     => $reg['client_id'],
        'code_verifier' => $this->verifier,
    ]);
    expect($res['error'])->toBe('invalid_grant');
});

it('burns the code: a second exchange fails', function () {
    $reg = registerPestClient();
    $redirect = 'https://claude.ai/api/mcp/auth_callback';
    $code = OAuthServer::issueCode($reg['client_id'], $this->uid, $redirect, $this->challenge, 'mcp');
    $params = [
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => $redirect,
        'client_id'     => $reg['client_id'],
        'code_verifier' => $this->verifier,
    ];
    expect(OAuthServer::exchangeCode($params))->toHaveKey('access_token');
    expect(OAuthServer::exchangeCode($params)['error'])->toBe('invalid_grant'); // replay
});

it('rejects an expired code', function () {
    $reg = registerPestClient();
    $redirect = 'https://claude.ai/api/mcp/auth_callback';
    $code = 'expired-' . bin2hex(random_bytes(8));
    $this->db->prepare("
        INSERT INTO oauth_auth_codes (code, client_id, user_id, redirect_uri, code_challenge, scope, expires_at)
        VALUES (:c, :cid, :uid, :ruri, :cc, 'mcp', CURRENT_TIMESTAMP - INTERVAL '10 seconds')
    ")->execute([':c' => $code, ':cid' => $reg['client_id'], ':uid' => $this->uid, ':ruri' => $redirect, ':cc' => $this->challenge]);

    $res = OAuthServer::exchangeCode([
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => $redirect,
        'client_id'     => $reg['client_id'],
        'code_verifier' => $this->verifier,
    ]);
    expect($res['error'])->toBe('invalid_grant');
});

it('rejects an unsupported grant type', function () {
    expect(OAuthServer::exchangeCode(['grant_type' => 'client_credentials'])['error'])->toBe('unsupported_grant_type');
});
