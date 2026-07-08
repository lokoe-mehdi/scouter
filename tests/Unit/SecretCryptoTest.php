<?php

use App\Util\SecretCrypto;

/**
 * SecretCrypto — AES-256-GCM at rest for secrets (Google refresh tokens,
 * OpenRouter key). The round-trip and the fail-closed-without-key behaviours are
 * the security contract.
 */

beforeEach(function () {
    $this->originalKey = getenv('SCOUTER_ENCRYPTION_KEY');
    putenv('SCOUTER_ENCRYPTION_KEY=test-secret-for-SecretCryptoTest-key!!');
});

afterEach(function () {
    if ($this->originalKey === false) {
        putenv('SCOUTER_ENCRYPTION_KEY');
    } else {
        putenv('SCOUTER_ENCRYPTION_KEY=' . $this->originalKey);
    }
});

it('round-trips a secret through encrypt/decrypt', function () {
    $secret = '1//0abcDEF_google-refresh-token.example';
    $blob = SecretCrypto::encrypt($secret);
    expect($blob)->toStartWith('enc:v1:');
    expect($blob)->not->toContain($secret);
    expect(SecretCrypto::decrypt($blob))->toBe($secret);
});

it('produces different ciphertexts for the same plaintext (random IV)', function () {
    $a = SecretCrypto::encrypt('same');
    $b = SecretCrypto::encrypt('same');
    expect($a)->not->toBe($b);
    expect(SecretCrypto::decrypt($a))->toBe('same');
    expect(SecretCrypto::decrypt($b))->toBe('same');
});

it('returns a non-prefixed value untouched (legacy plaintext)', function () {
    expect(SecretCrypto::decrypt('plain-value'))->toBe('plain-value');
});

it('fails closed when no encryption key is configured', function () {
    putenv('SCOUTER_ENCRYPTION_KEY');
    expect(SecretCrypto::hasKey())->toBeFalse();
    expect(SecretCrypto::encrypt('x'))->toBeNull();
});

it('cannot decrypt a blob with the wrong key', function () {
    $blob = SecretCrypto::encrypt('secret');
    putenv('SCOUTER_ENCRYPTION_KEY=a-totally-different-key-value-000000');
    expect(SecretCrypto::decrypt($blob))->toBeNull();
});

it('masks a secret keeping only the last 4 chars', function () {
    expect(SecretCrypto::mask('abcdEFGH'))->toBe(str_repeat('•', 20) . 'EFGH');
    expect(SecretCrypto::mask(''))->toBe('');
    expect(SecretCrypto::mask(null))->toBe('');
});
