<?php

namespace App\AI;

/**
 * Build the prompt (sent via OpenRouter) for natural-language → Link Explorer filters.
 *
 * Like UrlFiltersPrompt but for the Links table — each row represents one
 * <a> link between a SOURCE page and a TARGET page. Most page-level fields
 * (url, code, title, etc.) therefore exist in two flavours, and each chip
 * must declare `target: "source"` or `target: "target"`. Link-only fields
 * (anchor, position, xpath, etc.) use `target: "link"`.
 *
 * Output: groups (AND between groups, OR between chips inside a group),
 * inside a <filters>...</filters> tag — same idiom as the URL prompt.
 *
 * @package    Scouter
 * @subpackage AI
 */
class LinkFiltersPrompt
{
    public static function build(
        string $userPrompt,
        array $categories,
        array $schemaTypes,
        array $extractors,
        array $generations = [],
        ?string $previousError = null
    ): string {
        $catList = '';
        if (!empty($categories)) {
            foreach ($categories as $c) {
                $name = is_object($c) ? $c->cat : ($c['cat'] ?? '');
                if ($name !== '') $catList .= "  - {$name}\n";
            }
        }
        $catList = $catList !== '' ? rtrim($catList) : '  (no categories defined yet)';

        $schemaList = '';
        if (!empty($schemaTypes)) {
            foreach ($schemaTypes as $s) {
                if (is_string($s) && $s !== '') $schemaList .= "  - {$s}\n";
            }
        }
        $schemaList = $schemaList !== '' ? rtrim($schemaList) : '  (no structured data types found in this crawl)';

        $extractorList = '';
        if (!empty($extractors)) {
            foreach ($extractors as $e) {
                $key  = is_object($e) ? $e->key  : ($e['key']  ?? '');
                $type = is_object($e) ? $e->type : ($e['type'] ?? 'text');
                if ($key !== '') $extractorList .= "  - extract_{$key} ({$type})\n";
            }
        }
        $extractorList = $extractorList !== '' ? rtrim($extractorList) : '  (no custom extractors configured)';

        // AI-generated columns (Bulk AI Generator → pages.generation JSONB).
        $generationList = '';
        if (!empty($generations)) {
            foreach ($generations as $g) {
                $key  = is_object($g) ? $g->key  : ($g['key']  ?? '');
                $type = is_object($g) ? $g->type : ($g['type'] ?? 'text');
                if ($key !== '') $generationList .= "  - generation_{$key} ({$type})\n";
            }
        }
        $generationList = $generationList !== '' ? rtrim($generationList) : '  (no AI-generated columns configured)';

        $retryNote = '';
        if ($previousError !== null && $previousError !== '') {
            $retryNote = "\n\nYour previous answer could not be used. Reason: "
                . $previousError
                . "\nWrite a corrected JSON. Same output contract.\n";
        }

        $cleanPrompt = trim($userPrompt);

        return <<<PROMPT
You translate a SEO analyst's natural-language question into filters for
Scouter's LINK EXPLORER (table of <a> links between pages).

## Mental model

Each row in the Link Explorer represents ONE link: a SOURCE page links to a
TARGET page. Page-level filters (url, code, title, ...) therefore exist in two
flavours — you must specify which page they apply to via the `target` key
on each chip.

  - `target: "source"` → property of the source page (the one containing the link)
  - `target: "target"` → property of the target page (the destination)
  - `target: "link"`   → property of the link itself (anchor, position, xpath, etc.)

If the user's wording is ambiguous, prefer `target: "target"` (the destination
page — that's usually what SEO audits care about: "broken links" = target=404,
"links to noindex pages" = target page is noindex, etc.). If they explicitly
say "from pages that ...", that's a SOURCE filter.

## Output structure

Return:
```
{
  "groups": [
    [ chip, chip, ... ],   // chips in same group → OR
    [ chip ],
    ...                    // outer array → AND
  ]
}
```

So `[[A,B],[C]]` = `(A OR B) AND C`.

## Chip schema

`{"field": ..., "operator": ..., "value": ..., "target": "source|target|link"}`

`target` is REQUIRED on every chip. Operator may be omitted on booleans and
on enum-style link fields (see below).

## LINK-scope fields (target = "link")

