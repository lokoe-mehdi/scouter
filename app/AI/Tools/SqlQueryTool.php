<?php

namespace App\AI\Tools;

use App\AI\SqlExecutor;

/**
 * The `run_sql` tool exposed to Gemini.
 *
 * - declaration() returns the Function Declaration JSON that goes in the
 *   Gemini API request's `tools` field.
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

    /** Function Declaration for Gemini. */
    public static function declaration(): array
    {
        return [
            'name' => self::NAME,
            'description' =>
                "Execute a read-only PostgreSQL SELECT against the current crawl's data " .
                "and return the rows. Use this whenever you need actual numbers, lists, " .
                "or comparisons from the crawl. Always set LIMIT 10 on lists. No LIMIT " .
                "needed on aggregates (COUNT, SUM, AVG).",
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
     *     for_model: array,   // compact payload to send back to Gemini
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
                    'success' => false,
                    'purpose' => $purpose,
                    'query'   => $query,
                    'error'   => $err,
                ],
            ];
        }

        // Compact for the model — just enough to reason on, not the full rows
        // dump if it's a 10-row list (saves tokens). We keep up to 10 rows.
        $rows       = $result['rows'] ?? [];
        $columns    = $result['columns'] ?? [];
        $totalRows  = $result['total_rows'] ?? count($rows);
        $truncated  = (bool)($result['truncated'] ?? false);

        // Deeplink to SQL Explorer with the full query (without preview LIMIT)
        // so the user can click through, see all rows, sort, export.
        $deeplink = self::buildDeeplink($crawlId, $crawlPath, $result['deeplink_sql'] ?? $query);

        // For the model: rename `total_rows` to `rows_in_preview` to remove
        // the ambiguity — and inject a `note` when truncated so the LLM
        // can't easily ignore that there are likely more rows than visible.
        $forModel = [
            'rows'             => $rows,
            'columns'          => $columns,
            'rows_in_preview'  => count($rows),
            'truncated'        => $truncated,
        ];
        if ($truncated) {
            $forModel['note'] = 'This result was capped at ' . count($rows)
                . ' rows. The TRUE total of matching rows is unknown — run a '
                . 'SELECT COUNT(*) query if you need the exact number.';
        }

        return [
            'for_model' => $forModel,
            'for_ui' => [
                'success'   => true,
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
