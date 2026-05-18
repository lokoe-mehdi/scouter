<?php

namespace App\AI;

use App\Util\SafeHttp;

/**
 * Streaming client for OpenRouter's /chat/completions endpoint (stream:true).
 *
 * Returns a generator that yields normalised events as they arrive from the
 * upstream model. Text deltas come through chunk-by-chunk and are forwarded
 * immediately ; tool calls are accumulated across delta fragments (OpenAI
 * splits `function.arguments` across multiple chunks) and yielded once the
 * upstream signals `finish_reason: "tool_calls"` or the stream ends.
 *
 * Event shapes (yielded values) — same vocabulary as the old GeminiStream so
 * ChatAgent can stay roughly unchanged :
 *   ['type' => 'text',      'text' => '...']
 *   ['type' => 'tool_call', 'id' => 'call_xxx', 'name' => 'run_sql', 'args' => [...]]
 *   ['type' => 'usage',     'input_tokens' => int, 'output_tokens' => int]
 *   ['type' => 'error',     'message' => '...']
 *
 * Notes on the OpenRouter / OpenAI SSE protocol :
 *   - Each event is `data: <json>\n\n` with the same JSON shape as the
 *     non-streaming response, except `message` becomes `delta`.
 *   - Lines starting with `:` are SSE comments (keepalives) — silently dropped.
 *   - The final marker is `data: [DONE]\n\n` — also silently dropped.
 *   - `usage` only appears in the final chunk (and only when stream_options
 *     {"include_usage": true} is set ; we always set it).
 *
 * @package    Scouter
 * @subpackage AI
 */
class OpenRouterStream
{
    private const TIMEOUT_TOTAL = 300; // 5 min — chat turns include tool execution latency

