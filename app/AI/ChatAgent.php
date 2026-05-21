<?php

namespace App\AI;

use App\AI\Tools\SqlQueryTool;
use App\AI\Tools\HeadingsTool;
use App\AI\Tools\HtmlTool;

/**
 * The conversation engine behind Dr. Brief.
 *
 * Given a conversation history and a new user message, drives the multi-turn
 * exchange with OpenRouter (OpenAI chat-completions format) :
 *
 *    user msg ──▶  stream OpenRouter
 *                    │
 *                    ├── text delta ──▶ yield 'text_delta'
 *                    ├── tool_call  ──▶ execute SqlQueryTool / HeadingsTool ──▶ append result
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
    // turn. A broad audit ("report on the crawl", "what should I fix first?")
    // can legitimately need 8–12 queries (status code distribution,
    // indexability, top brokens, depth, duplicates, etc.). 15 leaves head-
    // room without being a free pass — the system prompt also asks the
    // model to plan and stay tight.
    private const MAX_TOOL_ITERATIONS = 15;

    private OpenRouterStream $stream;

    public function __construct()
    {
        $this->stream = new OpenRouterStream();
    }

    /**
     * Run one assistant turn.
     *
     * @param string $apiKey
     * @param string $model
     * @param string $systemPrompt
     * @param array  $history    array of {role: 'user'|'assistant', content: string}
     *                           Browser owns the history ; persistence is ephemeral.
     * @param object $crawl      current crawl record (for tool execution context)
     * @return \Generator yielding SSE event arrays:
     *   ['event' => 'thinking',        'data' => []]
     *   ['event' => 'tool_call_ready', 'data' => ['purpose','query']]
     *   ['event' => 'tool_executing',  'data' => []]
     *   ['event' => 'tool_result',     'data' => tool's for_ui payload]
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
        // Build the initial messages array : system prompt followed by the
        // browser-owned conversation history.
        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach ($history as $msg) {
            $role    = ($msg['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user';
            $content = (string)($msg['content'] ?? '');
            if ($content === '') continue;
            $messages[] = ['role' => $role, 'content' => $content];
        }

        // OpenAI tool declarations — wrap our bare functionDeclaration objects.
        $tools = [
            OpenRouterClient::wrapToolDeclaration(SqlQueryTool::declaration()),
            OpenRouterClient::wrapToolDeclaration(HeadingsTool::declaration()),
            OpenRouterClient::wrapToolDeclaration(HtmlTool::declaration()),
        ];

        $totalInputTokens  = 0;
        $totalOutputTokens = 0;
        $totalToolCalls    = 0;

        for ($iteration = 0; $iteration < self::MAX_TOOL_ITERATIONS; $iteration++) {
            yield ['event' => 'thinking', 'data' => []];

            $assistantTextSoFar = '';
            $pendingToolCalls   = [];

            foreach ($this->stream->stream($apiKey, $model, $messages, $tools) as $ev) {
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

            // We have tool calls — rebuild the assistant turn for the next call,
            // execute each tool, append tool-result messages, then loop.
            $totalToolCalls += count($pendingToolCalls);

            // OpenAI assistant turn with tool_calls : content can be the
            // assistant's pre-tool text (often empty), tool_calls carries
            // every function call the model wants to make in parallel.
            $assistantMsg = [
                'role'       => 'assistant',
                'content'    => $assistantTextSoFar !== '' ? $assistantTextSoFar : null,
                'tool_calls' => array_map(static function ($call) {
                    return [
                        'id'       => $call['id'],
                        'type'     => 'function',
                        'function' => [
                            'name'      => $call['name'],
                            // OpenAI expects arguments as a JSON-encoded string,
                            // not as a nested object.
                            'arguments' => json_encode(
                                $call['args'],
                                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                            ),
                        ],
                    ];
                }, $pendingToolCalls),
            ];
            $messages[] = $assistantMsg;

            // Execute each tool call, stream UI events, append `tool` role
            // messages with the result content.
            foreach ($pendingToolCalls as $call) {
                $crawlIdArg   = (int)($crawl->id ?? 0);
                $crawlPathArg = (string)($crawl->path ?? '');
                $exec = null;

                if ($call['name'] === SqlQueryTool::NAME) {
                    $purpose      = (string)($call['args']['purpose'] ?? '');
                    $queryPreview = (string)($call['args']['query']   ?? '');
                    yield ['event' => 'tool_call_ready', 'data' => [
                        'purpose' => $purpose,
                        'query'   => $queryPreview,
                    ]];
                    yield ['event' => 'tool_executing', 'data' => []];
                    $exec = SqlQueryTool::execute($call['args'], $crawlIdArg, $crawlPathArg);

                } elseif ($call['name'] === HeadingsTool::NAME) {
                    $urlsCount = isset($call['args']['urls']) && is_array($call['args']['urls'])
                        ? count($call['args']['urls']) : 0;
                    yield ['event' => 'tool_call_ready', 'data' => [
                        'purpose' => 'Extract h1..h6 from ' . $urlsCount . ' page(s)',
                        'query'   => HeadingsTool::NAME . ': ' . $urlsCount . ' url(s)',
                    ]];
                    yield ['event' => 'tool_executing', 'data' => []];
                    $exec = HeadingsTool::execute($call['args'], $crawlIdArg, $crawlPathArg);

                } elseif ($call['name'] === HtmlTool::NAME) {
                    $urlsCount = isset($call['args']['urls']) && is_array($call['args']['urls'])
                        ? count($call['args']['urls']) : 0;
                    $focus     = (string)($call['args']['focus'] ?? 'Inspect page HTML');
                    yield ['event' => 'tool_call_ready', 'data' => [
                        'purpose' => $focus,
                        'query'   => HtmlTool::NAME . ': ' . $urlsCount . ' url(s)',
                    ]];
                    yield ['event' => 'tool_executing', 'data' => []];
                    $exec = HtmlTool::execute($call['args'], $crawlIdArg, $crawlPathArg);

                } else {
                    // Unknown tool — tell the model, no UI event.
                    $messages[] = [
                        'role'         => 'tool',
                        'tool_call_id' => $call['id'],
                        'content'      => json_encode(['error' => 'Unknown tool: ' . $call['name']]),
                    ];
                    continue;
                }

                // Give the model a stable, SHORT token it can drop into an
                // inline markdown link, instead of reproducing the long base64
                // SQL-Explorer deeplink (which it would mangle). The widget
                // swaps the token for the real URL using this same mapping, so
                // the agent can place a contextual "open in SQL Explorer" link
                // exactly where it wants in its prose. Only SQL results carry a
                // deeplink.
                if ($exec && !empty($exec['for_ui']['deeplink'])) {
                    $linkToken = 'sqlx:' . $call['id'];
                    $exec['for_ui']['link_token'] = $linkToken;
                    if (isset($exec['for_model']) && is_array($exec['for_model'])) {
                        $exec['for_model']['full_result_link'] = $linkToken;
                    }
                }

                yield ['event' => 'tool_result', 'data' => $exec['for_ui']];
                $messages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $call['id'],
                    'content'      => json_encode(
                        $exec['for_model'],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                ];
            }
        }

        // If we exit the loop without returning, we hit the iteration cap.
        yield ['event' => 'error', 'data' => [
            'message' => 'Stopped after ' . self::MAX_TOOL_ITERATIONS . ' tool iterations to prevent a loop.',
        ]];
    }
}
