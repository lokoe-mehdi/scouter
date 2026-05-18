<?php

namespace App\Settings;

use App\Database\PostgresDatabase;
use PDO;

/**
 * Wrapper for the app_settings table.
 *
 * Provides typed get/set for global, admin-managed configuration. Sensitive
 * keys (those returned by `sensitiveKeys()`) are transparently encrypted
 * with AES-256-GCM using a key derived from the SCOUTER_ENCRYPTION_KEY
 * environment variable. Without that env var, set() refuses to persist
 * sensitive values — there is no fallback, by design (we'd rather fail
 * loudly than silently store secrets in plaintext).
 *
 * Caches values in-process so repeated reads in the same request don't
 * round-trip to PostgreSQL.
 *
 * @package    Scouter
 * @subpackage Settings
 */
class AppSettings
{
    /** @var array<string, string|null> in-process cache, key => decrypted value */
    private static array $cache = [];

    private const SENSITIVE_PREFIX = 'enc:v1:';

    /**
     * Keys whose value must be encrypted at rest.
     *
     * @return string[]
     */
    private static function sensitiveKeys(): array
    {
        return [
            'ai.openrouter.api_key',
        ];
    }

    /**
     * Fetch a setting. Returns null when the key has never been set.
     */
    public static function get(string $key): ?string
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        $pdo = PostgresDatabase::getInstance()->getConnection();
        $stmt = $pdo->prepare("SELECT value FROM app_settings WHERE key = :k");
        $stmt->execute([':k' => $key]);
        $raw = $stmt->fetchColumn();

        if ($raw === false) {
            self::$cache[$key] = null;
            return null;
        }

        $value = self::isSensitive($key) ? self::decrypt((string)$raw) : (string)$raw;
        self::$cache[$key] = $value;
        return $value;
    }

    /**
     * Persist a setting. Returns true on success, false if encryption was
     * required but no encryption key is configured.
     */
    public static function set(string $key, string $value, ?int $userId = null): bool
    {
        $stored = $value;
        if (self::isSensitive($key)) {
            $encrypted = self::encrypt($value);
            if ($encrypted === null) {
                return false;
            }
            $stored = $encrypted;
        }

        $pdo = PostgresDatabase::getInstance()->getConnection();
        $stmt = $pdo->prepare("
            INSERT INTO app_settings (key, value, updated_at, updated_by)
            VALUES (:k, :v, CURRENT_TIMESTAMP, :u)
            ON CONFLICT (key) DO UPDATE SET
                value = EXCLUDED.value,
                updated_at = CURRENT_TIMESTAMP,
                updated_by = EXCLUDED.updated_by
        ");
        $stmt->execute([':k' => $key, ':v' => $stored, ':u' => $userId]);

        self::$cache[$key] = $value;
        return true;
    }

    /**
     * Whether a usable encryption key is present in the environment. Needed by
     * the UI to surface a clear error before the user types their secret.
     */
    public static function hasEncryptionKey(): bool
    {
        return self::deriveKey() !== null;
    }

    /**
     * Mask a secret for safe display: shows the last 4 chars only.
     */
    public static function maskSecret(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $tail = substr($value, -4);
        return str_repeat('•', 20) . $tail;
    }

    /** Clear the in-process cache (testing only). */
    public static function flushCache(): void
    {
        self::$cache = [];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private static function isSensitive(string $key): bool
    {
        return in_array($key, self::sensitiveKeys(), true);
    }

    private static function deriveKey(): ?string
    {
        $raw = getenv('SCOUTER_ENCRYPTION_KEY');
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        // 32-byte key derived deterministically from the env var so admins
        // can rotate by changing the env var (existing values stay readable
        // only with the previous value).
        return hash('sha256', $raw, true);
    }

    private static function encrypt(string $plaintext): ?string
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
        return self::SENSITIVE_PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    private static function decrypt(string $stored): ?string
    {
        if (strpos($stored, self::SENSITIVE_PREFIX) !== 0) {
            // Legacy/unencrypted value — return as-is rather than crash. This
            // would only happen if someone hand-edited the DB.
            return $stored;
        }
        $key = self::deriveKey();
        if ($key === null) {
            return null;
        }
        $blob = base64_decode(substr($stored, strlen(self::SENSITIVE_PREFIX)), true);
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
}
