<?php

namespace App\AI;

use App\Database\PostgresDatabase;
use App\Storage\HtmlStore;
use PDO;

/**
 * Builds the per-URL context payload sent to the model in the
 * Bulk AI Generator flow.
 *
 * Given a crawl + a list of page IDs + a list of context fields the user
 * checked in the wizard (e.g. `['url','title','h1','visible_content']`),
 * returns one normalised array per page :
 *
 *   ['page_id' => 'abc12345', 'url' => '/foo',
 *    'title' => '…', 'h1' => '…', 'visible_content' => '…']
 *
 * Only the fields the user asked for are present (saves tokens).
 *
 * The expensive field is `visible_content` : it requires fetching the
 * stored HTML blob (table `html`), decoding (base64 + gzip), stripping
 * `<script>` / `<style>` / `<svg>` / `<noscript>` / `<nav>` / `<footer>`,
 * and capping at 4000 chars. Same pipeline as for simhash duplication —
 * we reuse what's already proven elsewhere in the codebase rather than
 * inventing a new one.
 *
 * @package    Scouter
 * @subpackage AI
 */
class ContextBuilder
{
    /** Hard cap on the visible text injected per URL, in characters. */
    public const VISIBLE_CONTENT_CHAR_CAP = 4000;

    /** Whitelisted context fields the wizard can request. Mirrors the
     *  columns of `pages` (the ones that make sense as AI context) plus
     *  two virtual fields handled separately :
     *    - `category`        → joined from crawl_categories.cat
     *    - `visible_content` → decoded + stripped HTML body, cap 4k chars
     *  Custom extracts use the `extract.<key>` notation. */
    public const ALLOWED_FIELDS = [
        'url',
        // HTTP
        'code', 'response_time', 'content_type', 'redirect_to',
        // Indexability flags
        'compliant', 'canonical', 'canonical_value', 'noindex',
        'nofollow', 'blocked', 'external',
        // SEO content
        'title', 'title_status', 'h1', 'h1_status',
        'metadesc', 'metadesc_status', 'h1_multiple', 'headings_missing',
        'word_count',
        // Crawl structure / signals
        'depth', 'inlinks', 'outlinks', 'pri', 'domain',
        'crawled', 'in_crawl', 'in_sitemap', 'is_html',
        // Virtual / computed
        'category',
        'visible_content',
        // schemas (text[]) — handled via array_to_string below
        'schemas',
    ];

    /** Boolean columns — rendered as "true"/"false" strings (instead of
     *  PHP's empty-string vs "1") so the model sees clean values. */
    private const BOOL_FIELDS = [
        'compliant', 'canonical', 'noindex', 'nofollow', 'blocked',
        'external', 'crawled', 'in_crawl', 'in_sitemap', 'is_html',
        'h1_multiple', 'headings_missing',
    ];