    /**
     * Stream a chat completion. Yields events one by one.
     *
     * @param string $apiKey
     * @param string $model           e.g. 'openai/gpt-4o' or 'anthropic/claude-3.5-sonnet'
     * @param array  $messages        OpenAI shape: [{role, content}, {role:'tool', tool_call_id, content}, ...]
     * @param array  $tools           [{type:'function', function:{name, description, parameters}}, ...] or []
     * @return \Generator yielding events
     */
    public function stream(
        string $apiKey,
        string $model,
        array $messages,
        array $tools = []
    ): \Generator {
        $url = OpenRouterClient::BASE . '/chat/completions';

        $payload = [
            'model'    => $model,
            'messages' => $messages,
            'stream'   => true,
            // Ask OpenRouter to include usage in the final chunk so we can
            // attribute cost per turn in ai_chat_runs. Without this, no usage.
            'stream_options' => ['include_usage' => true],
            'temperature' => 0.3,
        ];
        if (!empty($tools)) {
            $payload['tools']       = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $events = $this->runOnce($url, $apiKey, $payload);
        foreach ($events as $ev) {
            yield $ev;
        }
    }

    /**
     * Drive one HTTP request, parse the SSE stream, return the full event list.
     *
     * @return array<int, array> events ready to yield
     */
    private function runOnce(string $url, string $apiKey, array $payload): array
    {
        $buffer = '';
        $rawAccum = '';
        $eventQueue = [];
        // Tool-call accumulators keyed by `index` (OpenAI's required index field
        // — id+name come on the first chunk, arguments fragments come on later
        // chunks under the same index).
        $toolAcc = [];

        $emit = static function ($event) use (&$eventQueue) {
            $eventQueue[] = $event;
        };

        $self = $this;
        $writeFn = function ($ch, $chunk) use (&$buffer, &$rawAccum, &$toolAcc, $emit, $self) {
            $rawAccum .= $chunk;
            $buffer .= str_replace("\r", '', $chunk);
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $rawEvent = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);
                $self->parseSseEvent($rawEvent, $emit, $toolAcc);
            }
            return strlen($chunk);
        };

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(
            OpenRouterClient::defaultHeaders($apiKey),
            ['Accept: text/event-stream']
        ));
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

        // Flush any trailing partial buffer.
        if ($buffer !== '') {
            $this->parseSseEvent($buffer, $emit, $toolAcc);
        }

        // Emit any accumulated tool calls that the stream didn't already
        // close out via finish_reason="tool_calls". Belt + suspenders for
        // providers that don't send the finish_reason chunk.
        foreach ($toolAcc as $idx => $call) {
            if (!empty($call['_emitted'])) continue;
            $emit($this->finalizeToolCall($call));
        }

        // Surface transport / HTTP errors.
        if (!$ok || $errno) {
            $emit(['type' => 'error', 'message' => 'OpenRouter network error: ' . $errmsg]);
        } elseif ($status >= 400) {
            $msg = 'OpenRouter HTTP ' . $status;
            $decoded = json_decode($rawAccum, true);
            if (is_array($decoded) && isset($decoded['error']['message'])) {
                $msg .= ' — ' . $decoded['error']['message'];
            } elseif ($rawAccum !== '') {
                $msg .= ' — ' . substr($rawAccum, 0, 400);
            }
            $emit(['type' => 'error', 'message' => $msg]);
        } elseif (empty($eventQueue)) {
            error_log('[OpenRouterStream] 200 but no SSE events parsed. Body head: '
                . substr($rawAccum, 0, 500));
            $emit(['type' => 'error', 'message' =>
                'OpenRouter returned 200 but no parseable events. '
                . 'Body head: ' . substr($rawAccum, 0, 200)]);
        }

        return $eventQueue;
    }

    /**
     * Parse a single SSE event block, emit text/tool/usage events, and update
     * the tool-call accumulator. Called once per `\n\n`-delimited block.
     */
    public function parseSseEvent(string $raw, callable $emit, array &$toolAcc): void
    {
        $lines = preg_split('/\r?\n/', $raw);
        $dataParts = [];
        foreach ($lines as $line) {
            // SSE comments (used by OpenRouter for keepalives: ": OPENROUTER PROCESSING")
            if ($line === '' || $line[0] === ':') continue;
            if (strpos($line, 'data:') === 0) {
                $dataParts[] = ltrim(substr($line, 5));
            }
        }
        if (empty($dataParts)) return;

        $jsonStr = implode("\n", $dataParts);
        if ($jsonStr === '' || $jsonStr === '[DONE]') return;

        $parsed = json_decode($jsonStr, true);
        if (!is_array($parsed)) return;

        // API-level error embedded in the stream (rare but possible).
        if (isset($parsed['error']['message'])) {
            $emit(['type' => 'error', 'message' => (string)$parsed['error']['message']]);
            return;
        }

        // Usage typically appears in the final chunk only (when include_usage is set).
        if (isset($parsed['usage']) && is_array($parsed['usage'])) {
            $emit([
                'type'          => 'usage',
                'input_tokens'  => (int)($parsed['usage']['prompt_tokens']     ?? 0),
                'output_tokens' => (int)($parsed['usage']['completion_tokens'] ?? 0),
            ]);
        }

        $choices = $parsed['choices'] ?? [];
        foreach ($choices as $choice) {
            $delta = $choice['delta'] ?? [];

            // Text delta — yield immediately so the user sees it stream.
            if (isset($delta['content']) && is_string($delta['content']) && $delta['content'] !== '') {
                $emit(['type' => 'text', 'text' => $delta['content']]);
            }

            // Tool-call deltas — accumulate across chunks by index.
            if (isset($delta['tool_calls']) && is_array($delta['tool_calls'])) {
                foreach ($delta['tool_calls'] as $tc) {
                    $idx = isset($tc['index']) ? (int)$tc['index'] : 0;
                    if (!isset($toolAcc[$idx])) {
                        $toolAcc[$idx] = ['id' => '', 'name' => '', 'arguments' => '', '_emitted' => false];
                    }
                    if (!empty($tc['id']))                 $toolAcc[$idx]['id']        = (string)$tc['id'];
                    if (!empty($tc['function']['name']))   $toolAcc[$idx]['name']      = (string)$tc['function']['name'];
                    if (isset($tc['function']['arguments']) && is_string($tc['function']['arguments'])) {
                        $toolAcc[$idx]['arguments'] .= $tc['function']['arguments'];
                    }
                }
            }

            // Tool call completed for this choice — flush the accumulators.
            $finish = (string)($choice['finish_reason'] ?? '');
            if ($finish === 'tool_calls') {
                foreach ($toolAcc as $idx => $call) {
                    if (!empty($call['_emitted'])) continue;
                    $emit($this->finalizeToolCall($call));
                    $toolAcc[$idx]['_emitted'] = true;
                }
            }
        }
    }

    /**
     * Convert an accumulated tool-call entry to a normalised event for ChatAgent.
     * Arguments are JSON-decoded with a safe fallback if the model sent invalid JSON.
     */
    private function finalizeToolCall(array $call): array
    {
        $rawArgs = (string)$call['arguments'];
        $args = [];
        if ($rawArgs !== '') {
            $decoded = json_decode($rawArgs, true);
            if (is_array($decoded)) {
                $args = $decoded;
            } else {
                // Model sent malformed JSON — pass the raw string through so
                // the tool execution can surface a meaningful error.
                $args = ['_raw' => $rawArgs];
            }
        }
        return [
            'type' => 'tool_call',
            'id'   => $call['id'] !== '' ? $call['id'] : 'call_' . uniqid(),
            'name' => $call['name'],
            'args' => $args,
        ];
    }
}
