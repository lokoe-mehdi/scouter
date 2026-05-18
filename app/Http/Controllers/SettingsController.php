<?php

namespace App\Http\Controllers;

use App\Http\Controller;
use App\Http\Request;
use App\Settings\AppSettings;
use App\AI\OpenRouterClient;
use App\AI\DrBriefPrompt;

/**
 * Admin-only controller for app-wide settings.
 *
 * v1 surface area = AI provider config (OpenRouter API key + two models : a
 * light model for one-shot tasks like categorization / NL→SQL / filter
 * suggestions, and a stronger model for the Dr. Brief chatbot which uses
 * function calling and needs more reasoning).
 *
 * Auth is enforced by the router (`admin => true`) ; we still defensively
 * read settings via AppSettings rather than from the request, so a forged
 * call that bypassed the router middleware couldn't leak the key.
 *
 * @package    Scouter
 * @subpackage Http\Controllers
 */
class SettingsController extends Controller
{
    /**
     * GET /api/settings — returns the current AI config (key MASKED).
     *
     * Returns the persisted state only. The frontend triggers a separate
     * /ai/test call (which hits OpenRouter) when the admin clicks "Test" —
     * we don't make the page load pay for a network round-trip.
     */
    public function show(Request $request): void
    {
        $key            = AppSettings::get('ai.openrouter.api_key');
        $modelLight     = AppSettings::get('ai.openrouter.model_light');
        $modelStrong    = AppSettings::get('ai.openrouter.model_strong');
        $drBriefPrompt  = AppSettings::get(DrBriefPrompt::SETTINGS_KEY);

        $this->success([
            'has_encryption_key'      => AppSettings::hasEncryptionKey(),
            'has_api_key'             => $key !== null && $key !== '',
            'api_key_masked'          => $key !== null ? AppSettings::maskSecret($key) : '',
            'model_light'             => $modelLight,
            'model_strong'            => $modelStrong,
            // Dr. Brief prompt customisation: the admin can edit the system
            // prompt template via /settings. We expose the current override
            // (or empty when none is set), the default template (for the
            // "Restore default" button), and the list of {placeholder}
            // variables available with their descriptions.
            'dr_brief_prompt'         => $drBriefPrompt ?? '',
            'dr_brief_prompt_default' => DrBriefPrompt::defaultTemplate(),
            'dr_brief_variables'      => DrBriefPrompt::availableVariables(),
        ]);
    }

    /**
     * POST /api/settings/ai/prompt — persist a custom Dr. Brief system prompt
     * template. The body `prompt` field is stored as-is in app_settings under
     * `ai.openrouter.dr_brief_prompt`. Send an empty string to revert to the
     * built-in default — `DrBriefPrompt::build()` falls back automatically
     * when the stored value is empty.
     *
     * Body : { prompt: string }
     */
    public function saveDrBriefPrompt(Request $request): void
    {
        $prompt = (string)$request->get('prompt', '');
        // No length cap on purpose : the admin owns this config, and the
        // model's context window will hard-limit anything truly excessive.
        AppSettings::set(DrBriefPrompt::SETTINGS_KEY, $prompt, $this->userId);

        $this->success([
            'has_custom_prompt' => trim($prompt) !== '',
            'prompt_length'     => mb_strlen($prompt),
        ]);
    }

    /**
     * POST /api/settings/ai/test — validate the supplied key against
     * OpenRouter AND return both the available models (for the two
     * selectors) and the account info (label + credit remaining).
     *
     * Body: { api_key?: string }  (if absent, uses the currently stored key)
     */
    public function testAi(Request $request): void
    {
        $apiKey = trim((string)$request->get('api_key', ''));
        if ($apiKey === '') {
            $apiKey = (string)AppSettings::get('ai.openrouter.api_key');
        }
        if ($apiKey === '') {
            $this->error('No API key provided and none stored', 400);
            return;
        }

        // 1. Validate the key + pull account info (label, credit).
        $info = OpenRouterClient::validateKey($apiKey);
        if (!$info['ok']) {
            $this->json(['success' => false, 'error' => $info['error']], 200);
            return;
        }

        // 2. Fetch the model catalog. /models is unauthenticated so it doesn't
        //    actually need the key — but we still do it here because the UI
        //    relies on a successful test to populate the dropdowns.
        $list = OpenRouterClient::listModels();
        if (!$list['ok']) {
            $this->json(['success' => false, 'error' => 'Key OK but model list failed: ' . $list['error']], 200);
            return;
        }

        $this->success([
            'account' => [
                'label'        => $info['label'],
                'usage'        => $info['usage'],
                'limit'        => $info['limit'],
                'is_free_tier' => $info['is_free_tier'],
            ],
            'models_count' => count($list['models']),
            'models'       => $list['models'],
        ]);
    }

    /**
     * POST /api/settings — persist key + the two model selections.
     *
     * Body : {
     *   api_key?: string,        // optional — omit to keep the stored value
     *   model_light:  string,    // required
     *   model_strong: string,    // required
     * }
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

        $modelLight  = trim((string)$request->get('model_light', ''));
        $modelStrong = trim((string)$request->get('model_strong', ''));
        if ($modelLight === '' || $modelStrong === '') {
            $this->error('Both model_light and model_strong are required', 400);
            return;
        }

        $apiKey = trim((string)$request->get('api_key', ''));
        if ($apiKey !== '') {
            if (!AppSettings::set('ai.openrouter.api_key', $apiKey, $this->userId)) {
                $this->error('Failed to persist API key (encryption error)', 500);
                return;
            }
        }
        AppSettings::set('ai.openrouter.model_light',  $modelLight,  $this->userId);
        AppSettings::set('ai.openrouter.model_strong', $modelStrong, $this->userId);

        $current = (string)AppSettings::get('ai.openrouter.api_key');
        $this->success([
            'has_api_key'    => $current !== '',
            'api_key_masked' => $current !== '' ? AppSettings::maskSecret($current) : '',
            'model_light'    => $modelLight,
            'model_strong'   => $modelStrong,
        ]);
    }
}
