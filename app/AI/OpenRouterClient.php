<?php

namespace App\AI;

use App\Util\SafeHttp;

/**
 * Thin HTTP client for the OpenRouter API.
 *
 * OpenRouter (https://openrouter.ai) is an OpenAI-compatible aggregator that
 * proxies many model providers (OpenAI, Anthropic, Google, Mistral, …) behind
 * a single API and a single billing account. Three operations matter for
 * Scouter :
 *
 *   - validateKey($apiKey)   — hit /auth/key to confirm the key works AND get
 *                              the remaining credit (used in Settings UI).
 *   - listModels()           — populate the two model selectors. No auth needed.
 *                              Result is cached in-process for the request.
 *   - chatCompletion(...)    — non-streaming completion for one-shot features
 *                              (categorization, NL→SQL, filter suggestions).
 *
 * Streaming chat lives in OpenRouterStream — separate file because the cURL
 * write-callback plumbing is involved enough on its own.
 *
 * Every outbound request goes through SafeHttp::applyCurlSecurity() — defence
 * in depth: OpenRouter is a public hostname but if DNS ever lies (or we move
 * to a self-hosted gateway) the SSRF guard catches it.
 *
 * No SDK on purpose — keeps the dependency surface small and audit-friendly.
 *
 * @package    Scouter
 * @subpackage AI
 */
class OpenRouterClient
{
    public const BASE = 'https://openrouter.ai/api/v1';

    private const TIMEOUT_QUICK    = 30;   // /auth/key, /models
    private const TIMEOUT_COMPLETE = 180;  // /chat/completions — some models take 30–90s TTFB

    /** In-process /models cache so multiple calls in the same request don't re-fetch. */
    private static ?array $modelsCache = null;

    /**
     * Validate an OpenRouter key. Returns:
     *   ['ok' => true, 'label' => string, 'usage' => float|null, 'limit' => float|null,
     *    'is_free_tier' => bool, 'rate_limit' => array|null]
     *   ['ok' => false, 'error' => string]
     *
     * `usage`/`limit` come from /auth/key in the same units OpenRouter bills in
     * (USD credits). When the account is on a pure pay-as-you-go plan,
     * `limit` is null and only `usage` (lifetime) is meaningful.
     */
    public static function validateKey(string $apiKey): array
    {
        $url = self::BASE . '/auth/key';
        $response = self::httpGet($url, $apiKey);
        if (!$response['ok']) {
            return $response;
        }

        $body = json_decode($response['body'], true);
        if (!is_array($body) || !isset($body['data'])) {
            return ['ok' => false, 'error' => 'Unexpected response from OpenRouter /auth/key'];
        }

        $d = $body['data'];
        return [
            'ok'           => true,
            'label'        => (string)($d['label'] ?? ''),
            'usage'        => isset($d['usage']) ? (float)$d['usage'] : null,
            'limit'        => isset($d['limit']) && $d['limit'] !== null ? (float)$d['limit'] : null,
            'is_free_tier' => (bool)($d['is_free_tier'] ?? false),
            'rate_limit'   => is_array($d['rate_limit'] ?? null) ? $d['rate_limit'] : null,
        ];
    }

