<?php

namespace App\Api;

use App\Database\PostgresDatabase;
use PDO;

/**
 * Minimal OAuth 2.0 Authorization Server for the MCP connector.
 *
 * Implements just what claude.ai's remote-MCP client needs:
 *   - RFC 8414 Authorization Server Metadata
 *   - RFC 9728 Protected Resource Metadata
 *   - RFC 7591 Dynamic Client Registration
 *   - Authorization Code grant with PKCE (S256 only)
 *
 * The issued access token is a real `sctr_` API key (minted via ApiKeyService),
 * so the existing Bearer middleware validates it unchanged — Scouter is both the
 * Authorization Server and (through /api/v1) the Resource Server.
 *
 * @package    Scouter
 * @subpackage Api
 */
class OAuthServer
{
    private const CODE_TTL_SECONDS = 300;
    public const SCOPE = 'mcp';

    private static function db(): PDO
    {
        return PostgresDatabase::getInstance()->getConnection();
    }

    // -------------------------------------------------------------------------
    // Discovery metadata
    // -------------------------------------------------------------------------

    /** RFC 8414 — Authorization Server Metadata. */
    public static function authServerMetadata(string $issuer): array
    {
        return [
            'issuer'                                => $issuer,
            'authorization_endpoint'                => $issuer . '/oauth/authorize',
            'token_endpoint'                        => $issuer . '/oauth/token',
            'registration_endpoint'                 => $issuer . '/oauth/register',
            'response_types_supported'              => ['code'],
            'grant_types_supported'                 => ['authorization_code'],
            'code_challenge_methods_supported'      => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported'                      => [self::SCOPE],
        ];
    }

    /** RFC 9728 — Protected Resource Metadata. */
    public static function protectedResourceMetadata(string $issuer): array
    {
        return [
            'resource'                 => $issuer . '/mcp',
            'authorization_servers'    => [$issuer],
            'scopes_supported'         => [self::SCOPE],
            'bearer_methods_supported' => ['header'],
        ];
    }

    // -------------------------------------------------------------------------
    // Dynamic Client Registration (RFC 7591)
    // -------------------------------------------------------------------------

    /**
     * Register a client from the DCR request body. Public client (PKCE, no
     * secret). Returns the registration response, or ['error' => …] on bad input.
     */
    public static function registerClient(array $body): array
    {
        $redirectUris = $body['redirect_uris'] ?? [];
        if (!is_array($redirectUris) || count($redirectUris) === 0) {
            return ['error' => 'invalid_redirect_uri', 'error_description' => 'redirect_uris is required'];
        }
        foreach ($redirectUris as $uri) {
            if (!is_string($uri) || !preg_match('#^https?://#i', $uri)) {
                return ['error' => 'invalid_redirect_uri', 'error_description' => 'redirect_uris must be absolute http(s) URLs'];
            }
        }

        $clientId   = 'mcp_' . bin2hex(random_bytes(16));
        $clientName = isset($body['client_name']) ? mb_substr((string)$body['client_name'], 0, 255) : null;

        self::db()->prepare("
            INSERT INTO oauth_clients (client_id, client_name, redirect_uris)
            VALUES (:cid, :name, :uris)
        ")->execute([
            ':cid'  => $clientId,
            ':name' => $clientName,
            ':uris' => json_encode(array_values($redirectUris)),
        ]);

