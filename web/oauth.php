<?php
/**
 * OAuth 2.0 endpoints for the MCP connector (claude.ai). Routed here by nginx:
 *   /.well-known/oauth-authorization-server   (RFC 8414 metadata)
 *   /.well-known/oauth-protected-resource     (RFC 9728 metadata)
 *   /oauth/register                            (RFC 7591 dynamic registration)
 *   /oauth/authorize                           (auth code + PKCE; reuses login)
 *   /oauth/token                               (code -> sctr_ access token)
 *
 * Bootstrapped standalone (NOT init.php) so the machine endpoints stay
 * unauthenticated; /authorize reuses the normal Scouter login session.
 */

require_once(__DIR__ . '/../vendor/autoload.php');
require_once(__DIR__ . '/config/i18n.php');

use App\Api\OAuthServer;
use App\Auth\Auth;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$proto = $_SERVER['HTTP_X_FORWARDED_PROTO']
    ?? (((($_SERVER['HTTPS'] ?? '') !== '') && ($_SERVER['HTTPS'] ?? '') !== 'off') ? 'https' : 'http');
$host  = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
$issuer = $proto . '://' . $host;

$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/** JSON response helper with permissive CORS (browser-side discovery/token). */
function oauthJson(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

/** Diagnostic logging for the OAuth flow (errors land in the PHP error log). */
function oauthLog(string $event, array $ctx = []): void
{
    $parts = [];
    foreach ($ctx as $k => $v) {
        $parts[] = $k . '=' . (is_scalar($v) || $v === null ? (string)$v : json_encode($v));
    }
    error_log('[Scouter OAuth] ' . $event . ($parts ? ' ' . implode(' ', $parts) : ''));
}

// CORS preflight for the JSON endpoints.
if ($method === 'OPTIONS') {
    oauthJson([], 204);
}

oauthLog('request', ['path' => $path, 'method' => $method, 'issuer' => $issuer]);

OAuthServer::pruneExpiredCodes();

// -----------------------------------------------------------------------------
// Discovery metadata (also accept the path-suffixed variants some clients use).
// -----------------------------------------------------------------------------
if (str_starts_with($path, '/.well-known/oauth-authorization-server')) {
    oauthJson(OAuthServer::authServerMetadata($issuer));
}
if (str_starts_with($path, '/.well-known/oauth-protected-resource')) {
    oauthJson(OAuthServer::protectedResourceMetadata($issuer));
}

// -----------------------------------------------------------------------------
// Dynamic Client Registration
// -----------------------------------------------------------------------------
if ($path === '/oauth/register' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
    $res = OAuthServer::registerClient($body);
    if (isset($res['error'])) {
        oauthJson($res, 400);
    }
    oauthJson($res, 201);
}

// -----------------------------------------------------------------------------
// Token endpoint
// -----------------------------------------------------------------------------
if ($path === '/oauth/token' && $method === 'POST') {
    // application/x-www-form-urlencoded per OAuth; $_POST covers it.
    $res = OAuthServer::exchangeCode($_POST);
    if (isset($res['error'])) {
        oauthLog('token_error', [
            'error'        => $res['error'],
            'desc'         => $res['error_description'] ?? '',
            'grant_type'   => $_POST['grant_type'] ?? '',
            'client_id'    => $_POST['client_id'] ?? '',
            'redirect_uri' => $_POST['redirect_uri'] ?? '',
            'has_code'     => isset($_POST['code']) ? 'y' : 'n',
            'has_verifier' => isset($_POST['code_verifier']) ? 'y' : 'n',
        ]);
        oauthJson($res, 400);
    }
    oauthLog('token_ok', ['client_id' => $_POST['client_id'] ?? '']);
    oauthJson($res, 200);
}

// -----------------------------------------------------------------------------
// Authorization endpoint (browser, user-facing)
// -----------------------------------------------------------------------------
if ($path === '/oauth/authorize') {
    $auth = new Auth();

    // Gather params from query (GET) or the consent form (POST).
    $src           = $method === 'POST' ? $_POST : $_GET;
    $clientId      = (string)($src['client_id'] ?? '');
    $redirectUri   = (string)($src['redirect_uri'] ?? '');
    $responseType  = (string)($src['response_type'] ?? 'code');
    $codeChallenge = (string)($src['code_challenge'] ?? '');
    $challengeMeth = (string)($src['code_challenge_method'] ?? '');
    $state         = (string)($src['state'] ?? '');
    $scope         = (string)($src['scope'] ?? OAuthServer::SCOPE);

    $client = $clientId !== '' ? OAuthServer::findClient($clientId) : null;

    // Validate the client + redirect_uri BEFORE trusting redirect_uri for errors.
    if (!$client || !OAuthServer::redirectUriAllowed($client, $redirectUri)) {
        oauthLog('authorize_reject', [
            'reason'       => !$client ? 'unknown_client' : 'redirect_uri_not_allowed',
            'client_id'    => $clientId,
            'redirect_uri' => $redirectUri,
            'allowed'      => $client['redirect_uris'] ?? [],
        ]);
        http_response_code(400);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>OAuth error</title>'
           . '<p style="font-family:system-ui;max-width:32rem;margin:3rem auto">'
           . 'Invalid <code>client_id</code> or <code>redirect_uri</code>. '
           . 'Re-add the Scouter connector in Claude.</p>';
        exit;
    }

    $bail = function (string $error) use ($redirectUri, $state, $clientId, $method): void {
        oauthLog('authorize_bail', ['error' => $error, 'client_id' => $clientId, 'method' => $method]);
        $sep = str_contains($redirectUri, '?') ? '&' : '?';
        $qs = http_build_query(array_filter(['error' => $error, 'state' => $state], fn($v) => $v !== ''));
        header('Location: ' . $redirectUri . $sep . $qs);
        exit;
    };

    if ($responseType !== 'code') { $bail('unsupported_response_type'); }
    if ($challengeMeth !== 'S256' || $codeChallenge === '') { $bail('invalid_request'); }

    // Require a logged-in Scouter user; bounce through the normal login page.
    if (!$auth->isLoggedIn()) {
        $returnTo = '/oauth/authorize?' . http_build_query([
            'response_type'         => 'code',
            'client_id'             => $clientId,
            'redirect_uri'          => $redirectUri,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
            'state'                 => $state,
            'scope'                 => $scope,
        ]);
        header('Location: /login.php?redirect=' . urlencode($returnTo));
        exit;
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    // Consent decision.
    if ($method === 'POST') {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
            $bail('access_denied');
        }
        if (($_POST['decision'] ?? '') !== 'allow') {
            $bail('access_denied');
        }
        $code = OAuthServer::issueCode($clientId, (int)$auth->getCurrentUserId(), $redirectUri, $codeChallenge, $scope);
        $sep = str_contains($redirectUri, '?') ? '&' : '?';
        $qs = http_build_query(array_filter(['code' => $code, 'state' => $state], fn($v) => $v !== ''));
        header('Location: ' . $redirectUri . $sep . $qs);
        exit;
    }

    // Render the consent screen (GET).
    $email      = htmlspecialchars((string)$auth->getCurrentEmail(), ENT_QUOTES);
    $clientName = htmlspecialchars((string)($client['client_name'] ?: 'Claude'), ENT_QUOTES);
    $csrf       = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES);
    $h = fn(string $v) => htmlspecialchars($v, ENT_QUOTES);
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="<?= $h(\I18n::getInstance()->getLang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h(__('oauth.consent_title')) ?></title>
<style>
  body { font-family: system-ui, -apple-system, sans-serif; background: #f8fafc; margin: 0; display: flex; min-height: 100vh; align-items: center; justify-content: center; }
  .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; box-shadow: 0 10px 40px rgba(15,23,42,0.08); padding: 2rem; max-width: 26rem; width: 90%; }
  h1 { font-size: 1.25rem; margin: 0 0 0.5rem; color: #0f172a; }
  p { color: #475569; line-height: 1.55; font-size: 0.95rem; }
  .who { background: #f1f5f9; border-radius: 8px; padding: 0.6rem 0.8rem; font-size: 0.9rem; color: #334155; margin: 1rem 0; }
  .scope { font-size: 0.85rem; color: #64748b; margin: 1rem 0; }
  .actions { display: flex; gap: 0.6rem; margin-top: 1.5rem; }
  button { flex: 1; padding: 0.7rem 1rem; border-radius: 8px; border: none; font-size: 0.95rem; font-weight: 600; cursor: pointer; }
  .allow { background: #0891b2; color: #fff; }
  .deny { background: #e2e8f0; color: #334155; }
</style>
</head>
<body>
  <div class="card">
    <h1><?= $h(__('oauth.consent_title')) ?></h1>
    <p><?= str_replace(':client', '<strong>' . $clientName . '</strong>', $h(__('oauth.consent_intro'))) ?></p>
    <div class="who"><?= $h(__('oauth.consent_account')) ?> <strong><?= $email ?></strong></div>
    <p class="scope"><?= $h(__('oauth.consent_scope')) ?></p>
    <form method="post" action="/oauth/authorize">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="client_id" value="<?= $h($clientId) ?>">
      <input type="hidden" name="redirect_uri" value="<?= $h($redirectUri) ?>">
      <input type="hidden" name="code_challenge" value="<?= $h($codeChallenge) ?>">
      <input type="hidden" name="code_challenge_method" value="S256">
      <input type="hidden" name="state" value="<?= $h($state) ?>">
      <input type="hidden" name="scope" value="<?= $h($scope) ?>">
      <div class="actions">
        <button type="submit" name="decision" value="deny" class="deny"><?= $h(__('oauth.consent_deny')) ?></button>
        <button type="submit" name="decision" value="allow" class="allow"><?= $h(__('oauth.consent_allow')) ?></button>
      </div>
    </form>
  </div>
</body>
</html>
    <?php
    exit;
}

// Unknown OAuth path.
http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'not_found']);