    private \PDO $db;

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db ?? PostgresDatabase::getInstance()->getConnection();
    }

    /**
     * Build the context for a list of page IDs.
     *
     * @param int      $crawlId        target crawl
     * @param string[] $pageIds        page ids (CHAR(8) strings)
     * @param string[] $fields         requested fields from ALLOWED_FIELDS,
     *                                 may also contain 'extract.<key>' or
     *                                 'generation.<key>' entries
     * @return array<int, array<string, mixed>>  one entry per page (in same order as $pageIds)
     */
    public function buildForPages(int $crawlId, array $pageIds, array $fields): array
    {
        if (empty($pageIds)) return [];

        // Normalise fields : split into base columns + extract keys + generation keys.
        $needsVisible    = in_array('visible_content', $fields, true);
        $extractKeys     = [];
        $generationKeys  = [];
        $baseFields      = [];
        foreach ($fields as $f) {
            if (strpos($f, 'extract.') === 0) {
                $extractKeys[] = substr($f, 8);
            } elseif (strpos($f, 'generation.') === 0) {
                $generationKeys[] = substr($f, 11);
            } elseif (in_array($f, self::ALLOWED_FIELDS, true)) {
                $baseFields[] = $f;
            }
        }
        // `url` is implicit — always returned even if not asked, the model
        // needs it to identify the row in its JSON output.
        if (!in_array('url', $baseFields, true)) array_unshift($baseFields, 'url');

        // 1) Fetch the base columns from pages (one query for all).
        $select = ['id'];
        foreach ($baseFields as $f) {
            if ($f === 'visible_content') continue; // handled separately
            if ($f === 'category')        continue; // handled separately
            if ($f === 'schemas') {
                // text[] → render as comma-joined for the model
                $select[] = "array_to_string(schemas, ', ') AS schemas";
                continue;
            }
            $select[] = $f;
        }
        // For category : join crawl_categories (project-level).
        if (in_array('category', $baseFields, true)) {
            $select[] = '(SELECT cat FROM crawl_categories WHERE id = p.cat_id) AS category';
        }
        // For extracts : project them as JSONB extracts->>'key' AS extract_key.
        foreach ($extractKeys as $key) {
            $safe = preg_replace('/[^a-z0-9_]/i', '', $key); // sanity
            if ($safe === '') continue;
            $select[] = "extracts->>'" . $safe . "' AS extract_" . $safe;
        }
        // Same shape for previously-generated values : generation->>'key' AS generation_key.
        foreach ($generationKeys as $key) {
            $safe = preg_replace('/[^a-z0-9_]/i', '', $key);
            if ($safe === '') continue;
            $select[] = "generation->>'" . $safe . "' AS generation_" . $safe;
        }

        $placeholders = [];
        $params = [':cid' => $crawlId];
        foreach ($pageIds as $i => $pid) {
            $key = ':p' . $i;
            $placeholders[] = $key;
            $params[$key] = $pid;
        }
        // Migrated crawl → read via the ChPdo shim (PG purged): it rewrites the
        // virtual tables AND translates the PG-isms used here — `extracts->>'k'` /
        // `generation->>'k'` become CH Map access `extracts['k']` / `generation['k']`.
        $useCh = \App\Database\CrawlStore::usesClickHouse($crawlId);
        $db = $useCh ? new \App\Database\ChPdo($crawlId) : $this->db;

        $sql = 'SELECT ' . implode(', ', $select)
             . ' FROM pages p WHERE crawl_id = :cid AND id IN (' . implode(',', $placeholders) . ')';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rowsById = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rowsById[$r['id']] = $r;
        }

        // 2) Fetch visible_content if requested (separate table `html`).
        $visibleById = [];
        if ($needsVisible) {
            $visibleById = $this->fetchVisibleContent($crawlId, $pageIds, $useCh);
        }

        // 3) Assemble in the input order, drop NULLs, attach extract values.
        $out = [];
        foreach ($pageIds as $pid) {
            $row = $rowsById[$pid] ?? null;
            if (!$row) continue;
            $entry = ['page_id' => (string)$row['id']];
            foreach ($baseFields as $f) {
                if ($f === 'visible_content') {
                    $entry['visible_content'] = $visibleById[$pid] ?? '';
                } elseif (array_key_exists($f, $row)) {
                    $entry[$f] = self::renderValue($f, $row[$f]);
                }
            }
            foreach ($extractKeys as $key) {
                $safe = preg_replace('/[^a-z0-9_]/i', '', $key);
                if ($safe === '') continue;
                $colKey = 'extract_' . $safe;
                if (array_key_exists($colKey, $row)) {
                    $entry['extract.' . $safe] = $row[$colKey] !== null ? (string)$row[$colKey] : '';
                }
            }
            foreach ($generationKeys as $key) {
                $safe = preg_replace('/[^a-z0-9_]/i', '', $key);
                if ($safe === '') continue;
                $colKey = 'generation_' . $safe;
                if (array_key_exists($colKey, $row)) {
                    $entry['generation.' . $safe] = $row[$colKey] !== null ? (string)$row[$colKey] : '';
                }
            }
            $out[] = $entry;
        }
        return $out;
    }

    /** Render a value for the model — booleans become "true"/"false",
     *  NULLs become empty string, everything else stays as a string. */
    private static function renderValue(string $field, $val): string
    {
        if ($val === null) return '';
        if (in_array($field, self::BOOL_FIELDS, true)) {
            // Postgres returns booleans as 't'/'f' or true/false through PDO
            // depending on the type binding ; normalise both.
            if (is_bool($val))   return $val ? 'true' : 'false';
            if ($val === 't')    return 'true';
            if ($val === 'f')    return 'false';
            if ($val === '1' || $val === 1) return 'true';
            if ($val === '0' || $val === 0) return 'false';
        }
        return (string)$val;
    }

    /**
     * Roughly estimate the token cost of a context entry before sending.
     * Used by the wizard's pre-flight estimation panel.
     *
     * Heuristic : ~4 chars per token (English/French). Good enough for a
     * ±15% estimate — the real numbers come back in the API response.
     */
    public static function estimateContextTokens(array $contextEntry): int
    {
        $total = 0;
        foreach ($contextEntry as $k => $v) {
            // Account for the JSON key + quoting overhead (~6 chars).
            $total += mb_strlen((string)$k) + 6 + mb_strlen((string)$v);
        }
        return (int)ceil($total / 4);
    }

    // -------------------------------------------------------------------------
    // Visible content extraction
    // -------------------------------------------------------------------------

    /**
     * Pull the HTML blobs from `html`, decode + strip + cap.
     *
     * @return array<string,string>  page_id => cleaned text (max 4k chars)
     */
    private function fetchVisibleContent(int $crawlId, array $pageIds, bool $useCh = false): array
    {
        if (empty($pageIds)) return [];
        $db = $useCh ? new \App\Database\ChPdo($crawlId) : $this->db;

        // HTML now lives in the blob store (S3/local), returned decompressed;
        // older crawls fall back to the DB inside HtmlStore.
        $htmlById = HtmlStore::fetchMany($crawlId, $pageIds, $useCh, $db);

        $out = [];
        foreach ($htmlById as $id => $raw) {
            if ($raw === null || $raw === '') {
                $out[$id] = '';
                continue;
            }
            $text = self::stripHtmlToText($raw);
            if (mb_strlen($text) > self::VISIBLE_CONTENT_CHAR_CAP) {
                $text = mb_substr($text, 0, self::VISIBLE_CONTENT_CHAR_CAP) . '…';
            }
            $out[$id] = $text;
        }
        return $out;
    }

    /**
     * Decode the stored HTML blob (same logic as HtmlTool/HeadingsTool :
     * base64 + gzip-deflate). Returns null on failure.
     */
    private static function decodeStoredHtml(?string $stored): ?string
    {
        if (!$stored) return null;
        $decoded = base64_decode($stored, true);
        if ($decoded === false) return null;
        $decompressed = @gzinflate($decoded);
        return $decompressed !== false ? $decompressed : $decoded;
    }

    /**
     * Strip a raw HTML document down to its visible text.
     *
     * Removes : script / style / svg / noscript / nav / footer / aside,
     * HTML comments, then converts remaining tags to spaces, collapses
     * whitespace. Same intent as the simhash duplication pipeline, kept
     * intentionally simple : we want pages.title-class signal, not
     * perfect text extraction.
     */
    private static function stripHtmlToText(string $html): string
    {
        // Drop heavy non-content blocks before parsing.
        $html = preg_replace('/<!--.*?-->/s',                                     '', $html);
        $html = preg_replace('#<script\b[^>]*>.*?</script\s*>#is',                '', (string)$html);
        $html = preg_replace('#<style\b[^>]*>.*?</style\s*>#is',                  '', (string)$html);
        $html = preg_replace('#<svg\b[^>]*>.*?</svg\s*>#is',                      '', (string)$html);
        $html = preg_replace('#<noscript\b[^>]*>.*?</noscript\s*>#is',            '', (string)$html);
        $html = preg_replace('#<(nav|footer|aside|header)\b[^>]*>.*?</\1\s*>#is', '', (string)$html);

        // Replace tags by space so adjacent inline elements don't merge words.
        $text = preg_replace('/<[^>]+>/', ' ', (string)$html);
        $text = html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Collapse whitespace.
        $text = preg_replace('/\s+/u', ' ', (string)$text);
        return trim((string)$text);
    }
}
