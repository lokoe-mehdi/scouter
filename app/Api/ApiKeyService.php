<?php

namespace App\Api;

use App\Database\PostgresDatabase;
use PDO;

/**
 * Per-user API keys for the public REST API.
 *
 * Security model (see API.md §2):
 *  - Token = `sctr_` + 256 bits of CSPRNG, base64url. 256-bit entropy → unguessable.
 *  - Stored HASHED (SHA-256), never reversible — we only verify, like a password.
 *  - The plaintext is returned ONCE by generate(); after that only metadata.
 *  - `prefix` (first chars) is indexed for an O(1) candidate lookup; the full
 *    hash is then compared in CONSTANT TIME (hash_equals) to avoid timing oracles.
 *  - Keys are soft-revocable; last_used_at is tracked (throttled).
 *  - A verified key "acts as" its owner — the router sets the auth context to
 *    that user, so all existing per-project authorization applies unchanged.
 *
 * @package    Scouter
 * @subpackage Api
 */
class ApiKeyService
{
    private const TOKEN_PREFIX = 'sctr_';
    private const PREFIX_LEN   = 12;          // chars stored for lookup/display
    private const RATE_PER_MIN = 120;         // requests per key per minute

    private static function db(): PDO
    {
        return PostgresDatabase::getInstance()->getConnection();
    }

    /**
     * Create a new key for a user. Returns the PLAINTEXT token (shown once) plus
     * metadata. Never retrievable again after this call.
     *
     * @return array{id:int, token:string, prefix:string, name:string}
     */
    public static function generate(int $userId, string $name): array
    {
        $name = trim($name) !== '' ? mb_substr(trim($name), 0, 100) : 'API key';
        $token  = self::TOKEN_PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $prefix = substr($token, 0, self::PREFIX_LEN);
        $hash   = hash('sha256', $token);

        $stmt = self::db()->prepare("
            INSERT INTO api_keys (user_id, name, prefix, token_hash)
            VALUES (:uid, :name, :prefix, :hash)
            RETURNING id
        ");
        $stmt->execute([':uid' => $userId, ':name' => $name, ':prefix' => $prefix, ':hash' => $hash]);
        $id = (int)$stmt->fetchColumn();

        return ['id' => $id, 'token' => $token, 'prefix' => $prefix, 'name' => $name];
    }

    /**
     * Verify a presented token. Returns the owning user + key id, or null.
     *
     * @return array{key_id:int, user:object}|null
     */
    public static function verify(?string $token): ?array
    {
        if ($token === null || strncmp($token, self::TOKEN_PREFIX, strlen(self::TOKEN_PREFIX)) !== 0) {
            return null;
        }
        $prefix = substr($token, 0, self::PREFIX_LEN);
        $hash   = hash('sha256', $token);

        $stmt = self::db()->prepare("
            SELECT id, user_id, token_hash
            FROM api_keys
            WHERE prefix = :p AND revoked_at IS NULL
        ");
        $stmt->execute([':p' => $prefix]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // Constant-time compare — no early-exit timing leak.
            if (hash_equals((string)$row['token_hash'], $hash)) {
                $u = self::db()->prepare("SELECT id, email, role FROM users WHERE id = :id");
                $u->execute([':id' => (int)$row['user_id']]);
                $user = $u->fetch(PDO::FETCH_OBJ);
                if (!$user) return null; // owner deleted
                return ['key_id' => (int)$row['id'], 'user' => $user];
            }
        }
        return null;
    }

    /** Soft-revoke a key the user owns. Returns true if a row was revoked. */
    public static function revoke(int $keyId, int $userId): bool
    {
        $stmt = self::db()->prepare("
            UPDATE api_keys SET revoked_at = CURRENT_TIMESTAMP
            WHERE id = :id AND user_id = :uid AND revoked_at IS NULL
        ");
        $stmt->execute([':id' => $keyId, ':uid' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /** Active keys for a user — metadata only (never the token). */
    public static function listForUser(int $userId): array
    {
        $stmt = self::db()->prepare("
            SELECT id, name, prefix, last_used_at, created_at
            FROM api_keys
            WHERE user_id = :uid AND revoked_at IS NULL
            ORDER BY created_at DESC
        ");
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Record usage, throttled to ≤ once/minute so we don't write on every call. */
    public static function touchLastUsed(int $keyId): void
    {
        try {
            self::db()->prepare("
                UPDATE api_keys SET last_used_at = CURRENT_TIMESTAMP
                WHERE id = :id
                  AND (last_used_at IS NULL OR last_used_at < CURRENT_TIMESTAMP - INTERVAL '60 seconds')
            ")->execute([':id' => $keyId]);
        } catch (\Throwable $e) {
            // Non-critical.
        }
    }

    /**
     * Per-key rate limit (sliding minute). Returns true if the call is allowed.
     * Uses APCu (shared across PHP-FPM workers on the host) when available;
     * degrades to "allow" if APCu isn't present (limit becomes best-effort).
     */
    public static function rateLimit(int $keyId): bool
    {
        if (!function_exists('apcu_inc')) {
            return true; // no shared counter available — don't break the API
        }
        $bucket = 'scouter:rl:' . $keyId . ':' . floor(time() / 60);
        $success = false;
        $count = apcu_inc($bucket, 1, $success);
        if (!$success || $count === false) {
            apcu_store($bucket, 1, 60);
            return true;
        }
        if ($count === 1) {
            // first hit of this minute — set TTL
            apcu_store($bucket, 1, 60);
        }
        return $count <= self::RATE_PER_MIN;
    }
}
