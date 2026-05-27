<?php

namespace App\Analysis;

use App\Database\ClickHouseDatabase;
use App\Database\PostgresDatabase;

/**
 * Stats de crawl dérivées de ClickHouse, calculées UNE fois et persistées dans
 * la ligne `crawls` (write-through). Une seule requête CH batchée produit :
 *   - compliant        (pages indexables)
 *   - critical_errors  (4xx/5xx sur pages crawlées)
 *   - health_score     (score santé SEO 5 piliers : indexabilité, on-page title+h1
 *                       uniques, contenu non-thin, distribution PageRank, profondeur)
 *
 * Sentinelle : `crawls.health_score IS NULL` = pas encore calculé. Une fois posé
 * (non-NULL), la home et la page projet lisent simplement la ligne `crawls` →
 * ZÉRO requête ClickHouse au rendu.
 *
 * Déclenché :
 *   (1) au post-crawl, via le job `precompute-reports:<id>` (scouter.php) → tout
 *       nouveau crawl a ses 3 stats stockées d'office ;
 *   (2) paresseusement, à la 1re consultation d'un ancien crawl (index.php /
 *       project.php) → calcul live une seule fois + write-through, puis instantané.
 *
 * Le score est IMMUABLE post-crawl (indexabilité / on-page / contenu / pagerank /
 * profondeur — indépendant des catégories) → stockage permanent sûr, jamais
 * invalidé à l'édition des catégories.
 */
class CrawlStats
{
    /**
     * Calcule compliant + critical_errors + health_score en ClickHouse pour les
     * crawls donnés, persiste dans `crawls`, et retourne
     * [crawl_id => ['compliant'=>int, 'critical_errors'=>int, 'health_score'=>int]].
     * Seuls les crawls réellement présents dans ClickHouse sont renvoyés/persistés
     * (les autres → absents : l'appelant retombe sur pcHealthScore). Retourne []
     * si ClickHouse est désactivé.
     */
    public static function ensureFromClickHouse(array $crawlIds): array
    {
        $crawlIds = array_values(array_unique(array_filter(array_map('intval', $crawlIds))));
        if (empty($crawlIds) || !ClickHouseDatabase::enabled()) {
            return [];
        }
        $idList = implode(',', $crawlIds);

        // pages est un ReplacingMergeTree → on déduplique (LIMIT 1 BY crawl_id,id)
        // AVANT d'agréger. title_status/h1_status/pri vivent dans page_metrics.
        // Score 5 piliers + compliant (indexables) + critical_errors dans la même
        // passe. Repli pur-PHP (sans CH) : pcHealthScore() dans project-metrics.php.
        $sql = "
WITH per AS (
  SELECT crawl_id, countIf(compliant=1 AND is_html=1) AS idx
  FROM (SELECT crawl_id, id, compliant, is_html FROM pages WHERE crawl_id IN ($idList)
        ORDER BY date DESC LIMIT 1 BY crawl_id, id)
  GROUP BY crawl_id
),
thr AS (SELECT crawl_id, greatest(ceil(log((idx + 24) / 25.0) / log(5.0)), 1) AS max_depth FROM per)
SELECT p.crawl_id AS crawl_id,
  countIf(p.compliant=1) AS compliant,
  countIf(p.code >= 400 AND p.crawled=1) AS critical_errors,
  round((
      countIf(p.compliant=1 AND p.is_html=1)*100.0 / nullIf(countIf(p.crawled=1 AND p.is_html=1),0)
    + countIf(m.title_status='unique' AND m.h1_status='unique' AND p.compliant=1 AND p.is_html=1)*100.0 / nullIf(countIf(p.crawled=1 AND p.is_html=1),0)
    + countIf(p.compliant=1 AND p.is_html=1 AND p.word_count>500)*100.0 / nullIf(countIf(p.compliant=1 AND p.is_html=1),0)
    + sumIf(m.pri, p.compliant=1 AND p.is_html=1)*100.0 / nullIf(sum(m.pri),0)
    + countIf(p.compliant=1 AND p.is_html=1 AND p.depth <= t.max_depth)*100.0 / nullIf(countIf(p.compliant=1 AND p.is_html=1),0)
  ) / 5) AS score
FROM (SELECT crawl_id, id, compliant, is_html, crawled, word_count, depth, code FROM pages WHERE crawl_id IN ($idList)
      ORDER BY date DESC LIMIT 1 BY crawl_id, id) p
LEFT JOIN page_metrics m ON m.crawl_id = p.crawl_id AND m.id = p.id
JOIN thr t ON t.crawl_id = p.crawl_id
GROUP BY p.crawl_id, t.max_depth";

        try {
            $rows = ClickHouseDatabase::getInstance()->select($sql);
        } catch (\Throwable $e) {
            error_log('[CrawlStats] CH query failed: ' . $e->getMessage());
            return [];
        }
        if (empty($rows)) {
            return [];
        }

        $out = [];
        try {
            $pdo = PostgresDatabase::getInstance()->getConnection();
            $upd = $pdo->prepare(
                "UPDATE crawls SET compliant = :compliant, critical_errors = :crit, health_score = :hs WHERE id = :id"
            );
            foreach ($rows as $r) {
                $id = (int) ($r['crawl_id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $score = (($r['score'] ?? null) !== null && $r['score'] !== '')
                    ? (int) round(max(0, min(100, (float) $r['score'])))
                    : 0;
                $compliant = (int) ($r['compliant'] ?? 0);
                $crit = (int) ($r['critical_errors'] ?? 0);
                $upd->execute([':compliant' => $compliant, ':crit' => $crit, ':hs' => $score, ':id' => $id]);
                $out[$id] = ['compliant' => $compliant, 'critical_errors' => $crit, 'health_score' => $score];
            }
        } catch (\Throwable $e) {
            error_log('[CrawlStats] persist failed: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * Fige un health_score déjà calculé (ex. repli pcHealthScore pour un crawl
     * ABSENT de ClickHouse) afin de poser la sentinelle → plus de recalcul à
     * chaque consultation. N'écrase pas une valeur déjà posée.
     */
    public static function persistHealthScore(int $crawlId, int $score): void
    {
        if ($crawlId <= 0) {
            return;
        }
        try {
            $pdo = PostgresDatabase::getInstance()->getConnection();
            $stmt = $pdo->prepare("UPDATE crawls SET health_score = :hs WHERE id = :id AND health_score IS NULL");
            $stmt->execute([':hs' => (int) max(0, min(100, $score)), ':id' => $crawlId]);
        } catch (\Throwable $e) {
            error_log('[CrawlStats] persistHealthScore failed: ' . $e->getMessage());
        }
    }
}
