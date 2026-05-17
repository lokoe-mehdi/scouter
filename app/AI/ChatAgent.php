<?php

namespace App\AI;

use App\AI\Tools\SqlQueryTool;

/**
 * The conversation engine behind Dr. Brief.
 *
 * Given a conversation history and a new user message, drives the multi-turn
 * exchange with Gemini :
 *
 *    user msg ──▶  stream Gemini
 *                    │
 *                    ├── text delta ──▶ yield 'text_delta'
 *                    ├── tool_call  ──▶ execute SqlQueryTool ──▶ append result
 *                    │                     │
 *                    │                     └─── loop : restream with the result
 *                    │
 *                    └── stream end  ──▶ yield 'done'
 *
 * The whole thing is a PHP generator yielding SSE-shaped events that the
 * controller writes straight to the wire. We never buffer the assistant's
 * final reply — every chunk goes out immediately.
 *
 * Hard cap on tool-call iterations to prevent infinite loops.
 *
 * @package    Scouter
 * @subpackage AI
 */
class ChatAgent
{
    // Max rounds of (model → tool_call → execute → model continues) per user
    // turn. A "report on the crawl" type of question can legitimately need
    // 8–10 queries (status code distribution, indexability, top brokens,
    // depth, duplicates, etc.) so we keep this generous.
    private const MAX_TOOL_ITERATIONS = 15;

    private GeminiStream $stream;

    public function __construct()
    {
        $this->stream = new GeminiStream();
    }

