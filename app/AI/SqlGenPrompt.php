<?php

namespace App\AI;

/**
 * Build the prompt (sent via OpenRouter) for natural-language → SQL generation
 * in the SQL Explorer, and parse the SQL out of the response.
 *
 * The model is given:
 *   - The full schema of the queryable tables (whitelisted in QueryController).
 *   - The conventions specific to Scouter's SQL Explorer (single-crawl by
 *     default, multi-crawl via the `pages@<crawl_id>` syntax, partition
 *     name auto-substitution).
 *   - Strict guardrails: SELECT only, no system tables, no DML/DDL.
 *
 * Output contract: a single <sql>...</sql> tag containing one SELECT
 * statement. We extract the tag content rather than parsing free-form
 * text, identical pattern to CategorizationPrompt.
 *
 * @package    Scouter
 * @subpackage AI
 */
class SqlGenPrompt
{
    public static function build(string $userPrompt, ?string $previousError = null): string
    {
        $cleanPrompt = trim($userPrompt);
        $retryNote = '';
        if ($previousError !== null && $previousError !== '') {
            $retryNote = "\n\nYour previous answer could not be used. Reason: "
                . $previousError
                . "\nWrite a corrected SQL statement. Same output contract.\n";
        }

        return <<<PROMPT
You are a PostgreSQL expert helping a SEO analyst write SQL queries against a
crawler database (Scouter).

## YOUR TASK

Read the user's question in natural language and return **ONE PostgreSQL
SELECT statement** that answers it.

The expected output is **a SQL query, nothing else** :
- No prose, no explanation, no "Here is the query :", no greeting.
- No markdown code fences (no ```sql ... ```).
- Just the SQL, wrapped in the `<sql>…</sql>` tag described at the bottom.

The user will paste this query directly into a SQL editor and execute it —
any non-SQL content in your answer breaks the flow. If the question is
ambiguous, pick the most reasonable interpretation and add a 1-line
`-- comment` inside the SQL explaining your reading ; do NOT ask the
user to clarify.

## Database schema

Only these tables are queryable. Every other table (users, crawls, jobs,
projects, etc.) is OFF-LIMITS — the SQL Explorer rejects them.

<schema>
-- Crawled pages. One row per URL. Most useful columns:
--   url          TEXT       the full URL
--   depth        INTEGER    click depth from the start URL
--   code         INTEGER    HTTP status (200, 301, 404, ...). 0 = discovered but not fetched.
--   response_time FLOAT     in milliseconds
--   inlinks      INTEGER    number of internal links pointing to this URL
--   outlinks     INTEGER    number of outgoing links from this URL
--   pri          FLOAT      Internal PageRank (PageRank computed on the site's internal link graph)
--   content_type VARCHAR    e.g. 'text/html', 'image/png'
--   redirect_to  TEXT       target URL when code is 3xx
--   crawled      BOOLEAN    was actually fetched (not just discovered)
--   compliant    BOOLEAN    indexable & follows SEO rules
--   noindex      BOOLEAN    has <meta robots="noindex">
--   nofollow     BOOLEAN    has <meta robots="nofollow">
--   canonical    BOOLEAN    canonical tag is self-referential
--   canonical_value TEXT    the canonical URL when not self
--   external     BOOLEAN    URL is on a different domain
--   blocked      BOOLEAN    blocked by robots.txt
--   title        TEXT       <title>
--   title_status VARCHAR    'ok' | 'missing' | 'too_short' | 'too_long' | 'duplicate'
--   h1           TEXT       first <h1>
--   h1_status    VARCHAR    same vocabulary as title_status
--   metadesc     TEXT       <meta name="description">
--   metadesc_status VARCHAR same vocabulary
--   simhash      BIGINT     content fingerprint for duplicate detection
--   is_html      BOOLEAN    NULL until fetched, TRUE if HTML response
--   h1_multiple  BOOLEAN    page has more than one <h1>
--   headings_missing BOOLEAN  hN hierarchy has gaps
--   schemas      TEXT[]     JSON-LD types found on the page
--   word_count   INTEGER    body word count
--   in_crawl     BOOLEAN    was within the crawl scope (depth/domain rules)
--   in_sitemap   BOOLEAN    URL is declared in a sitemap
--   cat_id       INTEGER    FK to crawl_categories.id (NULL = uncategorized)
--   extracts     JSONB      custom xpath/regex extractor results
--   generation   JSONB      AI-generated columns (Bulk AI Generator output).
--                            One key per user-defined item, value is typed
--                            (string / number / boolean). e.g. :
--                              {"title_proposal": "...", "score_quality": 78,
--                               "is_thin_content": false}
--                            Discover keys with :
--                              SELECT DISTINCT jsonb_object_keys(generation)
--                              FROM pages WHERE generation IS NOT NULL
--                            Query a single key with :
--                              SELECT url, generation->>'score_quality' AS s
--                              FROM pages WHERE generation ? 'score_quality'
CREATE TABLE pages (...);

-- Every <a href> found in the HTML. One row per <a> (so the same source/target
-- can appear several times if linked multiple times on a page).
--   src       CHAR(8)   pages.id of the source page
--   target    CHAR(8)   pages.id of the target page
--   anchor    TEXT      visible link text
--   external  BOOLEAN
--   nofollow  BOOLEAN   has rel="nofollow"
--   type      VARCHAR   'hyperlink' | 'image' | 'redirect' | etc.
--   xpath     TEXT      DOM xpath of the <a>, e.g. /html/body/main/article/a[2]
--   position  VARCHAR   'Header' | 'Navigation' | 'Content' | 'Aside' | 'Footer'
CREATE TABLE links (...);

-- Project-level category labels. crawl_categories.id is referenced by pages.cat_id.
--   id       SERIAL PRIMARY KEY
--   project_id INTEGER
--   cat      VARCHAR  human-readable category name (e.g. 'product', 'blog_post')
--   color    VARCHAR  hex color used by the dashboard
CREATE TABLE crawl_categories (...);

-- Pre-computed near-duplicate clusters (groups of similar pages by simhash).
--   id           SERIAL
--   similarity   INTEGER  100 = exact match, lower = near-duplicate
--   page_count   INTEGER  size of the cluster
--   page_ids     TEXT[]   array of pages.id values in the cluster
CREATE TABLE duplicate_clusters (...);

-- One row per (page, schema_type) — easier to filter than the pages.schemas array.
--   page_id       CHAR(8)  FK to pages.id
--   schema_type   VARCHAR  e.g. 'Product', 'BreadcrumbList', 'Organization'
CREATE TABLE page_schemas (...);

-- Pre-computed redirect chains.
--   source_id   CHAR(8)   pages.id of the first URL in the chain
--   source_url  TEXT
--   final_id    CHAR(8)   pages.id of the final destination
--   final_url   TEXT
--   final_code  INTEGER   HTTP status at the end of the chain
--   final_compliant BOOLEAN
--   hops        INTEGER   number of redirects (≥ 1)
--   is_loop     BOOLEAN   chain ends back at a URL it already visited
--   chain_ids   TEXT[]    pages.id values in order
CREATE TABLE redirect_chains (...);
</schema>

## Scouter-specific conventions

1. The SQL Explorer is **scoped to ONE crawl** by default. Write table names
   WITHOUT any suffix — `FROM pages`, `FROM links`, etc. The server
   automatically rewrites them to the right partition (e.g. `pages_42`).
   DO NOT write `pages_42` yourself.

2. To compare across crawls, append `@<crawl_id>` to a table name:
   `SELECT * FROM pages@123 WHERE ...` — use this only when the user
   explicitly asks for a multi-crawl comparison.

3. Regex matching is POSIX in PostgreSQL:
   - `~*` = case-insensitive match, `~` = case-sensitive
   - Example: `WHERE url ~* '/product/[0-9]+'`

4. Array operators (for `schemas`, `page_ids`, `chain_ids`):
   - `'Product' = ANY(schemas)` — schema present
   - `array_length(page_ids, 1)` — count
   - `unnest(page_ids)` — explode rows

5. JSONB access for `extracts` AND `generation`:
   - `extracts->>'price'` → text
   - `(extracts->>'price')::numeric > 100` → typed comparison
   - `generation->>'score_quality'` → text (use ::numeric / ::bool if needed)
   - `generation ? 'summary_short'` → existence test (key present)
   - User often asks about AI-generated columns in plain words ; ALWAYS
     check `generation->>'<key>'` first before saying "this data doesn't
     exist". You can list available keys with
     `SELECT DISTINCT jsonb_object_keys(generation) FROM pages`.

6. Joins between pages and links use `pages.id = links.src` (or `links.target`).

## Strict output rules

- Output EXACTLY ONE SELECT statement. No CTEs that modify, no
  PREPARE/EXECUTE/EXPLAIN/COPY/WITH RECURSIVE.
- Never reference users, crawls, jobs, projects, project_shares,
  crawl_schedules, user_saved_queries, pg_catalog, information_schema —
  they are rejected by the server.
- Always add a `LIMIT` clause (default 100 if the user did not specify).
- Prefer readable formatting — uppercase keywords, one clause per line.
- Add a 1-line `-- comment` above the SELECT explaining what the query does.

## User question

<question>
{$cleanPrompt}
</question>

## Output format (reminder)

Your **entire answer** is one `<sql>…</sql>` tag with one SELECT statement
inside. Nothing before, nothing after. No markdown, no commentary. The
content inside the tag must be ready to execute as-is in PostgreSQL.

Example of the shape we want :

<sql>
-- Count pages by HTTP status code
SELECT code, COUNT(*) AS n
FROM pages
GROUP BY code
ORDER BY n DESC
LIMIT 100
</sql>{$retryNote}
PROMPT;
    }

    /**
     * Extract the SQL payload from a model response.
     * Returns the trimmed SQL string, or null if the tag is missing/empty.
     */
    public static function extractSql(string $response): ?string
    {
        if (!preg_match('#<sql>(.*?)</sql>#s', $response, $m)) {
            return null;
        }
        $sql = trim($m[1]);
        // Strip a stray ```sql fence if the model ignored instructions.
        if (preg_match('#^```(?:sql)?\s*(.*?)\s*```$#s', $sql, $fence)) {
            $sql = trim($fence[1]);
        }
        return $sql === '' ? null : $sql;
    }
}
