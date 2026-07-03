<?php
/**
 * Google Search Console connector — browser-facing OAuth flow. Routed here by
 * nginx (`location /gsc/`):
 *   GET  /gsc/connect?project=<id>   → start OAuth (redirect to Google consent)
 *   GET  /gsc/callback?code&state    → exchange code, pick a property
 *   POST /gsc/select-property        → store property + launch the backfill job
 *   POST /gsc/disconnect             → launch the delete job (purge + revoke)
 *
 * Session-based (reuses the normal Scouter login). The JSON data endpoints for
 * the Search Analytics view live in the API router (App\Http\Controllers\GscController).
 */

require_once(__DIR__ . '/../vendor/autoload.php');
require_once(__DIR__ . '/config/i18n.php');

use App\Auth\Auth;
use App\Database\ProjectRepository;
use App\Google\GoogleOAuthClient;
use App\Google\SearchConsoleClient;
use App\Gsc\ConnectorRepository;
use App\Gsc\GscJobRunner;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$auth   = new Auth();

/** Redirect helper. */
function gscRedirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

/** Bounce to the project page with a flash message. */
function backToProject(int $projectId, string $status, string $msg = ''): never
{
    $qs = http_build_query(array_filter(['id' => $projectId, 'gsc' => $status, 'gsc_msg' => $msg]));
    gscRedirect('/project.php?' . $qs);
}

// Everything here requires a logged-in user.
if (!$auth->isLoggedIn()) {
    gscRedirect('/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
}
$userId = (int) $auth->getCurrentUserId();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// -----------------------------------------------------------------------------
// GET /gsc/connect — start the OAuth handshake
// -----------------------------------------------------------------------------
if ($path === '/gsc/connect' && $method === 'GET') {
    $projectId = (int) ($_GET['project'] ?? 0);
    if (!$projectId || !$auth->canManageProject($projectId)) {
        backToProject($projectId, 'error', __('gsc.err_project_access'));
    }
    if (!GoogleOAuthClient::isConfigured()) {
        backToProject($projectId, 'error', __('gsc.err_oauth'));
    }

    $pkce  = GoogleOAuthClient::makePkce();
    $state = bin2hex(random_bytes(16));
    $_SESSION['gsc_oauth'] = [
        'state'      => $state,
        'verifier'   => $pkce['verifier'],
        'project_id' => $projectId,
        'user_id'    => $userId,
    ];
    gscRedirect(GoogleOAuthClient::buildAuthUrl($state, $pkce['challenge']));
}

// -----------------------------------------------------------------------------
// GET /gsc/callback — exchange the code, then show the property picker
// -----------------------------------------------------------------------------
if ($path === '/gsc/callback' && $method === 'GET') {
    $sess = $_SESSION['gsc_oauth'] ?? null;
    $projectId = (int) ($sess['project_id'] ?? 0);

    if (isset($_GET['error'])) {
        backToProject($projectId, 'error', __('gsc.err_cancelled'));
    }
    $state = (string) ($_GET['state'] ?? '');
    if (!$sess || !hash_equals((string) $sess['state'], $state) || (int) $sess['user_id'] !== $userId) {
        backToProject($projectId, 'error', __('gsc.err_state'));
    }
    if (!$auth->canManageProject($projectId)) {
        backToProject($projectId, 'error', __('gsc.err_project_access'));
    }

    $tok = GoogleOAuthClient::exchangeCode((string) ($_GET['code'] ?? ''), (string) $sess['verifier']);
    if (!$tok['ok']) {
        backToProject($projectId, 'error', __('gsc.err_oauth_fail', ['err' => ($tok['error'] ?? '?')]));
    }
    if (($tok['refresh_token'] ?? '') === '') {
        // No refresh token → the user had a prior grant. Ask them to remove access
        // and retry (prompt=consent normally forces one, this is the safety net).
        backToProject($projectId, 'error', __('gsc.err_no_refresh'));
    }

    // Persist the (pending) connector with the encrypted refresh token.
    $repo = new ConnectorRepository();
    $repo->upsertPending($projectId, (string) $tok['refresh_token'], [
        'google_email' => $tok['email'] ?? null,
        'google_sub'   => $tok['sub'] ?? null,
        'scope'        => $tok['scope'] ?? null,
        'created_by'   => $userId,
    ]);

    // List the properties the account can read.
    $sites = SearchConsoleClient::listSites((string) $tok['access_token']);
    if (!$sites['ok']) {
        backToProject($projectId, 'error', __('gsc.err_list_sites', ['err' => ($sites['error'] ?? '')]));
    }

    renderPropertyPicker($projectId, $sites['sites'], (string) ($tok['email'] ?? ''), (new ProjectRepository())->getById($projectId));
    exit;
}

// -----------------------------------------------------------------------------
// POST /gsc/select-property — store the chosen property + launch the backfill
// -----------------------------------------------------------------------------
if ($path === '/gsc/select-property' && $method === 'POST') {
    $projectId = (int) ($_POST['project_id'] ?? 0);
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string) $_POST['csrf_token'])) {
        backToProject($projectId, 'error', __('gsc.err_csrf'));
    }
    if (!$projectId || !$auth->canManageProject($projectId)) {
        backToProject($projectId, 'error', __('gsc.err_project_access'));
    }
    $siteUrl = trim((string) ($_POST['site_url'] ?? ''));
    if ($siteUrl === '') {
        backToProject($projectId, 'error', __('gsc.err_no_property'));
    }
    $months = (int) ($_POST['backfill_months'] ?? 16);
    $months = max(1, min(16, $months));
    $propertyType = str_starts_with($siteUrl, 'sc-domain:') ? 'domain' : 'url_prefix';

    $repo = new ConnectorRepository();
    $connector = $repo->getByProject($projectId);
    if (!$connector) {
        backToProject($projectId, 'error', __('gsc.err_no_connector'));
    }
    $repo->startBackfill((int) $connector->id, $siteUrl, $propertyType, $months);
    GscJobRunner::enqueueBackfill((int) $connector->id, $siteUrl);

    unset($_SESSION['gsc_oauth']);
    gscRedirect('/search-analytics.php?project=' . $projectId);
}

