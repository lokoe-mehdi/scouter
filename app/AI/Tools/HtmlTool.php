<?php

namespace App\AI\Tools;

use App\Database\PostgresDatabase;
use App\Storage\HtmlStore;
use PDO;

/**
 * `get_page_html` tool — fetch the cleaned HTML markup of 1-2 pages so the
 * model can inspect structural elements that SQL on `pages`/`links` can't
 * reveal : pagination components, navigation, breadcrumbs, anchor patterns,
 * inline schema.org JSON-LD, hidden links, etc.
 *
 * Why a hard 2-URL cap : a single page's HTML can be 100-500 KB ; sending
 * many of them would blow the context window and torch the user's credit
 * for no analytical gain. This tool is for spot-checking 1-2 representative
 * pages, not for bulk inspection — that's what SQL is for.
 *
 * Why stripping : `<script>`, `<style>`, `<svg>`, `<noscript>`, and HTML
 * comments together account for the bulk of any modern page's byte count
 * and almost never carry information useful for SEO/structural analysis.
 * We keep the body markup, `<head>` (titles, metas, rel="next/prev", schema
 * scripts… wait, scripts are stripped — see note), anchors, lists, etc.
 *
 * Note on JSON-LD : it lives inside `<script type="application/ld+json">`.
 * We KEEP those scripts specifically, since they carry schema.org data the
 * model often needs to evaluate.
 *
 * @package    Scouter
 * @subpackage AI\Tools
 */
class HtmlTool
{
    public const NAME = 'get_page_html';

    /** Hard cap on URLs per call — this is the expensive tool. */
    private const MAX_URLS = 2;

    /** Per-page cap on cleaned HTML returned to the model (in characters). */
    private const MAX_CHARS_PER_PAGE = 40000;