  - anchor               text of the <a>. Operators: contains, not_contains, regex, not_regex
  - external             whether the link is internal or external.
                         value: "external" or "internal"  (NO operator)
  - link_nofollow        rel attribute. value: "nofollow" or "dofollow" (NO operator)
  - type                 link type. value: array of "ahref" / "canonical" / "redirect"
  - position             where in the page the link sits. value: array of
                         "Header", "Navigation", "Content", "Aside", "Footer"
  - self_link            true when source and target are the same page.
                         Use {"field":"self_link","operator":"=","value":true,"target":"link"}
                         to filter "only self-links".
  - xpath                DOM path of the <a>. Operators: contains, not_contains, regex, not_regex

## PAGE-scope fields (target = "source" or "target")

Each of these applies to EITHER the source page or the target page — pick the
right one based on context.

### Text — operators: contains, not_contains, regex, not_regex
  - url               the full URL
  - content_type      e.g. "text/html"
  - redirect_to       target URL when the page is a redirect
  - canonical_value   the canonical URL when not self-referential
  - domain            host part of the URL

### Numeric — operators: =, >, <, >=, <=, !=
  - depth             click depth
  - inlinks           number of internal links to this page
  - outlinks          number of outgoing links from this page
  - response_time     TTFB in ms
  - word_count        body word count
  - pri               Internal PageRank score (float 0-1, computed on the site's internal links)

### Boolean (value is STRING "true" or "false", operator omitted)
  - compliant         indexable AND follows SEO rules (use this for "indexable")
  - canonical         canonical tag is self-referential
  - noindex           has meta robots noindex
  - nofollow          has meta robots nofollow
  - blocked           blocked by robots.txt
  - h1_multiple       page has more than one h1
  - headings_missing  hN hierarchy has gaps
  - crawled           the URL was actually fetched
  - in_sitemap        URL is declared in a sitemap
  - is_html           response is HTML
  - out_of_scope      URL was outside the crawl scope

### HTTP status — field: code
  - exact/comparison: value is a NUMBER
    `{"field":"code","operator":"=","value":404,"target":"target"}`
  - status GROUP: value is a STRING "1xx" / "2xx" / ... / "5xx" / "other"
    (or an array)
    `{"field":"code","operator":"=","value":["4xx","5xx"],"target":"target"}`

### Title / H1 / Meta description — fields: title, h1, metadesc
  Two modes:

  - **STATE** (no operator, value is array of states):
    `{"field":"title","value":["empty","duplicate"],"target":"target"}`
    States: "unique", "empty", "duplicate"

    Phrasing:
      - "title not unique"  → ["empty","duplicate"]
      - "title missing"     → ["empty"]
      - "title duplicate"   → ["duplicate"]

  - **TEXT** (contains|not_contains|regex|not_regex):
    `{"field":"title","operator":"contains","value":"shoes","target":"source"}`

### Category — field: category
  - operators: in, not_in
  - value is an array of category NAMES (server resolves to IDs)
  - Available in this project:
<categories>
{$catList}
</categories>

### Structured data — field: schemas
  - COUNT (=, >, <, >=, <=): value is a NUMBER
    `{"field":"schemas","operator":">","value":0,"target":"target"}` = page has ≥1 schema
  - PRESENCE (contains, not_contains): value is an ARRAY of type names
  - Available schema types:
<schemas>
{$schemaList}
</schemas>

### Custom extractors
<extractors>
{$extractorList}
</extractors>
  - field "extract_<key>" exactly as listed
  - text → contains, not_contains, regex, not_regex
  - number → =, >, <, >=, <=, !=

### AI-generated columns (Bulk AI Generator)
<generations>
{$generationList}
</generations>
  - field "generation_<key>" exactly as listed, page-scope (target='source'|'target')
  - text → contains, not_contains, regex, not_regex
  - number → =, >, <, >=, <=, !=
  - boolean → =, value MUST be the string "true" or "false"

## Worked examples

"links pointing to 404 pages"
<filters>
{
  "groups": [
    [{"field":"code","operator":"=","value":404,"target":"target"}]
  ]
}
</filters>

"footer or navigation links from indexable pages, pointing to noindex pages"
<filters>
{
  "groups": [
    [{"field":"position","value":["Footer","Navigation"],"target":"link"}],
    [{"field":"compliant","value":"true","target":"source"}],
    [{"field":"noindex","value":"true","target":"target"}]
  ]
}
</filters>

"nofollow links to external pages whose title or h1 is empty"
<filters>
{
  "groups": [
    [{"field":"link_nofollow","value":"nofollow","target":"link"}],
    [{"field":"external","value":"external","target":"link"}],
    [
      {"field":"title","value":["empty"],"target":"target"},
      {"field":"h1","value":["empty"],"target":"target"}
    ]
  ]
}
</filters>

## Hard rules

- Output is `{"groups": [ [chip,...], ... ]}` only. No other top-level keys.
- Every chip MUST have `target` set to "source", "target", or "link".
- Drop any chip whose field doesn't exist in the lists above — better fewer
  valid groups than garbage.
- "ET / AND / virgules" → separate groups.
- "OU / OR / soit … soit" → multiple chips in the same group.
- For "indexable" → compliant=true. For "non indexable" → compliant=false.
- Default page side for ambiguous phrasing: "target" (destination page).

## User question

<question>
{$cleanPrompt}
</question>

## Output format

Wrap your final JSON inside a single <filters>...</filters> HTML tag.
Output nothing else outside the tag. No markdown code fences.{$retryNote}
PROMPT;
    }

    /**
     * Extract the JSON payload from the model response.
     * Accepts {groups:[[...]]} (preferred) or {filters:[...]} (legacy flat).
     *
     * @return array<int, array>|null
     */
    public static function extractGroups(string $response): ?array
    {
        if (!preg_match('#<filters>(.*?)</filters>#s', $response, $m)) {
            return null;
        }
        $body = trim($m[1]);
        if (preg_match('#^```(?:json)?\s*(.*?)\s*```$#s', $body, $fence)) {
            $body = trim($fence[1]);
        }
        if ($body === '') return null;

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) return null;

        if (isset($decoded['groups']) && is_array($decoded['groups'])) {
            return $decoded['groups'];
        }
        if (isset($decoded['filters']) && is_array($decoded['filters'])) {
            $groups = [];
            foreach ($decoded['filters'] as $chip) {
                if (is_array($chip)) $groups[] = [$chip];
            }
            return $groups;
        }
        return null;
    }
}
