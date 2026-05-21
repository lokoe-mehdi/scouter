<?php

namespace App\Http\Controllers;

use App\Http\Controller;
use App\Http\Request;
use App\Api\ApiKeyService;

/**
 * Admin-only management of personal API keys (session-authenticated, called by
 * the Settings page). Distinct from the public token API (/api/v1/*).
 *
 * The plaintext token is returned ONLY by create(); afterwards only metadata
 * (name, prefix, dates) is ever exposed.
 *
 * @package    Scouter
 * @subpackage Http\Controllers
 */
class ApiKeyController extends Controller
{
    /** GET /api/keys — list the current admin's active keys (metadata only). */
    public function index(Request $request): void
    {
        $this->success(['keys' => ApiKeyService::listForUser((int)$this->userId)]);
    }

    /** POST /api/keys — create a key; returns the plaintext token ONCE. */
    public function create(Request $request): void
    {
        $name = trim((string)$request->get('name', ''));
        $created = ApiKeyService::generate((int)$this->userId, $name);
        // `token` is shown this one time only — the client must store it now.
        $this->success([
            'id'     => $created['id'],
            'name'   => $created['name'],
            'prefix' => $created['prefix'],
            'token'  => $created['token'],
        ], 'API key created — copy it now, it will not be shown again.');
    }

    /** DELETE /api/keys/{id} — revoke one of the admin's keys. */
    public function revoke(Request $request): void
    {
        $id = (int)$request->param('id', 0);
        if ($id <= 0) { $this->error('Invalid key id', 400); return; }
        $ok = ApiKeyService::revoke($id, (int)$this->userId);
        if (!$ok) { $this->error('Key not found', 404); return; }
        $this->success(['revoked' => true]);
    }
}
