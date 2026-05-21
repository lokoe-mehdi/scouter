<?php

namespace App\AI;

/**
 * Build the OpenRouter prompt for template-based URL categorization, and parse
 * the YAML answer back out of the response.
 *
 * The prompt is in English on purpose — most models follow formatting
 * instructions more reliably in English, and the output (a YAML config) is
 * locale-agnostic.
 *
 * Output contract with the model: a single <categorization>...</categorization>
 * HTML tag containing valid YAML. We extract the tag content rather than
 * parsing free-form text so a stray sentence around the YAML can't break us.
 *
 * @package    Scouter
 * @subpackage AI
 */
class CategorizationPrompt
{
    /**
     * @param array<int, array{url: string, h1: ?string, title: ?string}> $sample
     * @param string $domain  The site domain (e.g. "example.com"), goes into every
     *                        category's `dom:` key — Scouter's categorization layer
     *                        requires it and we want zero manual editing after the
     *                        AI suggestion.
     */
    public static function build(array $sample, string $domain, ?string $previousError = null): string
    {
        $sampleJsonl = '';
        foreach ($sample as $row) {
            $sampleJsonl .= json_encode([
                'url'   => (string)($row['url'] ?? ''),
                'h1'    => (string)($row['h1'] ?? ''),
                'title' => (string)($row['title'] ?? ''),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        }
        $sampleJsonl = rtrim($sampleJsonl);

        $retryNote = '';
        if ($previousError !== null && $previousError !== '') {
            $retryNote = "\n\nYour previous answer could not be used. Reason: "
                . $previousError
                . "\nPlease produce a corrected YAML that compiles. Same output contract.\n";
        }

        $safeDomain = preg_replace('/[^a-z0-9.\-]/i', '', $domain) ?: 'example.com';

        return <<<PROMPT
You are a SEO crawler analyst. Given a sample of pages from a single website,
your job is to propose a categorization by MAJOR TECHNICAL TEMPLATE — the
distinct page types served by the site's CMS or codebase. Think "what HTML
template renders this URL?" — homepage, product page, category listing, blog
post, etc. — NOT topical themes ("shoes", "kitchen", "summer collection") or
subjective groupings.

Rules of the game, in priority order:

1. AIM FOR 4 TO 7 CATEGORIES — 8 is the absolute maximum. Fewer is better.
   The goal is a SHORT, READABLE breakdown of the site's structure, not a
   granular taxonomy. If two candidate categories share the same template
   logic (same kind of page, just different topic), MERGE THEM into one.

   Examples of correct merges:
     - "shoes_category" + "kitchen_category"   → ONE "category" template
     - "fr_blog_post" + "en_blog_post"          → ONE "blog_post" template
     - "iphone_product" + "samsung_product"     → ONE "product" template
     - "/help/article-X" + "/faq/article-Y"     → ONE "support" template
       if they share the same /article/ layout

   Examples of wrong over-splitting (DO NOT DO):
     - Splitting products by brand, color, or department
     - Splitting blog posts by topic or year
     - Splitting categories by language version (unless the site has truly
       distinct templates per locale)

2. EVERY URL in the sample must match at least one category. Look at the URL
   PATH, not at h1/title — h1/title are clues to help you understand what
   the template is, but the matching is purely on URL patterns. Add an
   "other" catch-all only if absolutely necessary.

3. Order categories from MOST SPECIFIC to MOST GENERIC. The first matching
   category wins, so put narrow patterns (e.g. homepage `^/?\$`) before
   broad ones (e.g. `^/products/`).

OUTPUT FORMAT — YAML, every category has four keys:
  - `dom`     : the site domain (always "{$safeDomain}" in this run).
  - `include` : a list of regex patterns. A URL belongs to the category when
                AT LEAST ONE of these patterns matches its PATH (everything
                after the domain, starting with `/`).
  - `exclude` : optional, a list of regex patterns. Even if `include` matches,
                a URL is rejected if any `exclude` pattern matches the path.
  - `color`   : a distinct hex color used by the dashboard chart.

Patterns are tested as PostgreSQL POSIX regex (`~*`, case-insensitive). Anchor
them with `^` when you mean "starts with". Use `\$` for end-of-path.

<yaml_format_example>
homepage:
  dom: {$safeDomain}
  include:
    - ^/?\$
    - ^/(fr|en)/?\$
  color: '#4ecdc4'

product:
  dom: {$safeDomain}
  include:
    - ^/p/[0-9]+
    - ^/product/[^/]+
    - ^/[a-z]+/[^/]+\.html\$
  color: '#6bd899'

category:
  dom: {$safeDomain}
  include:
    - ^/c/[^/]+
    - ^/category/[^/]+
  exclude:
    - /preview
  color: '#d8bf6b'

blog_post:
  dom: {$safeDomain}
  include:
    - ^/blog/[^/]+/?\$
  color: '#a86bd8'
</yaml_format_example>

Conventions:
- Lowercase snake_case category names.
- Patterns target the PATH (no scheme, no host).
- `dom` MUST be "{$safeDomain}" for every category.
- Distinct color per category.

Here is the sample (one JSON object per line, with url / h1 / title):

<pages_sample>
{$sampleJsonl}
</pages_sample>

Output your final YAML inside a single <categorization>...</categorization>
HTML tag. Do not output anything else outside the tag. Do not wrap the YAML
in a markdown code block.{$retryNote}
PROMPT;
    }

    /**
     * Extract the YAML payload from a model response.
     *
     * Returns the trimmed YAML string on success, or null if the tag is missing
     * or empty. Validation that the YAML actually parses is done by the caller
     * (CategorizationService) since it owns the regex compilation check.
     */
    public static function extractYaml(string $response): ?string
    {
        if (!preg_match('#<categorization>(.*?)</categorization>#s', $response, $m)) {
            return null;
        }
        $yaml = trim($m[1]);
        // Defense: some models still wrap in a fenced block despite instructions.
        if (preg_match('#^```(?:ya?ml)?\s*(.*?)\s*```$#s', $yaml, $fence)) {
            $yaml = trim($fence[1]);
        }
        return $yaml === '' ? null : $yaml;
    }
}
