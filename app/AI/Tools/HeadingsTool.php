<?php

namespace App\AI\Tools;

use App\Database\PostgresDatabase;
use PDO;

/**
 * `get_page_headings` tool — extracts the full h1..h6 tree of one or
 * more pages from the stored HTML.
 *
 * Why a dedicated tool : the `html` table stores HTML as base64-encoded,
 * gzip-compressed bytes — there's no way to decompress that in pure
 * PostgreSQL, so a SQL-only recipe would never work. This tool does the
 * decode/decompress/DOM walk in PHP, same code path as the URL detail
 * modal's "Headings" tab, so the result is consistent with what the user
 * sees in the UI.
 *
 * @package    Scouter
 * @subpackage AI\Tools
 */
class HeadingsTool
{
    public const NAME = 'get_page_headings';

    /** Caps : never fetch more than this regardless of what the AI asks. */
    private const MAX_URLS = 20;

    /** Bare function declaration — ChatAgent wraps it in OpenAI's {type:'function',function:...} envelope. */
    public static function declaration(): array
    {
        return [
            'name' => self::NAME,
            'description' =>
                "Get the full ordered list of h1, h2, h3, h4, h5, h6 of one or more pages. " .
                "Use this whenever the user asks to SEE the headings (not just count h1s or " .
                "check h1_multiple/headings_missing flags). Pass an array of exact URLs " .
                "(up to 20). Returns one entry per page with its DOM-ordered headings. " .
                "If the crawl didn't store the HTML, the entry returns an empty list.",
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'urls' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' =>
                            "Array of FULL page URLs (up to 20). Get them first with a " .
                            "run_sql query like `SELECT url FROM pages WHERE ... LIMIT 20`.",
                    ],
                ],
                'required' => ['urls'],
            ],
        ];
    }

    /**
     * Execute a call from the model.
     *
     * @return array{
     *     for_model: array,
     *     for_ui:    array
     * }
     */
    public static function execute(array $args, int $crawlId, string $crawlPath = ''): array
    {
        $urls = $args['urls'] ?? [];
        if (!is_array($urls)) $urls = [];
        // Normalize : trim, drop empties, dedupe, cap.
        $clean = [];
        foreach ($urls as $u) {
            if (!is_string($u)) continue;
            $u = trim($u);
            if ($u === '') continue;
            if (!isset($clean[$u])) $clean[$u] = true;
            if (count($clean) >= self::MAX_URLS) break;
        }
        $urls = array_keys($clean);

        if (empty($urls)) {
            $err = 'No URLs provided. Pass an `urls` array of full URL strings.';
            return [
                'for_model' => ['error' => $err],
                'for_ui'    => ['success' => false, 'tool_kind' => 'headings', 'purpose' => 'Extract headings', 'error' => $err],
            ];
        }

        try {
            $results = self::fetchHeadings($urls, $crawlId);
        } catch (\Throwable $e) {
            $err = 'Headings extraction failed: ' . $e->getMessage();
            error_log('[HeadingsTool] ' . $err);
            return [
                'for_model' => ['error' => $err],
                'for_ui'    => ['success' => false, 'tool_kind' => 'headings', 'purpose' => 'Extract headings', 'error' => $err],
            ];
        }

        // Count totals for the UI summary line.
        $totalHeadings = 0;
        foreach ($results as $r) {
            $totalHeadings += count($r['headings']);
        }

        // for_ui : flatten into the same row/column shape SqlQueryTool returns
        // so the existing chat result-table renderer can show it without
        // special-casing.
        $rows = [];
        foreach ($results as $r) {
            foreach ($r['headings'] as $h) {
                $rows[] = [
                    'url'   => $r['url'],
                    'level' => 'h' . $h['level'],
                    'text'  => $h['text'],
                ];
            }
            if (empty($r['headings'])) {
                $rows[] = [
                    'url'   => $r['url'],
                    'level' => '—',
                    'text'  => '(no HTML stored / no headings found)',
                ];
            }
        }
        // Keep the chat preview compact — same 10-row cap as run_sql.
        $previewRows = array_slice($rows, 0, 10);

        return [
            'for_model' => [
                'pages'          => $results,
                'pages_returned' => count($results),
                'total_headings' => $totalHeadings,
            ],
            'for_ui' => [
                'success'    => true,
                'tool_kind'  => 'headings',
                'purpose'    => 'Extract h1..h6 from ' . count($results) . ' page(s)',
                'rows'       => $previewRows,
                'columns'    => ['url', 'level', 'text'],
                'total_rows' => count($rows),
                'truncated'  => count($rows) > 10,
                'deeplink'   => '',
            ],
        ];
    }

    /**
     * Fetch + decode + extract headings for the given URLs in the given crawl.
     *
     * @return array<int, array{url:string, headings: array<int, array{level:int,text:string}>}>
     */
    private static function fetchHeadings(array $urls, int $crawlId): array
    {
        // Migrated crawls read from ClickHouse (PG purged); CH stores raw HTML.
        $useCh = \App\Database\CrawlStore::usesClickHouse($crawlId);
        $db = $useCh ? new \App\Database\ChPdo($crawlId) : PostgresDatabase::getInstance()->getConnection();

        // 1. Resolve URLs → page IDs (one round-trip).
        $placeholders = [];
        $params = [':cid' => $crawlId];
        foreach ($urls as $i => $u) {
            $key = ':u' . $i;
            $placeholders[] = $key;
            $params[$key] = $u;
        }
        $sqlPages = "SELECT id, url FROM pages WHERE crawl_id = :cid AND url IN (" . implode(',', $placeholders) . ")";
        $stmt = $db->prepare($sqlPages);
        $stmt->execute($params);
        $pagesRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $byUrl = [];
        $pageIds = [];
        foreach ($pagesRows as $p) {
            $byUrl[$p['url']] = $p['id'];
            $pageIds[] = $p['id'];
        }

        // 2. Fetch HTML for those page IDs (also one round-trip).
        $htmlByPageId = [];
        if (!empty($pageIds)) {
            $hPlaceholders = [];
            $hParams = [':cid' => $crawlId];
            foreach ($pageIds as $i => $pid) {
                $key = ':p' . $i;
                $hPlaceholders[] = $key;
                $hParams[$key] = $pid;
            }
            $sqlHtml = "SELECT id, html FROM html WHERE crawl_id = :cid AND id IN (" . implode(',', $hPlaceholders) . ")";
            $stmt = $db->prepare($sqlHtml);
            $stmt->execute($hParams);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $htmlByPageId[$r['id']] = $r['html'];
            }
        }

        // 3. For each requested URL (in input order), decode HTML and extract
        //    headings via DOMDocument — same logic as QueryController::htmlSource.
        $out = [];
        foreach ($urls as $u) {
            $pageId = $byUrl[$u] ?? null;
            if (!$pageId || !isset($htmlByPageId[$pageId])) {
                $out[] = ['url' => $u, 'headings' => []];
                continue;
            }
            $htmlContent = $useCh ? (string)$htmlByPageId[$pageId] : self::decodeStoredHtml($htmlByPageId[$pageId]);
            $headings = ($htmlContent !== null && $htmlContent !== '') ? self::extractHeadings($htmlContent) : [];
            $out[] = ['url' => $u, 'headings' => $headings];
        }
        return $out;
    }

    private static function decodeStoredHtml(?string $stored): ?string
    {
        if (!$stored) return null;
        $decoded = base64_decode($stored, true);
        if ($decoded === false) return null;
        $decompressed = @gzinflate($decoded);
        return $decompressed !== false ? $decompressed : $decoded;
    }

    /**
     * @return array<int, array{level:int,text:string}>
     */
    private static function extractHeadings(string $html): array
    {
        $headings = [];
        $dom = new \DOMDocument();
        // Suppress libxml warnings on non-strict HTML.
        $previous = libxml_use_internal_errors(true);
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('//h1 | //h2 | //h3 | //h4 | //h5 | //h6');
        if ($nodes === false) return [];
        foreach ($nodes as $node) {
            $headings[] = [
                'level' => (int)substr($node->nodeName, 1),
                'text'  => trim($node->textContent),
            ];
        }
        return $headings;
    }
}