// -----------------------------------------------------------------------------
// POST /gsc/disconnect — purge + revoke (async)
// -----------------------------------------------------------------------------
if ($path === '/gsc/disconnect' && $method === 'POST') {
    $projectId = (int) ($_POST['project_id'] ?? 0);
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string) $_POST['csrf_token'])) {
        backToProject($projectId, 'error', __('gsc.err_csrf'));
    }
    if (!$projectId || !$auth->canManageProject($projectId)) {
        backToProject($projectId, 'error', __('gsc.err_project_access'));
    }
    $repo = new ConnectorRepository();
    $connector = $repo->getByProject($projectId);
    if ($connector) {
        $repo->setStatus((int) $connector->id, 'disconnecting');
        GscJobRunner::enqueueDelete((int) $connector->id, (string) $connector->site_url);
    }
    backToProject($projectId, 'disconnecting', __('gsc.disconnecting'));
}

// Unknown path.
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not found';
exit;

// -----------------------------------------------------------------------------
// Views
// -----------------------------------------------------------------------------

/**
 * Property picker shown after OAuth. Properties matching the project domain are
 * surfaced first. Minimal standalone page (like the OAuth consent screen).
 *
 * @param array<int,array{siteUrl:string,permissionLevel:string}> $sites
 */
function renderPropertyPicker(int $projectId, array $sites, string $email, ?object $project): void
{
    $h = fn(string $v) => htmlspecialchars($v, ENT_QUOTES);
    $csrf = $h($_SESSION['csrf_token']);
    $domain = strtolower((string) ($project->name ?? ''));

    // Sort: properties whose siteUrl contains the project domain first.
    usort($sites, function ($a, $b) use ($domain) {
        $am = $domain !== '' && str_contains(strtolower($a['siteUrl']), $domain) ? 0 : 1;
        $bm = $domain !== '' && str_contains(strtolower($b['siteUrl']), $domain) ? 0 : 1;
        return $am <=> $bm ?: strcmp($a['siteUrl'], $b['siteUrl']);
    });

    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h(__('gsc.picker_title')) ?></title>
<style>
  :root { --primary:#4ECDC4; --primary-dark:#3DB8AF; --text:#2C3E50; --muted:#7F8C8D; --border:#E1E8ED; --bg:#F7F9FC; }
  body { font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; background: var(--bg); margin: 0; color: var(--text);
         display: flex; min-height: 100vh; align-items: center; justify-content: center; }
  .card { background:#fff; border:1px solid var(--border); border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.08);
          padding:2rem; max-width:34rem; width:92%; }
  h1 { font-size:1.25rem; margin:0 0 .25rem; }
  p.sub { color:var(--muted); margin:.25rem 0 1.25rem; font-size:.9rem; }
  .prop { display:flex; align-items:center; gap:.7rem; padding:.7rem .8rem; border:1px solid var(--border);
          border-radius:8px; margin-bottom:.5rem; cursor:pointer; transition:border-color .15s; }
  .prop:hover { border-color: var(--primary); }
  .prop input { accent-color: var(--primary); }
  .prop code { font-size:.92rem; }
  .prop .lvl { margin-left:auto; font-size:.75rem; color:var(--muted); }
  .row { display:flex; align-items:center; gap:.75rem; margin:1rem 0 1.25rem; font-size:.9rem; }
  select { padding:.4rem .5rem; border:1px solid var(--border); border-radius:6px; }
  .actions { display:flex; gap:.6rem; }
  button { padding:.7rem 1rem; border-radius:8px; border:none; font-weight:600; cursor:pointer; }
  .go { background:var(--primary); color:#fff; flex:1; }
  .go:hover { background:var(--primary-dark); }
  .cancel { background:#eef2f5; color:var(--text); }
  .empty { color:var(--muted); }
</style>
</head>
<body>
  <div class="card">
    <h1><?= $h(__('gsc.picker_title')) ?></h1>
    <p class="sub"><?= $h(__('gsc.connected_account')) ?> <strong><?= $h($email) ?></strong></p>
    <?php if (empty($sites)): ?>
      <p class="empty"><?= $h(__('gsc.no_property')) ?></p>
      <div class="actions"><a class="cancel" style="text-decoration:none;padding:.7rem 1rem;border-radius:8px" href="/project.php?id=<?= $projectId ?>"><?= $h(__('gsc.back')) ?></a></div>
    <?php else: ?>
    <form method="post" action="/gsc/select-property">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="project_id" value="<?= $projectId ?>">
      <?php foreach ($sites as $i => $s): ?>
        <label class="prop">
          <input type="radio" name="site_url" value="<?= $h($s['siteUrl']) ?>" <?= $i === 0 ? 'checked' : '' ?>>
          <code><?= $h($s['siteUrl']) ?></code>
          <span class="lvl"><?= $h($s['permissionLevel']) ?></span>
        </label>
      <?php endforeach; ?>
      <div class="row">
        <label for="backfill_months"><?= $h(__('gsc.history_to_fetch')) ?></label>
        <select name="backfill_months" id="backfill_months">
          <option value="16" selected><?= $h(__('gsc.months_16_max')) ?></option>
          <option value="12"><?= $h(__('gsc.months_12')) ?></option>
          <option value="6"><?= $h(__('gsc.months_6')) ?></option>
          <option value="3"><?= $h(__('gsc.months_3')) ?></option>
        </select>
      </div>
      <div class="actions">
        <a class="cancel" style="text-decoration:none;display:inline-flex;align-items:center;padding:.7rem 1rem;border-radius:8px" href="/project.php?id=<?= $projectId ?>"><?= $h(__('gsc.cancel')) ?></a>
        <button type="submit" class="go"><?= __('gsc.connect_and_backfill') ?></button>
      </div>
    </form>
    <?php endif; ?>
  </div>
</body>
</html>
    <?php
}
