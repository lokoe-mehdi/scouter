<?php

namespace App\Database;

use App\Analysis\CategoryExpr;
use PDO;

/**
 * PostgreSQL report shim — the PG counterpart of {@see ChPdo}.
 *
 * Categorization is computed LIVE everywhere now (no stored cat_id). On PG, this
 * decorator wraps each `FROM/JOIN pages[_<id>]` into a subquery that adds a live
 * `category` column (the project's YAML rules as a PG `CASE WHEN … ~* …`), so the
 * report pages can `GROUP BY category` identically to ClickHouse. Everything else
 * is delegated to the real PDO (native prepare/execute/fetch), so PG reports keep
 * working byte-for-byte apart from the injected category.
 *
 * @package    Scouter
 * @subpackage Database
 */
class PgReportPdo
{
    private PDO $pdo;
    private array $crawlIds;
    /** @var array<int,string> per-crawl live category CASE WHEN cache */
    private array $catCache = [];

    public function __construct(int $crawlId, ?int $compareId = null)
    {
        $this->pdo = PostgresDatabase::getInstance()->getConnection();
        $this->crawlIds = $compareId ? [$crawlId, $compareId] : [$crawlId];
    }

    public function prepare(string $sql, array $options = [])
    {
        return $this->pdo->prepare($this->rewrite($sql), $options);
    }

    public function query(string $sql)
    {
        return $this->pdo->query($this->rewrite($sql));
    }

    public function exec(string $sql)
    {
        return $this->pdo->exec($sql);
    }

    public function quote(string $value, int $type = PDO::PARAM_STR): string
    {
        return $this->pdo->quote($value, $type);
    }

    public function __call($name, $args)
    {
        return $this->pdo->{$name}(...$args);
    }

    private function catExpr(int $id): string
    {
        if (!isset($this->catCache[$id])) {
            $this->catCache[$id] = (new CategoryExpr($this->pdo))->forCrawlPg($id);
        }
        return $this->catCache[$id];
    }

    private function pagesSource(array $ids, int $rulesId): string
    {
        $in = implode(',', array_map('intval', $ids));
        return "(SELECT p.*, (" . $this->catExpr($rulesId) . ") AS category FROM pages p WHERE crawl_id IN ({$in}))";
    }

    private const NOT_ALIAS = [
        'where','group','order','on','using','join','left','right','inner','cross','full',
        'union','limit','offset','having','and','or','as','set','returning','window',
    ];

    private function rewrite(string $sql): string
    {
        // Normalise the SQL-Explorer multi-crawl syntax pages@<id> to pages_<id>
        // (the real PG partition) so queries hit the partitioned tables directly.
        $sql = preg_replace('/\bpages@(\d+)\b/i', 'pages_$1', $sql);

        // PERF: only inject the live `category` CASE WHEN (a regex ~* over every
        // row) when the query actually references `category`. Otherwise leave the
        // SQL untouched → native PG speed (partition pruning, no per-row regex).
        if (!preg_match('/\bcategory\b/i', $sql)) {
            return $sql;
        }
        // pages_<id> (explicit partition, comparison) → that crawl's source.
        $sql = preg_replace_callback(
            '/\b(FROM|JOIN)\s+pages_(\d+)\b(\s+(?:AS\s+)?([a-zA-Z_][a-zA-Z0-9_]*))?/i',
            function ($m) {
                $id = (int) $m[2];
                return $m[1] . ' ' . $this->pagesSource([$id], $id) . ' AS ' . $this->alias($m[4] ?? '', 'pages_' . $id, $m[3] ?? '');
            },
            $sql
        );
        // bare pages → the crawl set (current [+ compare]).
        $sql = preg_replace_callback(
            '/\b(FROM|JOIN)\s+pages\b(\s+(?:AS\s+)?([a-zA-Z_][a-zA-Z0-9_]*))?/i',
            fn($m) => $m[1] . ' ' . $this->pagesSource($this->crawlIds, $this->crawlIds[0]) . ' AS ' . $this->alias($m[3] ?? '', 'pages', $m[2] ?? ''),
            $sql
        );
        return $sql;
    }

    private function alias(string $captured, string $name, string $tail): string
    {
        if ($captured !== '' && !in_array(strtolower($captured), self::NOT_ALIAS, true)) {
            return $captured;
        }
        return $name . $tail;
    }
}
