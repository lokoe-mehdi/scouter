<?php

namespace App\AI\Tools;

use App\AI\SqlExecutor;

/**
 * The `run_sql` tool exposed to the model via OpenRouter.
 *
 * - declaration() returns the bare function declaration — ChatAgent wraps it
 *   in the OpenAI {type:'function',function:{...}} envelope before sending.
 * - execute() runs the call via SqlExecutor and shapes the response for
 *   both the model (compact JSON it can reason on) and the browser (full
 *   preview rows + deeplink for the SSE UI).
 *
 * @package    Scouter
 * @subpackage AI\Tools
 */
class SqlQueryTool
{
    public const NAME = 'run_sql';

    /** Max rows handed to the MODEL (token saving). The UI preview + the
     *  SQL-Explorer deeplink still expose the full result set to the user. */
    private const MODEL_PREVIEW_ROWS = 25;

    /** Bare function declaration — ChatAgent wraps it in OpenAI's {type:'function',function:...} envelope. */
    public static function declaration(): array
    {
        return [
            'name' => self::NAME,
            'description' =>
                "Execute a read-only PostgreSQL SELECT against the current crawl's data " .
                "and return the rows. Use this whenever you need actual numbers, lists, " .
                "or comparisons from the crawl. For a flat list, LIMIT 100. No LIMIT " .
                "needed on aggregates (COUNT, SUM, AVG). The server caps every result at " .
                "100 rows for the chat preview — if the cap kicks in (truncated=true), " .
                "treat what you see as a SAMPLE, never as the full set, and tell the user " .
                "so. They have a button below the table to open the full query in the " .
                "SQL Explorer. " .
                "CRITICAL — when sampling examples per category/bucket, NEVER use a flat " .
                "LIMIT N : that gives N rows from 1-2 buckets only. Use a CTE with " .
                "`ROW_NUMBER() OVER (PARTITION BY <bucket> ORDER BY ...)` then filter " .
                "`rank <= 3` to get 2-3 examples PER bucket (3 × N_buckets rows total). " .
                "A flat LIMIT on a bucketed problem = wrong conclusion.",
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' =>
                            "A single PostgreSQL SELECT statement. Reference tables by " .
                            "their bare name (pages, links, crawl_categories, ...) — the " .
                            "server expands them to the right partition.",
                    ],
                    'purpose' => [
                        'type' => 'string',
                        'description' =>
                            "One short sentence (≤ 12 words) explaining what this query is " .
                            "meant to find out. Shown to the user above the SQL while it runs.",
                    ],
                ],
                'required' => ['query', 'purpose'],
            ],
        ];
    }

    /**
     * Execute a call from the model.
     *
     * @return array{
     *     for_model: array,   // compact payload to send back to the model
     *     for_ui:    array    // full payload to stream to the browser
     * }
     */
    public static function execute(array $args, int $crawlId, string $crawlPath = ''): array
    {
        $query   = isset($args['query'])   ? (string)$args['query']   : '';
        $purpose = isset($args['purpose']) ? (string)$args['purpose'] : '';

        $executor = new SqlExecutor();
        $result = $executor->execute($query, $crawlId);

        if (!$result['ok']) {
            $err = $result['error'] ?? 'Unknown SQL error';
            return [
                'for_model' => [
                    'error'         => $err,
                    'query_attempted' => $query,
                    'hint'          => 'Look at the error message and try a corrected query.',
                ],
                'for_ui' => [
                    'success'   => false,
                    'tool_kind' => 'sql',
                    'purpose'   => $purpose,
                    'query'     => $query,
                    'error'     => $err,
                ],
            ];
        }

        $rows       = $result['rows'] ?? [];
        $columns    = $result['columns'] ?? [];
        $totalRows  = $result['total_rows'] ?? count($rows);
        $truncated  = (bool)($result['truncated'] ?? false);

        // Rows shown to the MODEL are capped well below the UI preview to save
        // tokens: the model rarely needs more than a couple dozen rows to
        // reason or write its answer, and the user still sees the full preview
        // in the chat table + everything via the SQL-Explorer link. The big
        // win is on list queries (was up to 100 rows re-sent on every tool
        // iteration of the turn).
        $modelRows = array_slice($rows, 0, self::MODEL_PREVIEW_ROWS);
        $moreExist = $truncated || count($rows) > count($modelRows);

        // Deeplink to SQL Explorer with the full query (without preview LIMIT)
        // so the user can click through, see all rows, sort, export.
        $deeplink = self::buildDeeplink($crawlId, $crawlPath, $result['deeplink_sql'] ?? $query);

        // For the model: rename `total_rows` to `rows_in_preview` to remove
        // the ambiguity — and inject a `note` when more rows exist so the LLM
        // can't easily ignore that there are likely more rows than visible.
        $forModel = [
            'rows'             => $modelRows,
            'columns'          => $columns,
            'rows_in_preview'  => count($modelRows),
            'truncated'        => $moreExist,
        ];
        // Shared instruction on how to surface the SQL-Explorer link inline.
        // `full_result_link` is added to THIS result by ChatAgent (a short
        // token); the model must drop it verbatim as the URL of a markdown
        // link with its own descriptive anchor text — the UI swaps the token
        // for the real, sortable, CSV-exportable SQL-Explorer URL. The model
        // must NEVER write a real/guessed URL.
        $linkHowTo = ' To let the user open the FULL, sortable, CSV-exportable '
            . 'list, embed an inline markdown link in your reply whose URL is the '
            . 'VALUE of the `full_result_link` field below (it looks like '
            . '"sqlx:call_xxx"), used verbatim, with anchor text describing what '
            . 'it points to — e.g. if full_result_link is "sqlx:call_42", write '
            . '`[voir la liste complète dans SQL Explorer](sqlx:call_42)`. '
            . 'Never write or guess a real URL.';

        if ($moreExist) {
            $forModel['note'] = 'IMPORTANT: you are seeing only a SAMPLE of '
                . count($modelRows) . ' row(s) — more matching rows exist and are '
                . 'unknown to you. Treat these as examples, never as exhaustive. '
                . 'When you reference them, say things like "here are '
                . count($modelRows) . ' examples (more exist)" or pair them with a '
                . 'SELECT COUNT(*) so the user knows the true total.'
                . $linkHowTo;
        } elseif (count($modelRows) > 1) {
            // Complete short list: still offer the exportable view inline.
            $forModel['note'] = 'This list is complete (' . count($modelRows) . ' rows).'
                . $linkHowTo;
        }

        return [
            'for_model' => $forModel,
            'for_ui' => [
                'success'   => true,
                'tool_kind' => 'sql',
                'purpose'   => $purpose,
                'query'     => $query,
                'rows'      => $rows,
                'columns'   => $columns,
                'total_rows'=> $totalRows,
                'truncated' => $truncated,
                'deeplink'  => $deeplink,
            ],
        ];
    }

    /**
     * Build the SQL Explorer deeplink. We pass the SQL base64-encoded in `q=`
     * so it survives URL encoding of complex queries (regex, multi-line, etc.).
     * The SQL Explorer JS reads `q=` on load and prefills the editor.
     */
    private static function buildDeeplink(int $crawlId, string $crawlPath, string $sql): string
    {
        $sql = trim($sql);
        if ($sql === '') return '';
        $encoded = rtrim(strtr(base64_encode($sql), '+/', '-_'), '=');
        // run=1 makes the SQL Explorer auto-execute the query on arrival —
        // saves the user a click since the AI deeplink already vetted the SQL.
        $params = [
            'crawl' => $crawlId,
            'page'  => 'sql-explorer',
            'q'     => $encoded,
            'run'   => 1,
        ];
        return 'dashboard.php?' . http_build_query($params);
    }
}
