<?php

namespace App\AI;

use App\Util\SafeHttp;

/**
 * Thin HTTP client for the Google Gemini API.
 *
 * Three operations matter for Scouter:
 *   - testKey($apiKey)        — cheap call to validate a key before persisting it.
 *   - listModels($apiKey)     — populate the model picker in Settings.
 *   - generateContent(...)    — actual categorization call.
 *
 * Every outbound request goes through SafeHttp::applyCurlSecurity() to keep
 * the SSRF guard active even on this trusted endpoint (defense in depth).
 *
 * No SDK on purpose — keeps the dependency surface small and audit-friendly.
 *
 * @package    Scouter
 * @subpackage AI
 */
class GeminiClient
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta';
    // Quick metadata calls (listModels, key check) must be snappy.
    private const TIMEOUT_QUICK = 30;
    // generateContent on thinking models (Gemini 2.5 Pro/Flash, 3.x) can take
    // 30–90s before the first byte arrives. Give it real headroom.
    private const TIMEOUT_GENERATE = 180;

    /**
     * Quick liveness check on an API key. Returns:
     *   ['ok' => true,  'models_count' => int]
     *   ['ok' => false, 'error' => string]
     */
    public static function testKey(string $apiKey): array
    {
        $result = self::listModels($apiKey);
        if (!$result['ok']) {
            return $result;
        }
        return ['ok' => true, 'models_count' => count($result['models'])];
    }

    /**
     * List models that support generateContent. Returns:
     *   ['ok' => true,  'models' => [...], 'raw_count' => int]
     *   ['ok' => false, 'error' => string]
     *
     * `raw_count` is the total number of models returned by the API before any
     * filtering — useful to distinguish "the API returned nothing" from "the
     * filter dropped everything".
     */
    public static function listModels(string $apiKey): array
    {
        // Some keys/regions only return one model per page if pageSize is omitted,
        // so we both request a large pageSize AND follow pageToken to be safe.
        $allRaw = [];
        $pageToken = null;
        $safetyMax = 20; // hard cap on the number of pages to fetch
        while ($safetyMax-- > 0) {
            $url = self::BASE . '/models?pageSize=1000&key=' . rawurlencode($apiKey);
            if ($pageToken !== null) {
                $url .= '&pageToken=' . rawurlencode($pageToken);
            }
            $response = self::httpGet($url);
            if (!$response['ok']) {
                return $response;
            }
            $body = json_decode($response['body'], true);
            if (!is_array($body)) {
                return ['ok' => false, 'error' => 'Unexpected response from Gemini API (not JSON)'];
            }
            $models = $body['models'] ?? [];
            if (!is_array($models)) {
                return ['ok' => false, 'error' => 'Unexpected response from Gemini API (no models array)'];
            }
            $allRaw = array_merge($allRaw, $models);
            $pageToken = $body['nextPageToken'] ?? null;
            if (!is_string($pageToken) || $pageToken === '') {
                break;
            }
        }

        $rawCount = count($allRaw);

        $models = [];
        foreach ($allRaw as $m) {
            // The API documents the field as "supportedGenerationMethods", but some
            // SDKs and proxies serialize it as "supported_generation_methods" (snake_case).
            // Accept both, and compare case-insensitively to be robust.
            $methods = $m['supportedGenerationMethods']
                    ?? $m['supported_generation_methods']
                    ?? [];
            if (!is_array($methods)) {
                $methods = [];
            }
            $hasGenerate = false;
            foreach ($methods as $method) {
                if (is_string($method) && strcasecmp($method, 'generateContent') === 0) {
                    $hasGenerate = true;
                    break;
                }
            }
            if (!$hasGenerate) {
                continue;
            }

            // Strip the "models/" prefix to get the bare ID used in generateContent.
            $rawName = (string)($m['name'] ?? $m['baseModelId'] ?? '');
            $id = strpos($rawName, 'models/') === 0 ? substr($rawName, 7) : $rawName;
            if ($id === '') {
                continue;
            }
            $models[] = [
                'id'           => $id,
                'display_name' => (string)($m['displayName'] ?? $m['display_name'] ?? $id),
                'input_limit'  => (int)($m['inputTokenLimit']  ?? $m['input_token_limit']  ?? 0),
                'output_limit' => (int)($m['outputTokenLimit'] ?? $m['output_token_limit'] ?? 0),
            ];
        }

        // Stable order: newest-looking names first by sorting display name desc.
        usort($models, fn($a, $b) => strcmp($b['display_name'], $a['display_name']));

        // If the API returned models but the filter wiped everything, surface a
        // clear error rather than a silent "0 models" — helps diagnose API drift.
        if ($rawCount > 0 && empty($models)) {
            return [
                'ok' => false,
                'error' => "API returned {$rawCount} models but none expose 'generateContent'. "
                         . "Field name may have changed — check GeminiClient::listModels.",
            ];
        }

        return ['ok' => true, 'models' => $models, 'raw_count' => $rawCount];
    }

    /**
     * Call generateContent. Returns:
     *   ['ok' => true,  'text' => string, 'input_tokens' => int, 'output_tokens' => int]
     *   ['ok' => false, 'error' => string]
     */
    public static function generateContent(string $apiKey, string $model, string $prompt): array
    {
        $url = self::BASE . '/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
        // Disable "thinking" mode by default — for our use cases (categorize,
        // filter, SQL gen) we want pattern matching, not deep reasoning, and
        // thinking can add 30–60s of latency before any output. Some models
        // (deep-research-*, certain preview models) REQUIRE thinking and will
        // reject thinkingBudget=0 with HTTP 400 — handled below with a retry.
        $base = [
            'contents' => [
                ['parts' => [['text' => $prompt]]],
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'thinkingConfig' => ['thinkingBudget' => 0],
            ],
        ];

        $response = self::httpPost($url, json_encode($base), self::TIMEOUT_GENERATE);

        // Auto-fallback: if the model requires thinking, drop thinkingConfig
        // and retry. This way we keep the fast no-thinking path as default
        // and only pay the latency on models that have no choice.
        if (self::isThinkingRequiredError($response)) {
            unset($base['generationConfig']['thinkingConfig']);
            $response = self::httpPost($url, json_encode($base), self::TIMEOUT_GENERATE);
        }

        if (!$response['ok']) {
            return $response;
        }

        $body = json_decode($response['body'], true);
        if (!is_array($body)) {
            return ['ok' => false, 'error' => 'Unexpected response from Gemini API'];
        }

        if (isset($body['error']['message'])) {
            return ['ok' => false, 'error' => (string)$body['error']['message']];
        }

        $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!is_string($text)) {
            return ['ok' => false, 'error' => 'Empty response from Gemini'];
        }

        return [
            'ok'            => true,
            'text'          => $text,
            'input_tokens'  => (int)($body['usageMetadata']['promptTokenCount']     ?? 0),
            'output_tokens' => (int)($body['usageMetadata']['candidatesTokenCount'] ?? 0),
        ];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Detect the specific 400 we get from models that don't support
     * thinkingBudget=0 (deep-research, some preview models). Message looks
     * like "Budget 0 is invalid. This model only works in thinking mode."
     */
    public static function isThinkingRequiredError(array $response): bool
    {
        // Either we got an HTTP error and the error message is in $response['error'],
        // or we got a 200 with an error embedded in the body — check both.
        $msg = '';
        if (!empty($response['error'])) {
            $msg = (string)$response['error'];
        } elseif (!empty($response['body'])) {
            $decoded = json_decode($response['body'], true);
            $msg = (string)($decoded['error']['message'] ?? '');
        }
        if ($msg === '') return false;
        $low = strtolower($msg);
        return (strpos($low, 'thinking mode') !== false)
            || (strpos($low, 'budget 0 is invalid') !== false)
            || (strpos($low, 'thinkingbudget') !== false && strpos($low, 'invalid') !== false);
    }

    private static function httpGet(string $url): array
    {
        $ch = curl_init($url);
        return self::execute($ch, self::TIMEOUT_QUICK);
    }

    private static function httpPost(string $url, string $body, int $timeout = self::TIMEOUT_QUICK): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
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
            return ['ok' => false, 'error' => 'Rate limited by Gemini API (HTTP 429)'];
        }
        if ($status >= 400) {
            // Try to surface a clean error message from the body when available.
            $decoded = json_decode((string)$body, true);
            $msg = $decoded['error']['message'] ?? "HTTP {$status}";
            return ['ok' => false, 'error' => (string)$msg];
        }

        return ['ok' => true, 'body' => (string)$body];
    }
}
