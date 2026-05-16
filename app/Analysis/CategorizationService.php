<?php

namespace App\Analysis;

use PDO;

/**
 * Service de catégorisation SQL-only
 *
 * Centralise la logique de catégorisation en SQL pur,
 * sans charger les URLs en mémoire PHP.
 *
 * @package    Scouter
 * @subpackage Analysis
 * @author     Mehdi Colin
 * @version    1.0.0
 */
class CategorizationService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Parse les catégories YAML en règles structurées
     *
     * @param array $categories Catégories parsées depuis YAML
     * @return array Règles structurées [{name, domain, includes, excludes, color}]
     * @throws \InvalidArgumentException Si un pattern regex est invalide
     */
    public function parseRules(array $categories): array
    {
        $rules = [];
        foreach ($categories as $catName => $catRules) {
            if (!is_array($catRules) || !isset($catRules['dom']) || !isset($catRules['include'])) {
                continue;
            }

            $domain = $catRules['dom'];
            if (is_array($domain)) {
                continue;
            }

            $includes = is_array($catRules['include']) ? $catRules['include'] : [$catRules['include']];
            $excludes = isset($catRules['exclude'])
                ? (is_array($catRules['exclude']) ? $catRules['exclude'] : [$catRules['exclude']])
                : [];

            $color = trim($catRules['color'] ?? '#aaaaaa', '"\'');

            // Filter out non-string patterns
            $includes = array_values(array_filter($includes, 'is_string'));
            $excludes = array_values(array_filter($excludes, 'is_string'));

            if (empty($includes)) {
                continue;
            }

            // Validate regex patterns
            foreach ($includes as $pattern) {
                if (@preg_match('#' . $pattern . '#', '') === false) {
                    throw new \InvalidArgumentException("Invalid include pattern: $pattern");
                }
            }
            foreach ($excludes as $pattern) {
                if (@preg_match('#' . $pattern . '#', '') === false) {
                    throw new \InvalidArgumentException("Invalid exclude pattern: $pattern");
                }
            }

            $rules[] = [
                'name' => $catName,
                'domain' => $domain,
                'includes' => $includes,
                'excludes' => $excludes,
                'color' => $color,
            ];
        }
        return $rules;
    }

    /**
     * Teste la catégorisation en SQL pur (zéro RAM)
     *
     * @param int $crawlId ID du crawl
     * @param array $rules Règles parsées depuis parseRules()
     * @return array {stats: [{category, count}], urls: [{url, depth, code, category}]}
     */
    public function testCategorization(int $crawlId, array $rules): array
    {
        $caseWhen = $this->buildCaseWhenSql($rules);

        // Stats par catégorie via CTE
        // NB: on inclut toutes les URLs internes (external = false), y compris celles
        // bloquées par robots.txt — elles doivent être catégorisées même non crawlées.
        $sql = "
            WITH pages_with_path AS (
                SELECT url, depth, code,
                       regexp_replace(url, '^https?://[^/]+', '') AS url_path
                FROM pages
                WHERE crawl_id = :crawl_id AND external = false
            ),
            categorized AS (
                SELECT url, depth, code, {$caseWhen['sql']} AS cat_name
                FROM pages_with_path
            )
            SELECT cat_name AS category, COUNT(*) AS count
            FROM categorized
            GROUP BY cat_name
            ORDER BY count DESC
        ";

        $params = array_merge([':crawl_id' => $crawlId], $caseWhen['params']);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Échantillon de 500 URLs
        $sql = "
            WITH pages_with_path AS (
                SELECT url, depth, code,
                       regexp_replace(url, '^https?://[^/]+', '') AS url_path
                FROM pages
                WHERE crawl_id = :crawl_id AND external = false
            )
            SELECT url, depth, code, {$caseWhen['sql']} AS category
            FROM pages_with_path
            ORDER BY url
            LIMIT 500
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $urls = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return ['stats' => $stats, 'urls' => $urls];
    }

    /**
     * Applique la catégorisation : 1 UPDATE SQL par catégorie
     *
     * Crée les catégories en base et applique les règles via UPDATE SQL
     * avec regex PostgreSQL. cat_id IS NULL garantit le first-match-wins.
     *
     * @param int $crawlId ID du crawl
     * @param string $yamlContent Configuration YAML brute
     * @return int Nombre total de pages catégorisées
     */
    public function applyCategorization(int $crawlId, string $yamlContent, ?int $projectId = null): int
    {
        $categories = \Spyc::YAMLLoadString($yamlContent);
        $rules = $this->parseRules($categories);

        // Resolve projectId if not provided
        if (!$projectId) {
            $stmt = $this->db->prepare("SELECT project_id FROM crawls WHERE id = :crawl_id");
            $stmt->execute([':crawl_id' => $crawlId]);
            $projectId = (int)$stmt->fetchColumn();
        }

        if (!$projectId) {
            throw new \RuntimeException("Cannot categorize crawl $crawlId: no project_id found");
        }

        // Reset page assignments
        $this->db->prepare("UPDATE pages SET cat_id = NULL WHERE crawl_id = :crawl_id")
            ->execute([':crawl_id' => $crawlId]);

        $totalCategorized = 0;

        foreach ($rules as $rule) {
            // Upsert category at project level (stable IDs across crawls)
            $stmt = $this->db->prepare("
                INSERT INTO crawl_categories (project_id, cat, color)
                VALUES (:project_id, :cat, :color)
                ON CONFLICT (project_id, cat) DO UPDATE SET color = EXCLUDED.color
                RETURNING id
            ");
            $stmt->execute([':project_id' => $projectId, ':cat' => $rule['name'], ':color' => $rule['color']]);
            $catId = $stmt->fetch(PDO::FETCH_OBJ)->id;

            // Construire l'UPDATE SQL avec regex PostgreSQL
            // cat_id IS NULL garantit le first-match-wins (chaque UPDATE est atomique)
            $domainEscaped = preg_quote($rule['domain'], '/');
            $includePattern = implode('|', $rule['includes']);

            $sql = "
                UPDATE pages SET cat_id = :cat_id
                WHERE crawl_id = :crawl_id
                  AND cat_id IS NULL
                  AND external = false
                  AND url ~* :domain
                  AND regexp_replace(url, '^https?://[^/]+', '') ~* :include
            ";
            $params = [
                ':cat_id' => $catId,
                ':crawl_id' => $crawlId,
                ':domain' => $domainEscaped,
                ':include' => $includePattern,
            ];

            if (!empty($rule['excludes'])) {
                $excludePattern = implode('|', $rule['excludes']);
                $sql .= " AND NOT regexp_replace(url, '^https?://[^/]+', '') ~* :exclude";
                $params[':exclude'] = $excludePattern;
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $totalCategorized += $stmt->rowCount();
        }

        // Cleanup categories that are no longer in the current YAML config.
        // Without this, every renamed/removed category survives forever in the
        // crawl_categories table and pollutes the filter dropdowns in URL/Link
        // Explorer. We scope the cleanup to the whole project (categories are
        // project-level, shared across crawls) and also NULL out pages.cat_id
        // on every crawl of the project that pointed at a deleted category —
        // there is no FK so we must do it explicitly to avoid phantom cat_ids.
        $currentCatNames = array_map(fn($r) => $r['name'], $rules);
        $cleanupParams = [':project_id' => $projectId];

        if (!empty($currentCatNames)) {
            $placeholders = [];
            foreach ($currentCatNames as $i => $name) {
                $key = ':cat_keep_' . $i;
                $placeholders[] = $key;
                $cleanupParams[$key] = $name;
            }
            $keepClause = 'AND cat NOT IN (' . implode(',', $placeholders) . ')';
        } else {
            // No rule = wipe everything for this project
            $keepClause = '';
        }

        $this->db->prepare("
            UPDATE pages SET cat_id = NULL
            WHERE crawl_id IN (SELECT id FROM crawls WHERE project_id = :project_id)
              AND cat_id IN (
                  SELECT id FROM crawl_categories
                  WHERE project_id = :project_id $keepClause
              )
        ")->execute($cleanupParams);

        $this->db->prepare("
            DELETE FROM crawl_categories
            WHERE project_id = :project_id $keepClause
        ")->execute($cleanupParams);

        return $totalCategorized;
    }

    /**
     * Construit un CASE WHEN SQL dynamique pour simuler la catégorisation
     *
     * Le CASE respecte l'ordre des règles = first match wins.
     * Tous les patterns sont passés en paramètres PDO.
     *
     * @param array $rules Règles parsées
     * @return array {sql: string, params: array}
     */
    private function buildCaseWhenSql(array $rules): array
    {
        $params = [];
        $cases = [];

        foreach ($rules as $i => $rule) {
            $domParam = ":dom_$i";
            $includeParam = ":inc_$i";
            $nameParam = ":catname_$i";

            // Domain: échapper pour match littéral en regex POSIX
            $params[$domParam] = preg_quote($rule['domain'], '/');
            $params[$includeParam] = implode('|', $rule['includes']);
            $params[$nameParam] = $rule['name'];

            $condition = "url ~* {$domParam} AND url_path ~* {$includeParam}";

            if (!empty($rule['excludes'])) {
                $excludeParam = ":exc_$i";
                $params[$excludeParam] = implode('|', $rule['excludes']);
                $condition .= " AND NOT url_path ~* {$excludeParam}";
            }

            $cases[] = "WHEN {$condition} THEN {$nameParam}";
        }

        if (empty($cases)) {
            return ['sql' => "NULL", 'params' => []];
        }

        $sql = "CASE " . implode(" ", $cases) . " ELSE NULL END";

        return ['sql' => $sql, 'params' => $params];
    }
}
