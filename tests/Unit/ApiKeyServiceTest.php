<?php

use App\Api\ApiKeyService;
use App\Database\PostgresDatabase;

/**
 * Unit tests for ApiKeyService — the security core of the public API.
 *
 * DB-backed: a throwaway user owns the keys; we assert the generate→verify
 * round-trip, that wrong/tampered/revoked tokens are rejected, and that listing
 * never leaks the token. Cleaned up afterwards.
 */

beforeEach(function () {
    $this->db = PostgresDatabase::getInstance()->getConnection();
    $this->email = 'apikey-test-' . uniqid() . '@example.test';
    $this->db->prepare("INSERT INTO users (email, password_hash, role) VALUES (:e, 'x', 'admin')")
        ->execute([':e' => $this->email]);
    $this->uid = (int)$this->db->query(
        "SELECT id FROM users WHERE email = " . $this->db->quote($this->email)
    )->fetchColumn();
});

afterEach(function () {
    $this->db->exec("DELETE FROM api_keys WHERE user_id = " . (int)$this->uid);
    $this->db->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $this->uid]);
});

it('generates a sctr_-prefixed token and verifies it back to the owner', function () {
    $g = ApiKeyService::generate($this->uid, 'MCP server');
    expect($g['token'])->toStartWith('sctr_');
    expect($g['prefix'])->toBe(substr($g['token'], 0, 12));

    $v = ApiKeyService::verify($g['token']);
    expect($v)->not->toBeNull();
    expect((int)$v['user']->id)->toBe($this->uid);
    expect($v['key_id'])->toBe($g['id']);
});

it('rejects a wrong or tampered token', function () {
    $g = ApiKeyService::generate($this->uid, 'k');
    expect(ApiKeyService::verify('sctr_definitely-not-a-real-token'))->toBeNull();
    expect(ApiKeyService::verify($g['token'] . 'x'))->toBeNull();   // tampered
    expect(ApiKeyService::verify('no-prefix-token'))->toBeNull();    // missing prefix
    expect(ApiKeyService::verify(null))->toBeNull();
});

it('ignores a revoked key', function () {
    $g = ApiKeyService::generate($this->uid, 'k');
    expect(ApiKeyService::verify($g['token']))->not->toBeNull();
    expect(ApiKeyService::revoke($g['id'], $this->uid))->toBeTrue();
    expect(ApiKeyService::verify($g['token']))->toBeNull();
});

it('does not let a user revoke another user\'s key', function () {
    $g = ApiKeyService::generate($this->uid, 'k');
    expect(ApiKeyService::revoke($g['id'], $this->uid + 999999))->toBeFalse();
    expect(ApiKeyService::verify($g['token']))->not->toBeNull(); // still active
});

it('lists active keys as metadata only — never the token', function () {
    ApiKeyService::generate($this->uid, 'one');
    ApiKeyService::generate($this->uid, 'two');
    $keys = ApiKeyService::listForUser($this->uid);
    expect($keys)->toHaveCount(2);
    foreach ($keys as $k) {
        expect($k)->toHaveKeys(['id', 'name', 'prefix', 'last_used_at', 'created_at']);
        expect($k)->not->toHaveKey('token');
        expect($k)->not->toHaveKey('token_hash');
    }
});

it('never stores the plaintext token in the database', function () {
    $g = ApiKeyService::generate($this->uid, 'k');
    $raw = $this->db->query("SELECT token_hash FROM api_keys WHERE id = " . (int)$g['id'])->fetchColumn();
    expect($raw)->not->toBe($g['token']);
    expect($raw)->toBe(hash('sha256', $g['token']));
});

it('stamps last_used_at on first use (null → set)', function () {
    $g = ApiKeyService::generate($this->uid, 'k');
    $before = $this->db->query("SELECT last_used_at FROM api_keys WHERE id = " . (int)$g['id'])->fetchColumn();
    expect($before)->toBeNull();

    ApiKeyService::touchLastUsed($g['id']);
    $after = $this->db->query("SELECT last_used_at FROM api_keys WHERE id = " . (int)$g['id'])->fetchColumn();
    expect($after)->not->toBeNull();
});
