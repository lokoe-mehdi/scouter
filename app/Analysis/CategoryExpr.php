<?php

namespace App\Analysis;

use PDO;

/**
 * Builds the LIVE categorization expression for ClickHouse.
 *
 * Categorization is no longer stored (no cat_id). Instead, the project's YAML
 * rules are turned into a ClickHouse `CASE WHEN … END` expression evaluated at
 * query time, producing a `category` column. This is the CH counterpart of
 * {@see CategorizationService::buildCaseWhenSql()} (which targets PostgreSQL):
 *
 *   - PG `url ~* :p`  ->  CH `match(url, '(?i)p')`   (RE2, case-insensitive)
 *   - PG `regexp_replace(url, '^https?://[^/]+', '')` (the path) is inlined.
 *
 * Patterns come from the project owner's own YAML and only ever match their own
 * crawl, but we still escape single quotes so the inlined literal is safe.
 *
 * RE2 caveat: backreferences / lookarounds are unsupported (warn in the editor).
 *
 * @package    Scouter
 * @subpackage Analysis
 */
class CategoryExpr
{
    private PDO $pg;

    public function __construct(PDO $pg)
    {
        $this->pg = $pg;
    }

    /**
     * Return the ClickHouse `category` expression for a crawl, using the YAML
     * active for that crawl (categorization_config) or the project default.
     * Returns "''" (empty string literal) when the crawl has no rules.
     */
    public function forCrawl(int $crawlId): string
    {
        $yaml = $this->loadYaml($crawlId);
        if ($yaml === null || trim($yaml) === '') {
            return "''";
        }
        try {
            $categories = \Spyc::YAMLLoadString($yaml);
            if (!is_array($categories)) {
                return "''";
            }
            $rules = (new CategorizationService($this->pg))->parseRules($categories);
        } catch (\Throwable $e) {
            return "''";
        }
        return $this->build($rules);
    }

    /**
     * Parsed rules for a crawl (YAML → [{name,domain,includes,excludes,color}]),
     * or [] when none. Reused to build the live category expr AND the synthetic
     * cat_id map the reports expect.
     */
    public function rulesForCrawl(int $crawlId): array
    {
        $yaml = $this->loadYaml($crawlId);
        if ($yaml === null || trim($yaml) === '') {
            return [];
        }
        try {
            $categories = \Spyc::YAMLLoadString($yaml);
            if (!is_array($categories)) {
                return [];
            }
            return (new CategorizationService($this->pg))->parseRules($categories);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Live synthetic `cat_id` expression: the 1-based rule index of the first
     * matching rule (NULL if none). Matches the ids in the $categoriesMap the
     * dashboard builds from the same rules, so reports that GROUP BY cat_id and
     * look up $categoriesMap[cat_id] keep working unchanged on ClickHouse.
     */
    public function buildIdExpr(array $rules): string
    {
        $cases = [];
        foreach ($rules as $i => $rule) {
            $cases[] = "WHEN {$this->cond($rule)} THEN " . ($i + 1);
        }
        if (empty($cases)) {
            return "NULL";
        }
        return "CASE " . implode(' ', $cases) . " ELSE NULL END";
    }

    /** The match condition for one rule (domain + includes [- excludes]). */
    private function cond(array $rule): string
    {
        $dom = $this->lit('(?i)' . preg_quote($rule['domain'], '/'));
        $inc = $this->lit('(?i)' . implode('|', $rule['includes']));
        $c = "match(url, {$dom}) AND match(replaceRegexpOne(url, '^https?://[^/]+', ''), {$inc})";
        if (!empty($rule['excludes'])) {
            $exc = $this->lit('(?i)' . implode('|', $rule['excludes']));
            $c .= " AND NOT match(replaceRegexpOne(url, '^https?://[^/]+', ''), {$exc})";
        }
        return $c;
    }

    /** Build the CASE WHEN from parsed rules (first match wins, like PG). */
    public function build(array $rules): string
    {
        $cases = [];
        foreach ($rules as $rule) {
            $dom = $this->lit('(?i)' . preg_quote($rule['domain'], '/'));
            $inc = $this->lit('(?i)' . implode('|', $rule['includes']));
            $name = $this->lit($rule['name']);

            $cond = "match(url, {$dom}) AND match(replaceRegexpOne(url, '^https?://[^/]+', ''), {$inc})";
            if (!empty($rule['excludes'])) {
                $exc = $this->lit('(?i)' . implode('|', $rule['excludes']));
                $cond .= " AND NOT match(replaceRegexpOne(url, '^https?://[^/]+', ''), {$exc})";
            }
            $cases[] = "WHEN {$cond} THEN {$name}";
        }
        if (empty($cases)) {
            return "''";
        }
        return "CASE " . implode(' ', $cases) . " ELSE '' END";
    }

    /** Escape a value as a ClickHouse single-quoted string literal. */
    private function lit(string $s): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $s) . "'";
    }

    /** Load the YAML for a crawl: per-crawl snapshot, else project default. */
    private function loadYaml(int $crawlId): ?string
    {
        $stmt = $this->pg->prepare("SELECT config FROM categorization_config WHERE crawl_id = :cid");
        $stmt->execute([':cid' => $crawlId]);
        $config = $stmt->fetchColumn();
        if ($config) {
            return (string) $config;
        }

        $stmt = $this->pg->prepare("
            SELECT p.categorization_config
            FROM crawls c JOIN projects p ON p.id = c.project_id
            WHERE c.id = :cid
        ");
        $stmt->execute([':cid' => $crawlId]);
        $config = $stmt->fetchColumn();
        return $config ? (string) $config : null;
    }
}
