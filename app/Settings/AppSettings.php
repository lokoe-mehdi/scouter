<?php

namespace App\Settings;

use App\Database\PostgresDatabase;
use App\Util\SecretCrypto;
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

    /** @deprecated kept for BC; the canonical constant is SecretCrypto::PREFIX. */
    private const SENSITIVE_PREFIX = SecretCrypto::PREFIX;

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
        return SecretCrypto::hasKey();
    }

    /**
     * Mask a secret for safe display: shows the last 4 chars only.
     */
    public static function maskSecret(?string $value): string
    {
        return SecretCrypto::mask($value);
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

    private static function encrypt(string $plaintext): ?string
    {
        return SecretCrypto::encrypt($plaintext);
    }

    private static function decrypt(string $stored): ?string
    {
        return SecretCrypto::decrypt($stored);
    }
}
