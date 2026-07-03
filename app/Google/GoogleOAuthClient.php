<?php

namespace App\Google;

use App\Util\SafeHttp;

/**
 * Thin OAuth 2.0 client for Google (Authorization Code + PKCE, offline access).
 *
 * Scouter is the OAuth *client* here (unlike App\Api\OAuthServer, where Scouter
 * is the authorization *server* for the MCP connector). This drives the flow
 * that lets a user connect their Google Search Console property to a project:
 *
 *   1. buildAuthUrl()      — where we send the browser (consent screen).
 *   2. exchangeCode()      — callback: code → refresh_token + access_token + email.
 *   3. refreshAccessToken() — every job: refresh_token → fresh access_token.
 *   4. revoke()            — on disconnect: best-effort token revocation.
 *
 * No SDK on purpose (house style — cf. OpenRouterClient, S3Storage): three
 * endpoints, hand-rolled with curl behind SafeHttp's SSRF guard.
 *
 * Credentials come from the environment (infra secret, single app registration):
 *   GOOGLE_OAUTH_CLIENT_ID / GOOGLE_OAUTH_CLIENT_SECRET / GOOGLE_OAUTH_REDIRECT_URI
 *
 * @package    Scouter
 * @subpackage Google
 */
class GoogleOAuthClient
{
    private const AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    /**
     * Scopes requested. webmasters.readonly is a "sensitive" scope (app must be
     * "In production" to avoid the 7-day refresh-token revocation). openid+email
     * are non-sensitive and let us display which Google account is connected.
     */
    public const SCOPES = 'openid email https://www.googleapis.com/auth/webmasters.readonly';

    public static function isConfigured(): bool
    {
        return self::clientId() !== '' && self::clientSecret() !== '' && self::redirectUri() !== '';
    }

    public static function clientId(): string     { return (string) (getenv('GOOGLE_OAUTH_CLIENT_ID') ?: ''); }
    public static function clientSecret(): string { return (string) (getenv('GOOGLE_OAUTH_CLIENT_SECRET') ?: ''); }
    public static function redirectUri(): string  { return (string) (getenv('GOOGLE_OAUTH_REDIRECT_URI') ?: ''); }

    /**
     * PKCE helper: a random verifier + its S256 challenge (base64url, no pad).
     *
     * @return array{verifier:string,challenge:string}
     */
    public static function makePkce(): array
    {
        $verifier  = self::b64url(random_bytes(48));
        $challenge = self::b64url(hash('sha256', $verifier, true));
        return ['verifier' => $verifier, 'challenge' => $challenge];
    }

