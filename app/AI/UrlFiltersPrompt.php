<?php

namespace App\AI;

/**
 * Build the Gemini prompt for natural-language → URL Explorer filters.
 *
 * The output schema is a list of GROUPS (AND between groups, OR between chips
 * inside a group) — mirroring the JS `filterGroups` state of URL Explorer
 * so the AI can express any boolean combination the UI supports.
 *
 *   {
 *     "groups": [
 *       [ {chip}, {chip} ],   // chips inside a group are OR'd
 *       [ {chip} ]             // groups are AND'd
 *     ]
 *   }
 *
 * Wrapped in a single <filters>...</filters> tag for robust extraction.
 *
 * @package    Scouter
 * @subpackage AI
 */
class UrlFiltersPrompt
{
    /**
     * @param string $userPrompt    the question in natural language
     * @param array  $categories    [{id, cat}] available in the current project
     * @param array  $schemaTypes   list of distinct schema_type strings present in the crawl
     * @param array  $extractors    [{key, type}] custom extractors (type = 'number'|'text')
     */
    public static function build(
        string $userPrompt,
        array $categories,
        array $schemaTypes,
        array $extractors,
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

        $retryNote = '';
        if ($previousError !== null && $previousError !== '') {
            $retryNote = "\n\nYour previous answer could not be used. Reason: "
                . $previousError
                . "\nWrite a corrected JSON. Same output contract.\n";
        }

        $cleanPrompt = trim($userPrompt);

        return <<<PROMPT
You translate a SEO analyst's natural-language question into a structured set
of filters for Scouter's URL Explorer.

## Output structure (this is critical)

Return a JSON object shaped like:
```
{
  "groups": [
    [ chip, chip, ... ],   // each inner array is a GROUP
    [ chip ],
    ...
  ]
}
```

Boolean logic:
- **Chips INSIDE the same group are combined with OR.**
- **Groups (outer arrays) are combined with AND.**

So `[[A,B],[C]]` means `(A OR B) AND C`.

Use this to express compound questions. Examples:

- "pages 404 OR 500 with at least 10 inlinks"
  → `[[code=404, code=500], [inlinks>=10]]`
- "indexable pages whose title OR meta description OR h1 is empty or duplicate"
  → `[[compliant=true], [title in (empty,duplicate), metadesc in (empty,duplicate), h1 in (empty,duplicate)]]`
- "pages in noindex that are still in the sitemap"
  → `[[noindex=true], [in_sitemap=true]]`

If the user's question only needs ANDs (no OR), just emit one chip per group.

## Chip schema

Every chip is `{"field": ..., "operator": ..., "value": ...}` (operator may be
omitted on boolean fields — see below).

### Text fields — operators: contains, not_contains, regex, not_regex
  - url               the full URL
  - content_type      e.g. "text/html", "image/png"
  - redirect_to       target URL when the page is a redirect
  - canonical_value   the canonical URL when not self-referential
  - domain            the host part of the URL

### Numeric fields — operators: =, >, <, >=, <=, !=
  - depth             click depth from the start URL (0 = homepage)
  - inlinks           number of internal links pointing here
  - outlinks          number of outgoing links from this page
  - response_time     TTFB in milliseconds
  - word_count        body word count
  - pri               PageRank-like score (float between 0 and 1)

### Boolean fields — value is the STRING "true" or "false". Operator omitted.
  - compliant         indexable AND follows SEO rules (use this for "indexable")
  - canonical         canonical tag is self-referential
  - noindex           has <meta robots="noindex">
  - nofollow          has <meta robots="nofollow">
  - blocked           blocked by robots.txt
  - h1_multiple       page has more than one <h1>
  - headings_missing  hN hierarchy has gaps
  - external          URL is on a different domain (the UI sets external=false
                      by default on first load — only add it if the user
                      explicitly asks about external URLs)
  - crawled           the URL was actually fetched
  - in_sitemap        URL is declared in a sitemap
  - is_html           response is HTML
  - out_of_scope      URL was outside the crawl scope

  Example: `{"field":"compliant","value":"true"}`

### HTTP status — field: code
  - For an exact value or comparison, value is a NUMBER:
    `{"field":"code","operator":"=","value":200}`
    `{"field":"code","operator":">=","value":400}`
  - For a status GROUP, value is a STRING from "1xx","2xx","3xx","4xx","5xx","other"
    (or an array for multiple groups):
    `{"field":"code","operator":"=","value":"4xx"}`
    `{"field":"code","operator":"=","value":["4xx","5xx"]}`

### Title / H1 / Meta description — fields: title, h1, metadesc
  Two distinct filter modes:

  - **Filter on STATE** (no operator, value is array of states):
    `{"field":"title","value":["empty"]}`
    `{"field":"h1","value":["empty","duplicate"]}`

    State values:
      - "unique"     → page has a unique value for this field
      - "empty"      → field is empty / missing
      - "duplicate"  → field is identical across several pages

    Phrasing hints:
      - "title not unique"  → `["empty","duplicate"]` (NOT unique = empty OR duplicate)
      - "title missing"     → `["empty"]`
      - "duplicate titles"  → `["duplicate"]`
      - "title problems"    → `["empty","duplicate"]`

  - **Filter on TEXT content** (operator: contains|not_contains|regex|not_regex):
    `{"field":"title","operator":"contains","value":"shoes"}`

### Category — field: category
  - operators: in, not_in
  - value is an array of category NAMES (server resolves to IDs)
  - Available categories for this project:
<categories>
{$catList}
</categories>

### Structured data — field: schemas
  - For COUNT comparisons (operators =, >, <, >=, <=): value is a NUMBER
    (number of distinct schema types on the page).
    `{"field":"schemas","operator":">","value":0}` = has at least one schema
  - For PRESENCE (operators contains, not_contains): value is an ARRAY of schema names.
    `{"field":"schemas","operator":"contains","value":["Product"]}`
  - Available schema types in this crawl:
<schemas>
{$schemaList}
</schemas>

### Custom extractors
<extractors>
{$extractorList}
</extractors>
  - Field name is "extract_<key>" exactly as listed above.
  - text extractors  → operators: contains, not_contains, regex, not_regex
  - number extractors → operators: =, >, <, >=, <=, !=

## Hard rules

- Output is `{"groups": [ [chip,...], ... ]}`. NO other top-level keys.
- Each chip MUST use a field from the lists above. Drop any chip you can't
  map cleanly — better return 2 valid groups than 3 with garbage.
- Don't emit `external=false` unless the user explicitly asks about external
  URLs — the UI already handles the default scope filter.
- Read the user's wording carefully:
  - "ET" / "AND" / commas → separate groups
  - "OU" / "OR" / "soit … soit" → multiple chips in the same group
- For "indexable", use `compliant=true`. For "non indexable", `compliant=false`.

## User question

<question>
{$cleanPrompt}
</question>

## Output format

Wrap your final JSON inside a single <filters>...</filters> HTML tag.
Output nothing else outside the tag. No markdown code fences.

Worked example: "indexable pages with a title OR h1 problem"
<filters>
{
  "groups": [
    [ {"field": "compliant", "value": "true"} ],
    [
      {"field": "title", "value": ["empty", "duplicate"]},
      {"field": "h1",    "value": ["empty", "duplicate"]}
    ]
  ]
}
</filters>
{$retryNote}
PROMPT;
    }

    /**
     * Extract the JSON payload from the model response.
     *
     * Accepts the new {groups:[[...]]} format AND a legacy flat {filters:[...]}
     * shape so older responses keep working — the legacy shape is treated as
     * "one chip per AND group".
     *
     * @return array<int, array>|null  Array of groups (each group = array of chips), or null on failure.
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

        // Preferred shape: {"groups": [ [chip,...], ... ]}
        if (isset($decoded['groups']) && is_array($decoded['groups'])) {
            return $decoded['groups'];
        }

        // Legacy shape: {"filters": [chip, chip, ...]} — each chip becomes its own group.
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