    /**
     * List all available OpenRouter models. Returns:
     *   ['ok' => true, 'models' => [...]]
     *   ['ok' => false, 'error' => string]
     *
     * Each model is normalised to:
     *   ['id', 'name', 'context_length', 'prompt_price', 'completion_price',
     *    'supports_tools' (bool), 'modalities' (string)]
     *
     * The endpoint is unauthenticated; we hit it without an API key.
     */
    public static function listModels(): array
    {
        if (self::$modelsCache !== null) {
            return self::$modelsCache;
        }

        $url = self::BASE . '/models';
        $response = self::httpGet($url, null);
        if (!$response['ok']) {
            return $response;
        }

        $body = json_decode($response['body'], true);
        if (!is_array($body) || !isset($body['data']) || !is_array($body['data'])) {
            return ['ok' => false, 'error' => 'Unexpected response from OpenRouter /models'];
        }

        $models = [];
        foreach ($body['data'] as $m) {
            $id = (string)($m['id'] ?? '');
            if ($id === '') continue;

            $supported = $m['supported_parameters'] ?? [];
            if (!is_array($supported)) $supported = [];
            // A model "supports tools" if it accepts the OpenAI-style tools array
            // — that's what we need for Dr. Brief (run_sql, get_page_headings).
            $supportsTools = in_array('tools', $supported, true)
                          || in_array('tool_choice', $supported, true);

            $pricing = $m['pricing'] ?? [];
            $modalities = (string)($m['architecture']['modality'] ?? 'text->text');

            $models[] = [
                'id'               => $id,
                'name'             => (string)($m['name'] ?? $id),
                'context_length'   => (int)($m['context_length'] ?? 0),
                // Prices are per-token in USD as strings — keep them as floats
                // for easy display ("$X per 1M tokens" = price * 1e6).
                'prompt_price'     => isset($pricing['prompt'])     ? (float)$pricing['prompt']     : 0.0,
                'completion_price' => isset($pricing['completion']) ? (float)$pricing['completion'] : 0.0,
                'supports_tools'   => $supportsTools,
                'modalities'       => $modalities,
            ];
        }

        // Sort by name for a stable, scannable dropdown order.
        usort($models, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        self::$modelsCache = ['ok' => true, 'models' => $models];
        return self::$modelsCache;
    }

    /**
     * Non-streaming chat completion. Use for one-shot features (categorize,
     * NL→SQL, filter suggestions). Returns:
     *   ['ok' => true, 'text' => string, 'input_tokens' => int, 'output_tokens' => int]
     *   ['ok' => false, 'error' => string]
     *
     * @param string $apiKey   OpenRouter API key
     * @param string $model    Model id (e.g. "openai/gpt-4o-mini")
     * @param array  $messages OpenAI-style: [{role: 'system'|'user'|'assistant', content: '...'}]
     * @param array  $options  Optional: temperature, max_tokens, tools, tool_choice
     */
    public static function chatCompletion(
        string $apiKey,
        string $model,
        array $messages,
        array $options = []
    ): array {
        $payload = [
            'model'    => $model,
            'messages' => $messages,
            'stream'   => false,
        ];
        // Temperature defaults to 0.2 — the one-shot features want determinism
        // (extract a YAML tag, generate a SQL query, …), not creativity.
        $payload['temperature'] = isset($options['temperature'])
            ? (float)$options['temperature']
            : 0.2;
        if (isset($options['max_tokens']))   $payload['max_tokens']   = (int)$options['max_tokens'];
        if (isset($options['tools']))        $payload['tools']        = $options['tools'];
        if (isset($options['tool_choice']))  $payload['tool_choice']  = $options['tool_choice'];
        if (isset($options['response_format'])) $payload['response_format'] = $options['response_format'];

        $url = self::BASE . '/chat/completions';
        $response = self::httpPost($url, json_encode($payload), $apiKey, self::TIMEOUT_COMPLETE);
        if (!$response['ok']) {
            return $response;
        }

        $body = json_decode($response['body'], true);
        if (!is_array($body)) {
            return ['ok' => false, 'error' => 'Unexpected response from OpenRouter (not JSON)'];
        }
        if (isset($body['error']['message'])) {
            return ['ok' => false, 'error' => (string)$body['error']['message']];
        }

        // Standard OpenAI shape: choices[0].message.content. tool_calls is
        // also possible but the non-streaming path here is only used for
        // single-shot text completions that don't expose tools.
        $text = $body['choices'][0]['message']['content'] ?? null;
        if (!is_string($text)) {
            // Empty content with finish_reason=stop happens when the model
            // returned a tool_call instead of text. Surface a clear error
            // since the non-streaming path doesn't handle tools.
            return ['ok' => false, 'error' => 'Empty response from OpenRouter (no content in first choice)'];
        }

        return [
            'ok'            => true,
            'text'          => $text,
            'input_tokens'  => (int)($body['usage']['prompt_tokens']     ?? 0),
            'output_tokens' => (int)($body['usage']['completion_tokens'] ?? 0),
        ];
    }

    /**
     * Wrap a bare function declaration (name/description/parameters) in the
     * OpenAI/OpenRouter tool envelope. Lets our tools (SqlQueryTool,
     * HeadingsTool) keep their declarations format-agnostic.
     */
    public static function wrapToolDeclaration(array $functionDecl): array
    {
        return [
            'type'     => 'function',
            'function' => $functionDecl,
        ];
    }

    /**
     * Recommended headers for OpenRouter — appears on their leaderboards and
     * helps support diagnose issues. Title kept generic on purpose (no user
     * identifier ever leaks).
     *
     * @return string[]
     */
    public static function defaultHeaders(?string $apiKey): array
    {
        $headers = [
            'Content-Type: application/json',
            'HTTP-Referer: https://github.com/scouter',
            'X-Title: Scouter',
        ];
        if ($apiKey !== null && $apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }
        return $headers;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private static function httpGet(string $url, ?string $apiKey): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, self::defaultHeaders($apiKey));
        return self::execute($ch, self::TIMEOUT_QUICK);
    }

    private static function httpPost(string $url, string $body, ?string $apiKey, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, self::defaultHeaders($apiKey));
        return self::execute($ch, $timeout);
    }

    private static function execute($ch, int $timeoutSeconds): array
    {
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
        SafeHttp::applyCurlSecurity($ch);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $errmsg = $errno ? curl_error($ch) : '';
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

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
        if ($status === 401 || $status === 403) {
            return ['ok' => false, 'error' => 'API key invalid or unauthorized (HTTP ' . $status . ')'];
        }
        if ($status === 429) {
            return ['ok' => false, 'error' => 'Rate limited by OpenRouter (HTTP 429)'];
        }
        if ($status >= 400) {
            // Surface a clean error message when the body carries one.
            $decoded = json_decode((string)$body, true);
            $msg = $decoded['error']['message'] ?? "HTTP {$status}";
            return ['ok' => false, 'error' => (string)$msg];
        }

        return ['ok' => true, 'body' => (string)$body];
    }
}
