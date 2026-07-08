<?php

namespace App\Gsc;

use App\Analysis\CategorizationService;
use App\Database\PostgresDatabase;
use PDO;

/**
 * Reuses the project's URL categorization rules (the same YAML that powers the
 * crawl "category" column) as a filter for Search Console data.
 *
 * The rules are URL regexes, and GSC rows carry the URL in the `page` column, so
 * we compile each category to a ClickHouse match() condition on `page` — exactly
 * like App\Analysis\CategoryExpr does on `url`. This lets the Search Analytics
 * filter bar offer a "category" field bound to the categories already defined at
 * the project level.
 *
 * Category ids are 1-based rule indices (stable within a parse), matching what
 * the FilterBar sends back.
 *
 * @package    Scouter
 * @subpackage Gsc
 */
class GscCategories
{
    /** @var array<int,array<int,array<string,mixed>>> projectId => parsed rules (cache) */
    private static array $cache = [];

    /**
     * Parsed rules for a project's categorization config, or [] when none.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function rules(int $projectId): array
    {
        if (isset(self::$cache[$projectId])) {
            return self::$cache[$projectId];
        }
        $rules = [];
        try {
            $pg = PostgresDatabase::getInstance()->getConnection();
            $stmt = $pg->prepare("SELECT categorization_config FROM projects WHERE id = :id");
            $stmt->execute([':id' => $projectId]);
            $yaml = $stmt->fetchColumn();
            if ($yaml && trim((string) $yaml) !== '') {
                $categories = \Spyc::YAMLLoadString((string) $yaml);
                if (is_array($categories)) {
                    $rules = (new CategorizationService($pg))->parseRules($categories);
                }
            }
        } catch (\Throwable $e) {
            $rules = [];
        }
        return self::$cache[$projectId] = $rules;
    }

    /**
     * UI list of categories for the filter bar + badges: [{id, cat, color}]
     * (1-based ids).
     *
     * @return array<int,array{id:int,cat:string,color:string}>
     */
    public static function list(int $projectId): array
    {
        $out = [];
        foreach (self::rules($projectId) as $i => $rule) {
            $out[] = [
                'id'    => $i + 1,
                'cat'   => (string) ($rule['name'] ?? ('#' . ($i + 1))),
                'color' => (string) ($rule['color'] ?? '#95a5a6'),
            ];
        }
        return $out;
    }

    /**
     * ClickHouse `category` CASE WHEN expression (first match wins) on $col,
     * producing the category NAME (or '' when none). Mirrors CategoryExpr::build
     * but on the GSC `page` column. Returns "''" when the project has no rules.
     */
    public static function caseExpr(int $projectId, string $col = 'page'): string
    {
        $cases = [];
        foreach (self::rules($projectId) as $rule) {
            $name = self::lit((string) ($rule['name'] ?? ''));
            $cases[] = "WHEN " . self::cond($rule, $col) . " THEN {$name}";
        }
        return empty($cases) ? "''" : "CASE " . implode(' ', $cases) . " ELSE '' END";
    }

    public static function hasRules(int $projectId): bool
    {
        return !empty(self::rules($projectId));
    }

    /**
     * ClickHouse match() conditions per category id, evaluated on $col (the URL
     * column — `page` for GSC). Server-built + escaped → safe to inline.
     *
     * @return array<int,string> id (1-based) => SQL boolean condition
     */
    public static function conditions(int $projectId, string $col = 'page'): array
    {
        $conds = [];
        foreach (self::rules($projectId) as $i => $rule) {
            $conds[$i + 1] = self::cond($rule, $col);
        }
        return $conds;
    }

    /** The match condition for one rule (domain + includes [- excludes]) on $col. */
    private static function cond(array $rule, string $col): string
    {
        $dom = self::lit('(?i)' . preg_quote((string) ($rule['domain'] ?? ''), '/'));
        $inc = self::lit('(?i)' . implode('|', (array) ($rule['includes'] ?? [])));
        $path = "replaceRegexpOne({$col}, '^https?://[^/]+', '')";
        $c = "match({$col}, {$dom}) AND match({$path}, {$inc})";
        if (!empty($rule['excludes'])) {
            $exc = self::lit('(?i)' . implode('|', (array) $rule['excludes']));
            $c .= " AND NOT match({$path}, {$exc})";
        }
        return "({$c})";
    }

    /** Escape a value as a ClickHouse single-quoted string literal (like CategoryExpr). */
    private static function lit(string $s): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $s) . "'";
    }
}