    /**
     * The consent-screen URL. access_type=offline + prompt=consent guarantees a
     * refresh_token (Google only returns one on first consent otherwise).
     */
    public static function buildAuthUrl(string $state, string $codeChallenge, ?string $loginHint = null): string
    {
        $params = [
            'client_id'             => self::clientId(),
            'redirect_uri'          => self::redirectUri(),
            'response_type'         => 'code',
            'scope'                 => self::SCOPES,
            'access_type'           => 'offline',
            'prompt'                => 'consent',
            'include_granted_scopes'=> 'true',
            'state'                 => $state,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];
        if ($loginHint !== null && $loginHint !== '') {
            $params['login_hint'] = $loginHint;
        }
        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Exchange an authorization code for tokens.
     *
     * @return array{ok:bool,refresh_token?:string,access_token?:string,
     *               expires_in?:int,email?:string,sub?:string,scope?:string,error?:string}
     */
    public static function exchangeCode(string $code, string $codeVerifier): array
    {
        $resp = self::postForm(self::TOKEN_URL, [
            'code'          => $code,
            'client_id'     => self::clientId(),
            'client_secret' => self::clientSecret(),
            'redirect_uri'  => self::redirectUri(),
            'grant_type'    => 'authorization_code',
            'code_verifier' => $codeVerifier,
        ]);
        if (!$resp['ok']) {
            return $resp;
        }
        $b = json_decode($resp['body'], true);
        if (!is_array($b) || !isset($b['access_token'])) {
            return ['ok' => false, 'error' => 'Unexpected token response from Google'];
        }
        $claims = isset($b['id_token']) ? self::decodeIdToken((string) $b['id_token']) : [];
        return [
            'ok'            => true,
            'access_token'  => (string) $b['access_token'],
            // refresh_token is absent if the user had already granted consent and
            // Google chose not to re-issue one; callers must handle that.
            'refresh_token' => isset($b['refresh_token']) ? (string) $b['refresh_token'] : '',
            'expires_in'    => (int) ($b['expires_in'] ?? 3600),
            'scope'         => (string) ($b['scope'] ?? ''),
            'email'         => (string) ($claims['email'] ?? ''),
            'sub'           => (string) ($claims['sub'] ?? ''),
        ];
    }

    /**
     * Refresh an access token from a stored refresh token.
     *
     * @return array{ok:bool,access_token?:string,expires_in?:int,error?:string,
     *               invalid_grant?:bool}
     */
    public static function refreshAccessToken(string $refreshToken): array
    {
        $resp = self::postForm(self::TOKEN_URL, [
            'client_id'     => self::clientId(),
            'client_secret' => self::clientSecret(),
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ]);
        if (!$resp['ok']) {
            // A revoked/expired refresh token comes back as HTTP 400 invalid_grant.
            $isInvalidGrant = isset($resp['body']) && str_contains((string) $resp['body'], 'invalid_grant');
            return ['ok' => false, 'error' => $resp['error'] ?? 'refresh failed', 'invalid_grant' => $isInvalidGrant];
        }
        $b = json_decode($resp['body'], true);
        if (!is_array($b) || !isset($b['access_token'])) {
            return ['ok' => false, 'error' => 'Unexpected refresh response from Google'];
        }
        return [
            'ok'           => true,
            'access_token' => (string) $b['access_token'],
            'expires_in'   => (int) ($b['expires_in'] ?? 3600),
        ];
    }

    /** Best-effort token revocation (disconnect). Never throws. */
    public static function revoke(string $token): void
    {
        if ($token === '') {
            return;
        }
        try {
            self::postForm(self::REVOKE_URL, ['token' => $token]);
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Decode the payload of a Google id_token (JWT). No signature check: the
     * token is delivered directly by Google's token endpoint over TLS, so the
     * channel is already trusted — we only read the email/sub claims for display.
     *
     * @return array<string,mixed>
     */
    public static function decodeIdToken(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            return [];
        }
        $payload = json_decode(self::b64urlDecode($parts[1]), true);
        return is_array($payload) ? $payload : [];
    }

    /**
     * @param array<string,string> $fields
     * @return array{ok:bool,body?:string,error?:string}
     */
    private static function postForm(string $url, array $fields): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
        ]);
        SafeHttp::applyCurlSecurity($ch);

        $body   = curl_exec($ch);
        $errno  = curl_errno($ch);
        $errmsg = $errno ? curl_error($ch) : '';
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        try {
            SafeHttp::validateFinalIp($ch);
        } catch (\Throwable $e) {
            curl_close($ch);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        curl_close($ch);

        if ($errno || $body === false) {
            return ['ok' => false, 'error' => "Network error: {$errmsg}"];
        }
        if ($status >= 400) {
            $decoded = json_decode((string) $body, true);
            $msg = is_array($decoded)
                ? ($decoded['error_description'] ?? $decoded['error'] ?? "HTTP {$status}")
                : "HTTP {$status}";
            return ['ok' => false, 'error' => (string) $msg, 'body' => (string) $body];
        }
        return ['ok' => true, 'body' => (string) $body];
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $s): string
    {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad) {
            $s .= str_repeat('=', 4 - $pad);
        }
        return (string) base64_decode($s);
    }
}
