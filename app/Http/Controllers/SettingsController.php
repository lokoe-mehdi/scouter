<?php

namespace App\Http\Controllers;

use App\Http\Controller;
use App\Http\Request;
use App\Settings\AppSettings;
use App\AI\GeminiClient;

/**
 * Admin-only controller for app-wide settings.
 *
 * v1 surface area = AI provider config (Gemini API key + selected model).
 * Auth is enforced by the router (`admin => true`); we still defensively
 * read settings via AppSettings rather than from the request, so a forged
 * call that bypasses the router middleware can't leak the key in clear.
 *
 * @package    Scouter
 * @subpackage Http\Controllers
 */
class SettingsController extends Controller
{
    /**
     * GET /api/settings — returns the current AI config (key MASKED).
     *
     * Includes:
     *   - has_encryption_key : whether SCOUTER_ENCRYPTION_KEY env var is set.
     *                          The UI uses this to surface a clear setup
     *                          error before the admin types their secret.
     *   - api_key_masked     : last 4 chars only, for confirmation.
     *   - model              : currently selected model id, or null.
     */
    public function show(Request $request): void
    {
        $key   = AppSettings::get('ai.gemini.api_key');
        $model = AppSettings::get('ai.gemini.model');

        $this->success([
            'has_encryption_key' => AppSettings::hasEncryptionKey(),
            'has_api_key'        => $key !== null && $key !== '',
            'api_key_masked'     => $key !== null ? AppSettings::maskSecret($key) : '',
            'model'              => $model,
        ]);
    }

    /**
     * POST /api/settings/ai/test — validates the supplied key against Gemini
     * AND returns the available models. UI uses this both for the Test button
     * and to populate the model select after a successful test.
     *
     * Body: { api_key?: string }  (if absent, uses the currently stored key)
     */
    public function testAi(Request $request): void
    {
        $apiKey = trim((string)$request->get('api_key', ''));
        if ($apiKey === '') {
            $apiKey = (string)AppSettings::get('ai.gemini.api_key');
        }
        if ($apiKey === '') {
            $this->error('No API key provided and none stored', 400);
            return;
        }

        $result = GeminiClient::listModels($apiKey);
        if (!$result['ok']) {
            $this->json(['success' => false, 'error' => $result['error']], 200);
            return;
        }

        $this->success([
            'models_count' => count($result['models']),
            'models'       => $result['models'],
        ]);
    }

    /**
     * POST /api/settings — persist provider + model. A new api_key value
     * overwrites the existing one; omitting api_key (or sending an empty
     * string) keeps whatever is already stored.
     *
     * Body: { api_key?: string, model: string }
     */
    public function save(Request $request): void
    {
        if (!AppSettings::hasEncryptionKey()) {
            $this->error(
                'SCOUTER_ENCRYPTION_KEY env var is not set on the server. '
                . 'Refusing to store an API key in plaintext.',
                400
            );
            return;
        }

        $model = trim((string)$request->get('model', ''));
        if ($model === '') {
            $this->error('Model is required', 400);
            return;
        }

        $apiKey = trim((string)$request->get('api_key', ''));
        if ($apiKey !== '') {
            if (!AppSettings::set('ai.gemini.api_key', $apiKey, $this->userId)) {
                $this->error('Failed to persist API key (encryption error)', 500);
                return;
            }
        }
        AppSettings::set('ai.provider', 'gemini', $this->userId);
        AppSettings::set('ai.gemini.model', $model, $this->userId);

        $current = (string)AppSettings::get('ai.gemini.api_key');
        $this->success([
            'has_api_key'    => $current !== '',
            'api_key_masked' => $current !== '' ? AppSettings::maskSecret($current) : '',
            'model'          => $model,
        ]);
    }
}