    /** Function declaration for Gemini-style or OpenAI-style tool envelopes. */
    public static function declaration(): array
    {
        return [
            'name' => self::NAME,
            'description' =>
                "Fetch the actual HTML markup of 1 or 2 specific pages so you can " .
                "inspect concrete structural elements that SQL cannot reveal : the " .
                "pagination component (to check if decade shortcuts exist), the " .
                "navigation, breadcrumbs, anchor patterns, inline JSON-LD schema, " .
                "hidden links, rel=\"next/prev\" tags, etc. HARD CAP : 2 URLs per call " .
                "— pick representative examples, do NOT use this for bulk inspection. " .
                "The HTML is auto-cleaned (script/style/svg/comments stripped, except " .
                "JSON-LD scripts which are kept) and capped at 40,000 characters per " .
                "page ; if truncated, you'll see a `[…truncated]` marker. Use this " .
                "only AFTER a SQL query revealed a structural question worth " .
                "inspecting in markup form.",
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'urls' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' =>
                            "Array of 1 or 2 FULL page URLs (must match `pages.url` " .
                            "exactly). Get them first with a `run_sql` query like " .
                            "`SELECT url FROM pages WHERE … LIMIT 2`.",
                    ],
                    'focus' => [
                        'type' => 'string',
                        'description' =>
                            "One short sentence (≤ 15 words) telling the user what you " .
                            "are looking for in this HTML — shown in the UI above the " .
                            "tool block. Example : \"Check if pagination has decade " .
                            "shortcuts or only sequential next links.\"",
                    ],
                ],
                'required' => ['urls'],
            ],
        ];
    }

    /**
     * @return array{for_model: array, for_ui: array}
     */
    public static function execute(array $args, int $crawlId, string $crawlPath = ''): array
    {
        $focus = isset($args['focus']) ? trim((string)$args['focus']) : '';
        if ($focus === '') {
            $focus = 'Inspect page HTML';
        }

        // Normalize URLs : trim, drop empties, dedupe, hard-cap at 2.
        $urls = $args['urls'] ?? [];
        if (!is_array($urls)) $urls = [];
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
            $err = 'No URLs provided. Pass an `urls` array of 1-2 full URL strings.';
            return [
                'for_model' => ['error' => $err],
                'for_ui'    => ['success' => false, 'tool_kind' => 'html', 'purpose' => $focus, 'error' => $err],
            ];
        }

        try {
            $pages = self::fetchPages($urls, $crawlId);
        } catch (\Throwable $e) {
            $err = 'HTML extraction failed: ' . $e->getMessage();
            error_log('[HtmlTool] ' . $err);
            return [
                'for_model' => ['error' => $err],
                'for_ui'    => ['success' => false, 'tool_kind' => 'html', 'purpose' => $focus, 'error' => $err],
            ];
        }

        // Build the model payload : one entry per requested URL, even if the
        // page has no stored HTML (so the model knows which one failed).
        $forModelPages = [];
        $totalCharsAfter = 0;
        foreach ($pages as $p) {
            $forModelPages[] = [
                'url'                  => $p['url'],
                'html'                 => $p['html'],
                'html_chars'           => $p['chars_after'],
                'original_chars'       => $p['chars_before'],
                'truncated'            => $p['truncated'],
                'note'                 => $p['note'],
            ];
            $totalCharsAfter += $p['chars_after'];
        }

        // UI payload : compact — just the metadata, no HTML body. The HTML is
        // for the MODEL to read ; showing it in the chat would be noise (and
        // a lot of it). The user can re-open the URL detail modal in Scouter
        // to see the same HTML rendered nicely.
        $forUiPages = [];
        foreach ($pages as $p) {
            $forUiPages[] = [
                'url'              => $p['url'],
                'chars_returned'   => $p['chars_after'],
                'original_chars'   => $p['chars_before'],
                'truncated'        => $p['truncated'],
                'has_html'         => $p['has_html'],
            ];
        }

        return [
            'for_model' => [
                'pages'          => $forModelPages,
                'pages_returned' => count($pages),
                'total_chars'    => $totalCharsAfter,
            ],
            'for_ui' => [
                'success'    => true,
                'tool_kind'  => 'html',
                'purpose'    => $focus,
                'pages'      => $forUiPages,
                'pages_count'=> count($pages),
                'total_chars'=> $totalCharsAfter,
            ],
        ];
    }

    /**
     * Fetch + decode + clean the HTML for the given URLs in the given crawl.
     *
     * @return array<int, array{
     *   url:string,
     *   html:string,
     *   chars_before:int,
     *   chars_after:int,
     *   truncated:bool,
     *   has_html:bool,
     *   note:string
     * }>
     */
    private static function fetchPages(array $urls, int $crawlId): array
    {
        // Migrated crawls read from ClickHouse (PG purged); CH stores raw HTML.
        $useCh = \App\Database\CrawlStore::usesClickHouse($crawlId);
        $db = $useCh ? new \App\Database\ChPdo($crawlId) : PostgresDatabase::getInstance()->getConnection();

        // 1. URL → page id.
        $placeholders = [];
        $params = [':cid' => $crawlId];
        foreach ($urls as $i => $u) {
            $key = ':u' . $i;
            $placeholders[] = $key;
            $params[$key] = $u;
        }
        $stmt = $db->prepare(
            "SELECT id, url FROM pages WHERE crawl_id = :cid AND url IN (" . implode(',', $placeholders) . ")"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // pages.id is CHAR(8) — a fixed-width string identifier, NOT an int.
        // Casting to (int) would turn ids like 'a1b2c3d4' into 0 and the
        // subsequent IN clause would silently match nothing. Keep them as
        // strings throughout — same as HeadingsTool / QueryController.
        $idByUrl = [];
        $pageIds = [];
        foreach ($rows as $r) {
            $idByUrl[$r['url']] = $r['id'];
            $pageIds[] = $r['id'];
        }

        // 2. Fetch HTML for those ids from the blob store (S3/local) — returns
        //    id => already-decompressed raw HTML; old crawls fall back to the DB.
        $htmlById = !empty($pageIds)
            ? HtmlStore::fetchMany($crawlId, $pageIds, $useCh, $db)
            : [];

        // 3. For each requested URL (in input order), decode + clean + cap.
        $out = [];
        foreach ($urls as $u) {
            $pid = $idByUrl[$u] ?? null;
            $stored = $pid !== null ? ($htmlById[$pid] ?? null) : null;
            if (!$stored) {
                $out[] = [
                    'url'          => $u,
                    'html'         => '',
                    'chars_before' => 0,
                    'chars_after'  => 0,
                    'truncated'    => false,
                    'has_html'     => false,
                    'note'         => 'No HTML stored for this URL (either the URL was not crawled, or the crawl did not store HTML).',
                ];
                continue;
            }

            $raw = (string)$stored; // HtmlStore already decompressed it
            if ($raw === null || $raw === '') {
                $out[] = [
                    'url'          => $u,
                    'html'         => '',
                    'chars_before' => 0,
                    'chars_after'  => 0,
                    'truncated'    => false,
                    'has_html'     => false,
                    'note'         => 'Stored HTML could not be decoded (corrupted or unexpected encoding).',
                ];
                continue;
            }

            $before = strlen($raw);
            $cleaned = self::cleanHtml($raw);
            $truncated = false;
            if (strlen($cleaned) > self::MAX_CHARS_PER_PAGE) {
                $cleaned = substr($cleaned, 0, self::MAX_CHARS_PER_PAGE) . "\n\n[…truncated at " . self::MAX_CHARS_PER_PAGE . " characters — the original was " . $before . " chars]";
                $truncated = true;
            }
            $after = strlen($cleaned);

            $out[] = [
                'url'          => $u,
                'html'         => $cleaned,
                'chars_before' => $before,
                'chars_after'  => $after,
                'truncated'    => $truncated,
                'has_html'     => true,
                'note'         => $truncated
                    ? 'HTML was truncated. The first ' . self::MAX_CHARS_PER_PAGE . ' chars contain the head + start of body, which is enough for most structural checks (pagination, nav, breadcrumbs). If you need a section further down, fetch the URL detail in Scouter directly.'
                    : 'Full HTML returned (after script/style/svg/comment stripping).',
            ];
        }
        return $out;
    }

    /**
     * Decode the stored HTML blob. Storage format = gzip-deflated bytes
     * encoded in base64 ; the same path is used by QueryController::htmlSource
     * and HeadingsTool. Returns null when the blob is unusable.
     */
    private static function decodeStoredHtml(?string $stored): ?string
    {
        if (!$stored) return null;
        $decoded = base64_decode($stored, true);
        if ($decoded === false) return null;
        $decompressed = @gzinflate($decoded);
        if ($decompressed === false) {
            // Some legacy rows might be uncompressed base64 — accept either.
            return $decoded;
        }
        return $decompressed;
    }

    /**
     * Strip the byte-heavy / analysis-irrelevant blocks from raw HTML :
     *   - HTML comments
     *   - <script>...</script>   (EXCEPT JSON-LD which carries schema.org)
     *   - <style>...</style>
     *   - <svg>...</svg>         (inline icon SVGs bloat a lot of modern HTML)
     *   - <noscript>...</noscript>
     *
     * Whitespace is left as-is on purpose : indentation/newlines help the
     * model parse the markup and the few extra bytes are worth it.
     */
    private static function cleanHtml(string $html): string
    {
        // 1. Drop HTML comments (including conditional IE ones).
        $html = preg_replace('/<!--.*?-->/s', '', $html);

        // 2. Drop <script>...</script>, EXCEPT JSON-LD blocks (kept because
        //    schema.org data is structural information the model often needs).
        $html = preg_replace_callback(
            '#<script\b([^>]*)>(.*?)</script\s*>#is',
            static function ($m) {
                // Keep the block when type explicitly says JSON-LD.
                if (stripos($m[1], 'application/ld+json') !== false) {
                    return $m[0];
                }
                return '';
            },
            $html
        );

        // 3. Drop <style>, <svg>, <noscript>.
        $html = preg_replace('#<style\b[^>]*>.*?</style\s*>#is',       '', $html);
        $html = preg_replace('#<svg\b[^>]*>.*?</svg\s*>#is',           '', $html);
        $html = preg_replace('#<noscript\b[^>]*>.*?</noscript\s*>#is', '', $html);

        // 4. Collapse runs of >3 blank lines so the truncate cap doesn't
        //    burn most of its budget on whitespace from stripped blocks.
        $html = preg_replace("/(\n[\t ]*){4,}/", "\n\n\n", (string)$html);

        return (string)$html;
    }
}
