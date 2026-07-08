<?php

namespace App\Util;

/**
 * Symmetric encryption for secrets stored at rest (AES-256-GCM).
 *
 * The 32-byte key is derived deterministically (sha256) from the
 * SCOUTER_ENCRYPTION_KEY environment variable, so rotating the env var makes
 * previously-encrypted values unreadable (by design — fail loud, never a silent
 * plaintext fallback). Ciphertext is tagged with a version prefix so the format
 * can evolve.
 *
 * This is the shared primitive behind:
 *   - App\Settings\AppSettings  (global admin secrets, e.g. OpenRouter API key)
 *   - App\Gsc\ConnectorRepository (per-project Google refresh tokens)
 *
 * The wire format is IDENTICAL to what AppSettings used before extraction
 * (`enc:v1:` + base64(iv|tag|ciphertext)), so existing stored blobs keep
 * decrypting unchanged.
 *
 * @package    Scouter
 * @subpackage Util
 */
class SecretCrypto
{
    public const PREFIX = 'enc:v1:';

    /** Whether a usable encryption key is present in the environment. */
    public static function hasKey(): bool
    {
        return self::deriveKey() !== null;
    }

    /**
     * Encrypt a plaintext. Returns the `enc:v1:...` blob, or null when no
     * encryption key is configured (caller must treat this as a hard failure
     * and refuse to persist the secret).
     */
    public static function encrypt(string $plaintext): ?string
    {
        $key = self::deriveKey();
        if ($key === null) {
            return null;
        }
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($ciphertext === false) {
            return null;
        }
        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt an `enc:v1:...` blob. Returns null when the key is missing or the
     * blob is malformed / fails authentication. A value WITHOUT the prefix is
     * assumed to be a legacy plaintext and returned as-is (matches the previous
     * AppSettings behaviour for hand-edited rows).
     */
    public static function decrypt(string $stored): ?string
    {
        if (strncmp($stored, self::PREFIX, strlen(self::PREFIX)) !== 0) {
            return $stored;
        }
        $key = self::deriveKey();
        if ($key === null) {
            return null;
        }
        $blob = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($blob === false || strlen($blob) < 28) {
            return null;
        }
        $iv = substr($blob, 0, 12);
        $tag = substr($blob, 12, 16);
        $ciphertext = substr($blob, 28);
        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        return $plaintext === false ? null : $plaintext;
    }

    /**
     * Mask a secret for safe display: 20 bullets + the last 4 chars.
     */
    public static function mask(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        return str_repeat('•', 20) . substr($value, -4);
    }

    /** 32-byte key derived from SCOUTER_ENCRYPTION_KEY, or null if unset. */
    private static function deriveKey(): ?string
    {
        $raw = getenv('SCOUTER_ENCRYPTION_KEY');
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        return hash('sha256', $raw, true);
    }
}
