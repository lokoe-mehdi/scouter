<?php

namespace App\AI;

use App\Util\SafeHttp;

/**
 * Streaming client for Gemini's `streamGenerateContent` endpoint.
 *
 * Returns a generator that yields parsed events as they arrive from the
 * model — text deltas and function calls. The caller (ChatAgent) consumes
 * the stream and either forwards text to the browser as SSE or pauses to
 * execute tools and re-stream.
 *
 * Why a generator: we want each token / chunk to be forwarded to the user
 * immediately, not buffered until the full Gemini response is in. Generators
 * make that natural in PHP.
 *
 * Event shapes (yielded values):
 *   ['type' => 'text',     'text' => '...']           — text delta
 *   ['type' => 'tool_call','id' => '...', 'name' => 'run_sql', 'args' => [...]]
 *   ['type' => 'usage',    'input_tokens' => int, 'output_tokens' => int]
 *   ['type' => 'error',    'message' => '...']
 *
 * @package    Scouter
 * @subpackage AI
 */
class GeminiStream
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta';
    private const TIMEOUT_TOTAL = 300; // 5 min — chat turns can include tool execution

    /**
     * Stream a generateContent call. Yields events one by one.
     *
     * @param string $apiKey
     * @param string $model           e.g. 'gemini-2.5-flash'
     * @param string $systemInstruction
     * @param array  $contents        array of {role, parts:[{text}|{functionCall}|{functionResponse}]}
     * @param array  $tools           [{functionDeclarations:[...]}] or empty
     * @return \Generator yielding events
     */
    public function stream(
        string $apiKey,
        string $model,
        string $systemInstruction,
        array $contents,
        array $tools = []
    ): \Generator {
        $url = self::BASE . '/models/' . rawurlencode($model)
            . ':streamGenerateContent?alt=sse&key=' . rawurlencode($apiKey);

        // Default payload: no thinking, snappy. Some models (deep-research,
        // certain previews) reject thinkingBudget=0 — we detect that error
        // below and redo the request without thinkingConfig as a fallback.
        $payload = [
            'systemInstruction' => [
                'parts' => [['text' => $systemInstruction]],
            ],
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => 0.3,
                'thinkingConfig' => ['thinkingBudget' => 0],
            ],
        ];
        if (!empty($tools)) {
            $payload['tools'] = $tools;
        }

        // First attempt — with thinking disabled.
        $attempt = $this->runOnce($url, $payload);

        // If the model requires thinking mode, drop thinkingConfig and retry.
        // We swallow the events from the failed attempt and only stream
        // events from the successful one — otherwise the browser would see
        // a misleading "error" event followed by the actual response.
        if ($this->isThinkingRequiredError($attempt)) {
            unset($payload['generationConfig']['thinkingConfig']);
            error_log('[GeminiStream] Model requires thinking; retrying without thinkingConfig.');
            $attempt = $this->runOnce($url, $payload);
        }

        foreach ($attempt['events'] as $ev) {
            yield $ev;
        }
    }

    /**
     * One streaming attempt against Gemini. Returns:
     *   ['events' => [...parsed events including errors], 'raw' => string,
     *    'status' => int, 'curlErr' => string]
     */
    private function runOnce(string $url, array $payload): array
    {
        $buffer = '';
        $rawAccum = '';
        $eventQueue = [];
        $emit = static function ($event) use (&$eventQueue) {
            $eventQueue[] = $event;
        };

        $writeFn = function ($ch, $chunk) use (&$buffer, &$rawAccum, $emit) {
            $rawAccum .= $chunk;
            $buffer .= str_replace("\r", '', $chunk);
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $rawEvent = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);
                $this->parseSseEvent($rawEvent, $emit);
            }
            return strlen($chunk);
        };

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: text/event-stream',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT_TOTAL);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, $writeFn);
        SafeHttp::applyCurlSecurity($ch);

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $errmsg = $errno ? curl_error($ch) : '';
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($buffer !== '') {
            $this->parseSseEvent($buffer, $emit);
        }

        // Surface errors from this attempt — caller may decide to swallow them
        // and retry (e.g. thinking-required fallback).
        if (!$ok || $errno) {
            $emit(['type' => 'error', 'message' => 'Gemini network error: ' . $errmsg]);
        } elseif ($status >= 400) {
            $msg = 'Gemini HTTP ' . $status;
            $decoded = json_decode($rawAccum, true);
            if (is_array($decoded) && isset($decoded['error']['message'])) {
                $msg .= ' — ' . $decoded['error']['message'];
            } elseif ($rawAccum !== '') {
                $msg .= ' — ' . substr($rawAccum, 0, 400);
            }
            $emit(['type' => 'error', 'message' => $msg]);
        } elseif (empty($eventQueue)) {
            error_log('[GeminiStream] 200 but no SSE events parsed. Body head: '
                . substr($rawAccum, 0, 500));
            $emit(['type' => 'error', 'message' =>
                'Gemini returned 200 but no parseable events. '
                . 'Body head: ' . substr($rawAccum, 0, 200)]);
        }

        return [
            'events'  => $eventQueue,
            'raw'     => $rawAccum,
            'status'  => $status,
            'curlErr' => $errmsg,
        ];
    }

    /**
     * Detect the specific error returned by models that require thinking
     * mode (deep-research-*, certain preview models). Messages look like:
     *   "Budget 0 is invalid. This model only works in thinking mode."
     */
    private function isThinkingRequiredError(array $attempt): bool
    {
        // Check both: an explicit HTTP 400 with error message in the body,
        // OR a parsed event of type 'error' with the same hint.
        $msg = '';
        if ($attempt['status'] >= 400 && $attempt['raw'] !== '') {
            $decoded = json_decode($attempt['raw'], true);
            if (is_array($decoded) && isset($decoded['error']['message'])) {
                $msg = (string)$decoded['error']['message'];
            }
        }
        if ($msg === '') {
            foreach ($attempt['events'] as $ev) {
                if (($ev['type'] ?? '') === 'error') {
                    $msg = (string)($ev['message'] ?? '');
                    if ($msg !== '') break;
                }
            }
        }
        if ($msg === '') return false;
        $low = strtolower($msg);
        return (strpos($low, 'thinking mode') !== false)
            || (strpos($low, 'budget 0 is invalid') !== false)
            || (strpos($low, 'thinkingbudget') !== false && strpos($low, 'invalid') !== false);
    }

    /**
     * Parse a single SSE event block ("data: {json}\n").
     * Emits text/tool_call/usage events via the $emit callback.
     */
    private function parseSseEvent(string $raw, callable $emit): void
    {
        $lines = preg_split('/\r?\n/', $raw);
        $dataParts = [];
        foreach ($lines as $line) {
            if (strpos($line, 'data:') === 0) {
                $dataParts[] = ltrim(substr($line, 5));
            }
        }
        if (empty($dataParts)) return;
        $jsonStr = implode("\n", $dataParts);
        if ($jsonStr === '' || $jsonStr === '[DONE]') return;

        $parsed = json_decode($jsonStr, true);
        if (!is_array($parsed)) return;

        // Surface API errors immediately.
        if (isset($parsed['error']['message'])) {
            $emit(['type' => 'error', 'message' => (string)$parsed['error']['message']]);
            return;
        }

        // Token usage (sent on the final chunk usually).
        if (isset($parsed['usageMetadata'])) {
            $emit([
                'type'          => 'usage',
                'input_tokens'  => (int)($parsed['usageMetadata']['promptTokenCount']     ?? 0),
                'output_tokens' => (int)($parsed['usageMetadata']['candidatesTokenCount'] ?? 0),
            ]);
        }

        $candidates = $parsed['candidates'] ?? [];
        foreach ($candidates as $cand) {
            $parts = $cand['content']['parts'] ?? [];
            foreach ($parts as $part) {
                // Gemini 3+ attaches a `thoughtSignature` (opaque blob) to
                // both text and functionCall parts. When we re-send the
                // assistant turn after tool execution, those signatures MUST
                // be preserved on the matching parts or Gemini rejects with:
                //   "Function call is missing a thought_signature in functionCall parts"
                // We pass them through verbatim — never inspect, never tamper.
                $signature = isset($part['thoughtSignature']) ? (string)$part['thoughtSignature'] : null;

                if (isset($part['text']) && $part['text'] !== '') {
                    $ev = ['type' => 'text', 'text' => (string)$part['text']];
                    if ($signature !== null) $ev['signature'] = $signature;
                    $emit($ev);
                }
                if (isset($part['functionCall'])) {
                    $fc = $part['functionCall'];
                    $ev = [
                        'type' => 'tool_call',
                        'id'   => $fc['name'] ?? 'call_' . uniqid(),
                        'name' => (string)($fc['name'] ?? ''),
                        'args' => is_array($fc['args'] ?? null) ? $fc['args'] : [],
                    ];
                    if ($signature !== null) $ev['signature'] = $signature;
                    $emit($ev);
                }
            }
        }
    }
}
