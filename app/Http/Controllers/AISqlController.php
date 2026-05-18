<?php

namespace App\Http\Controllers;

use App\Http\Controller;
use App\Http\Request;
use App\Settings\AppSettings;
use App\AI\OpenRouterClient;
use App\AI\SqlGenPrompt;

/**
 * Endpoint behind the "Generate SQL from a question" feature in the SQL
 * Explorer. Takes a natural-language question, returns one PostgreSQL
 * SELECT statement.
 *
 * The generated SQL is NOT executed here. The user reviews it in the
 * editor and clicks the existing Execute button — which runs it through
 * the regular /api/query/execute path, with the strict table whitelist,
 * crawl_id substitution, and SELECT-only enforcement still in place.
 *
 * So even if the model produces something unsafe, QueryController is the
 * authoritative security layer. This endpoint just generates text.
 *
 * @package    Scouter
 * @subpackage Http\Controllers
 */
class AISqlController extends Controller
{
    /**
     * POST /api/sql/ai-generate
     * Body: { question: string }
     */
    public function generate(Request $request): void
    {
        $question = trim((string)$request->get('question', ''));
        if ($question === '') {
            $this->error('question is required', 400);
            return;
        }

        $apiKey = (string)AppSettings::get('ai.openrouter.api_key');
        $model  = (string)AppSettings::get('ai.openrouter.model_light');
        if ($apiKey === '' || $model === '') {
            $this->error('AI provider is not configured. Ask an admin to set it up in Settings.', 400);
            return;
        }

        // Attempt 1
        $prompt   = SqlGenPrompt::build($question);
        $response = OpenRouterClient::chatCompletion($apiKey, $model, [['role' => 'user', 'content' => $prompt]]);
        $totalIn  = (int)($response['input_tokens'] ?? 0);
        $totalOut = (int)($response['output_tokens'] ?? 0);

        $sql = null;
        $error = null;
        if (!$response['ok']) {
            $error = $response['error'];
        } else {
            $sql = SqlGenPrompt::extractSql($response['text']);
            if ($sql === null) {
                $error = 'No <sql>...</sql> tag found in the model response';
            }
        }

        // One retry if the first attempt didn't return a usable SQL.
        if ($sql === null && $error !== null) {
            $retryPrompt = SqlGenPrompt::build($question, $error);
            $retry = OpenRouterClient::chatCompletion($apiKey, $model, [['role' => 'user', 'content' => $retryPrompt]]);
            $totalIn  += (int)($retry['input_tokens']  ?? 0);
            $totalOut += (int)($retry['output_tokens'] ?? 0);

            if (!$retry['ok']) {
                $error = $retry['error'];
            } else {
                $sql = SqlGenPrompt::extractSql($retry['text']);
                if ($sql === null) {
                    $error = 'No <sql>...</sql> tag found in the model response (retry)';
                }
            }
        }

        if ($sql === null) {
            $this->error('AI response could not be parsed: ' . ($error ?? 'unknown error'), 502);
            return;
        }

        $this->success([
            'sql'           => $sql,
            'model'         => $model,
            'input_tokens'  => $totalIn,
            'output_tokens' => $totalOut,
        ]);
    }
}