    /**
     * Run one assistant turn.
     *
     * @param string $apiKey
     * @param string $model
     * @param string $systemPrompt
     * @param array  $history    array of {role, content?, tool_calls?, tool_results?}
     *                           where role ∈ {'user','assistant','tool'}
     * @param object $crawl      current crawl record (for tool execution context)
     * @return \Generator yielding SSE event arrays:
     *   ['event' => 'thinking',        'data' => []]
     *   ['event' => 'tool_call_ready', 'data' => ['purpose','query']]
     *   ['event' => 'tool_executing',  'data' => []]
     *   ['event' => 'tool_result',     'data' => SqlQueryTool::for_ui]
     *   ['event' => 'text_delta',      'data' => ['delta' => '...']]
     *   ['event' => 'done',            'data' => ['input_tokens','output_tokens','tool_calls']]
     *   ['event' => 'error',           'data' => ['message']]
     */
    public function run(
        string $apiKey,
        string $model,
        string $systemPrompt,
        array $history,
        object $crawl
    ): \Generator {
        $contents = $this->historyToContents($history);
        $tools = [['functionDeclarations' => [SqlQueryTool::declaration()]]];

        $totalInputTokens = 0;
        $totalOutputTokens = 0;
        $totalToolCalls = 0;

        for ($iteration = 0; $iteration < self::MAX_TOOL_ITERATIONS; $iteration++) {
            yield ['event' => 'thinking', 'data' => []];

            // Will collect things produced during this Gemini call so we can
            // either loop (tool call) or finish (no tool call). For Gemini 3+
            // compatibility, we track text chunks WITH their signatures
            // separately rather than concatenating them — preserving
            // signatures part-by-part is required for tools to keep working
            // on the next iteration.
            $assistantTextParts = [];   // [['text'=>'...', 'signature'=>'...'|null], ...]
            $assistantTextSoFar = '';   // concat for the "no tool" finish path
            $pendingToolCalls = [];

            foreach ($this->stream->stream($apiKey, $model, $systemPrompt, $contents, $tools) as $ev) {
                if ($ev['type'] === 'error') {
                    yield ['event' => 'error', 'data' => ['message' => $ev['message']]];
                    return;
                }
                if ($ev['type'] === 'usage') {
                    $totalInputTokens  += $ev['input_tokens'];
                    $totalOutputTokens += $ev['output_tokens'];
                    continue;
                }
                if ($ev['type'] === 'text') {
                    $assistantTextSoFar .= $ev['text'];
                    $assistantTextParts[] = [
                        'text'      => $ev['text'],
                        'signature' => $ev['signature'] ?? null,
                    ];
                    yield ['event' => 'text_delta', 'data' => ['delta' => $ev['text']]];
                    continue;
                }
                if ($ev['type'] === 'tool_call') {
                    $pendingToolCalls[] = $ev;
                    continue;
                }
            }

            // No tool calls this iteration → assistant has answered, we're done.
            if (empty($pendingToolCalls)) {
                yield ['event' => 'done', 'data' => [
                    'input_tokens'  => $totalInputTokens,
                    'output_tokens' => $totalOutputTokens,
                    'tool_calls'    => $totalToolCalls,
                ]];
                return;
            }

            // We have tool calls — execute each, append to conversation, loop.
            $totalToolCalls += count($pendingToolCalls);

            // Rebuild the assistant turn AS-IS for Gemini's next call.
            //   - text parts with their per-chunk thoughtSignature preserved
            //     (or merged if signatures were absent on Gemini 2.x)
            //   - functionCall parts with their thoughtSignature preserved
            // On Gemini 3+ the signatures are mandatory; on 2.x they're
            // simply absent (null) and we pass nothing extra.
            $assistantParts = [];
            $bufferedText = '';
            foreach ($assistantTextParts as $tp) {
                if ($tp['signature'] !== null) {
                    // Flush any signature-less text buffered before this
                    // signed part — keep ordering intact.
                    if ($bufferedText !== '') {
                        $assistantParts[] = ['text' => $bufferedText];
                        $bufferedText = '';
                    }
                    $assistantParts[] = [
                        'text'             => $tp['text'],
                        'thoughtSignature' => $tp['signature'],
                    ];
                } else {
                    $bufferedText .= $tp['text'];
                }
            }
            if ($bufferedText !== '') {
                $assistantParts[] = ['text' => $bufferedText];
            }

            foreach ($pendingToolCalls as $call) {
                $part = ['functionCall' => [
                    'name' => $call['name'],
                    'args' => $call['args'],
                ]];
                if (!empty($call['signature'])) {
                    $part['thoughtSignature'] = $call['signature'];
                }
                $assistantParts[] = $part;
            }
            $contents[] = ['role' => 'model', 'parts' => $assistantParts];

            // Execute each tool call, stream UI events, and add the
            // functionResponse part to the next user message for Gemini.
            $functionResponseParts = [];
            foreach ($pendingToolCalls as $call) {
                if ($call['name'] !== SqlQueryTool::NAME) {
                    // Unknown tool — tell Gemini.
                    $functionResponseParts[] = ['functionResponse' => [
                        'name'     => $call['name'],
                        'response' => ['error' => 'Unknown tool: ' . $call['name']],
                    ]];
                    continue;
                }

                yield ['event' => 'tool_call_ready', 'data' => [
                    'purpose' => (string)($call['args']['purpose'] ?? ''),
                    'query'   => (string)($call['args']['query']   ?? ''),
                ]];

                yield ['event' => 'tool_executing', 'data' => []];

                $exec = SqlQueryTool::execute(
                    $call['args'],
                    (int)($crawl->id ?? 0),
                    (string)($crawl->path ?? '')
                );

                yield ['event' => 'tool_result', 'data' => $exec['for_ui']];

                $functionResponseParts[] = ['functionResponse' => [
                    'name'     => SqlQueryTool::NAME,
                    'response' => $exec['for_model'],
                ]];
            }

            // Gemini protocol: tool responses come back as a "user" role turn
            // whose parts are functionResponse objects.
            $contents[] = ['role' => 'user', 'parts' => $functionResponseParts];
        }

        // If we exit the loop without returning, we hit the iteration cap.
        yield ['event' => 'error', 'data' => [
            'message' => 'Stopped after ' . self::MAX_TOOL_ITERATIONS . ' tool iterations to prevent a loop.',
        ]];
    }

    /**
     * Convert our simple history format ({role, content}) into Gemini's
     * `contents` array. Tool turns are skipped here — they would only be
     * present if we kept persistence (we don't, for now).
     */
    private function historyToContents(array $history): array
    {
        $out = [];
        foreach ($history as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = (string)($msg['content'] ?? '');
            if ($content === '') continue;

            // Gemini uses 'user' and 'model'.
            $geminiRole = $role === 'assistant' ? 'model' : 'user';
            $out[] = [
                'role'  => $geminiRole,
                'parts' => [['text' => $content]],
            ];
        }
        return $out;
    }
}