        return [
            'client_id'                 => $clientId,
            'client_id_issued_at'       => time(),
            'client_name'               => $clientName,
            'redirect_uris'             => array_values($redirectUris),
            'grant_types'               => ['authorization_code'],
            'response_types'            => ['code'],
            'token_endpoint_auth_method'=> 'none',
        ];
    }

    /** @return array{client_id:string,client_name:?string,redirect_uris:array}|null */
    public static function findClient(string $clientId): ?array
    {
        $stmt = self::db()->prepare("SELECT client_id, client_name, redirect_uris FROM oauth_clients WHERE client_id = :cid");
        $stmt->execute([':cid' => $clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['redirect_uris'] = json_decode($row['redirect_uris'], true) ?: [];
        return $row;
    }

    public static function redirectUriAllowed(array $client, string $redirectUri): bool
    {
        return in_array($redirectUri, $client['redirect_uris'], true);
    }

    // -------------------------------------------------------------------------
    // Authorization code
    // -------------------------------------------------------------------------

    /** Issue a single-use authorization code bound to the user + PKCE challenge. */
    public static function issueCode(string $clientId, int $userId, string $redirectUri, string $codeChallenge, ?string $scope): string
    {
        $code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        self::db()->prepare("
            INSERT INTO oauth_auth_codes (code, client_id, user_id, redirect_uri, code_challenge, scope, expires_at)
            VALUES (:code, :cid, :uid, :ruri, :cc, :scope, CURRENT_TIMESTAMP + (:ttl || ' seconds')::interval)
        ")->execute([
            ':code'  => $code,
            ':cid'   => $clientId,
            ':uid'   => $userId,
            ':ruri'  => $redirectUri,
            ':cc'    => $codeChallenge,
            ':scope' => $scope ?: self::SCOPE,
            ':ttl'   => (string)self::CODE_TTL_SECONDS,
        ]);
        return $code;
    }

    /** Verify a PKCE code_verifier against the stored S256 challenge. */
    public static function verifyPkce(string $verifier, string $challenge): bool
    {
        $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return hash_equals($challenge, $computed);
    }

    /**
     * Exchange an authorization code for an access token (a fresh sctr_ key).
     *
     * @param array $params grant_type, code, redirect_uri, client_id, code_verifier
     * @return array Either the token response or ['error'=>…, 'error_description'=>…]
     */
    public static function exchangeCode(array $params): array
    {
        if (($params['grant_type'] ?? '') !== 'authorization_code') {
            return ['error' => 'unsupported_grant_type'];
        }
        $code         = (string)($params['code'] ?? '');
        $redirectUri  = (string)($params['redirect_uri'] ?? '');
        $clientId     = (string)($params['client_id'] ?? '');
        $codeVerifier = (string)($params['code_verifier'] ?? '');
        if ($code === '' || $codeVerifier === '') {
            return ['error' => 'invalid_request', 'error_description' => 'code and code_verifier are required'];
        }

        $db = self::db();
        $stmt = $db->prepare("
            SELECT code, client_id, user_id, redirect_uri, code_challenge, scope, used,
                   (expires_at < CURRENT_TIMESTAMP) AS expired
            FROM oauth_auth_codes WHERE code = :code
        ");
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || $row['used'] || $row['expired']) {
            return ['error' => 'invalid_grant', 'error_description' => 'Authorization code is invalid, used or expired'];
        }
        if ($row['client_id'] !== $clientId || $row['redirect_uri'] !== $redirectUri) {
            return ['error' => 'invalid_grant', 'error_description' => 'client_id / redirect_uri mismatch'];
        }
        if (!self::verifyPkce($codeVerifier, $row['code_challenge'])) {
            return ['error' => 'invalid_grant', 'error_description' => 'PKCE verification failed'];
        }

        // Single-use: burn the code before issuing the token.
        $db->prepare("UPDATE oauth_auth_codes SET used = TRUE WHERE code = :code")->execute([':code' => $code]);

        $key = ApiKeyService::generate((int)$row['user_id'], 'MCP (claude.ai)');

        return [
            'access_token' => $key['token'],
            'token_type'   => 'Bearer',
            'scope'        => $row['scope'] ?: self::SCOPE,
        ];
    }

    /** Best-effort cleanup of expired codes (called opportunistically). */
    public static function pruneExpiredCodes(): void
    {
        try {
            self::db()->exec("DELETE FROM oauth_auth_codes WHERE expires_at < CURRENT_TIMESTAMP - INTERVAL '1 hour'");
        } catch (\Throwable $e) {
            // non-critical
        }
    }
}
