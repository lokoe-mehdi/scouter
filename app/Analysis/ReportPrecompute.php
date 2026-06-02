<?php

namespace App\Analysis;

use App\Database\ChPdo;
use App\Database\CrawlStore;
use App\Database\PgReportPdo;
use App\Database\PostgresDatabase;
use PDO;

/**
 * Précalcul générique des fragments de rapport lourds (table crawl_report_cache).
 *
 * Beaucoup de widgets reposent sur des agrégations qui scannent / joignent les
 * grosses tables (`pages`, `links`) — coûteuses à refaire en live à CHAQUE
 * affichage sur un gros crawl. Leur RÉSULTAT est minuscule (quelques lignes), donc
 * on le stocke une fois et on le relit.
 *
 * Usage côté rapport (une ligne) :
 *
 *     $rows = ReportPrecompute::cached($crawlId, 'codes_by_category', $pdo, $sql, $params, true);
 *
 * `cached()` lit le cache si présent, sinon exécute la requête avec le $pdo de
 * rapport (ChPdo/PgReportPdo), STOCKE le résultat ET la requête (SQL + params), puis
 * renvoie les lignes. Comme la requête est stockée, le worker peut tout RECALCULER
 * sans registre central — donc chaque rapport branche ses requêtes indépendamment,
 * sans toucher à ce fichier.
 *
 * Cycle de fraîcheur (AUCUN précalcul n'est lancé à l'ouverture d'un dashboard) :
 *   - 1er affichage d'un rapport dont un fragment n'est pas en cache → calcul live
 *     (lent une fois) + stockage (lazy-warm via cached()). Ensuite : lecture pure,
 *     aucun recalcul. Plus besoin de re-sauvegarder la segmentation.
 *   - save de catégorisation → recompute(onlyCategoryDependent=true) réécrit les
 *     fragments marqués `categoryDependent` (les autres ne dépendent pas des catégs).
 *   - fin de crawl → recompute() réécrit les fragments déjà stockés.
 *
 * @package    Scouter
 * @subpackage Analysis
 */
class ReportPrecompute
{
    /** Garde-fou : la table a-t-elle été vérifiée/créée dans ce process. */
    private static bool $tableReady = false;

    /**
     * Lit un fragment depuis le cache, sinon l'exécute (lazy-warm) + le stocke.
     *
     * @param int    $crawlId          crawl concerné
     * @param string $key              identifiant stable du fragment (unique par rapport)
     * @param mixed  $pdo              ChPdo/PgReportPdo de rapport (déjà construit)
     * @param string $sql              requête SQL (style PG, réécrite par le shim)
     * @param array  $params           params bindés (ex: [':crawl_id' => $crawlId])
     * @param bool   $categoryDependent true si le résultat dépend des catégories
     *                                  (GROUP BY category, etc.) → recalculé au save catégo
     * @return array<int,object> lignes (stdClass), comme PDO::FETCH_OBJ
     */
    public static function cached(int $crawlId, string $key, $pdo, string $sql, array $params = [], bool $categoryDependent = false): array
    {
        $hit = self::read($crawlId, $key);
        if ($hit !== null) {
            return $hit;
        }
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_OBJ);
        } catch (\Throwable $e) {
            error_log("[ReportPrecompute] cached {$key} crawl {$crawlId} failed: " . $e->getMessage());
            return [];
        }
        self::write($crawlId, $key, $rows, $sql, $params, $categoryDependent);
        return $rows;
    }

    /**
     * Recalcule et RÉÉCRIT les fragments STOCKÉS d'un crawl (worker / fin de crawl /
     * save catégo). Re-exécute la requête mémorisée de chaque entrée — pas de registre
     * central. Met à jour sur place (ne vide jamais), pour que le rapport lise toujours
     * une table à jour sans retomber sur du live.
     *
     * @param bool $onlyCategoryDependent ne recalcule que les fragments marqués
     *             categoryDependent (cas d'un changement de catégorisation).
     */
    public static function recompute(int $crawlId, bool $onlyCategoryDependent = false): void
    {
        self::ensureTable();
        try {
            $pg = PostgresDatabase::getInstance()->getConnection();
            $q = "SELECT report_key, query_sql, query_params, category_dependent
                  FROM crawl_report_cache WHERE crawl_id = :c AND query_sql IS NOT NULL";
            if ($onlyCategoryDependent) {
                $q .= " AND category_dependent = 1";
            }
            $stmt = $pg->prepare($q);
            $stmt->execute([':c' => $crawlId]);
            $entries = $stmt->fetchAll(PDO::FETCH_OBJ);
        } catch (\Throwable $e) {
            error_log("[ReportPrecompute] recompute list crawl {$crawlId} failed: " . $e->getMessage());
            return;
        }
        if (empty($entries)) {
            return;
        }
        $pdo = self::reportPdo($crawlId);
        foreach ($entries as $entry) {
            $params = [];
            if (!empty($entry->query_params)) {
                $decoded = json_decode((string) $entry->query_params, true);
                if (is_array($decoded)) {
                    $params = $decoded;
                }
            }
            $catDep = ((int) $entry->category_dependent === 1);
            try {
                $stmt = $pdo->prepare($entry->query_sql);
                $stmt->execute($params);
                self::write($crawlId, $entry->report_key, $stmt->fetchAll(PDO::FETCH_OBJ), $entry->query_sql, $params, $catDep);
            } catch (\Throwable $e) {
                error_log("[ReportPrecompute] recompute {$entry->report_key} crawl {$crawlId} failed: " . $e->getMessage());
            }
        }
    }

    /** Recalcule tous les crawls TERMINÉS d'un projet (après save catégorisation). */
    public static function recomputeProject(int $projectId, bool $onlyCategoryDependent = false): void
    {
        $pg = PostgresDatabase::getInstance()->getConnection();
        $stmt = $pg->prepare("SELECT id FROM crawls WHERE project_id = :p AND status IN ('finished','stopped','error')");
        $stmt->execute([':p' => $projectId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $cid) {
            self::recompute((int) $cid, $onlyCategoryDependent);
        }
    }

    // -- internals -----------------------------------------------------------

    /** Le shim de rapport adapté au store du crawl (CH vs PG). */
    private static function reportPdo(int $crawlId)
    {
        return CrawlStore::usesClickHouse($crawlId)
            ? new ChPdo($crawlId)
            : new PgReportPdo($crawlId);
    }

    /**
     * S'assure que la table + ses colonnes existent (auto-réparation, façon
     * JobManager::ensureTables) : même si la migration n'a pas tourné, le cache marche.
     * Idempotent, une fois par process.
     */
    private static function ensureTable(): void
    {
        if (self::$tableReady) {
            return;
        }
        // Les NOTICE PostgreSQL ("already exists, skipping") peuvent polluer la sortie
        // HTML quand display_errors est On — on les masque le temps du DDL.
        $prev = error_reporting();
        error_reporting($prev & ~E_NOTICE & ~E_WARNING);
        try {
            $pg = PostgresDatabase::getInstance()->getConnection();
            $pg->exec("
                CREATE TABLE IF NOT EXISTS crawl_report_cache (
                    crawl_id   INTEGER NOT NULL REFERENCES crawls(id) ON DELETE CASCADE,
                    report_key TEXT NOT NULL,
                    payload    JSONB NOT NULL,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (crawl_id, report_key)
                )
            ");
            // Colonnes ajoutées pour le recompute générique (re-exécution sans registre).
            $pg->exec("ALTER TABLE crawl_report_cache ADD COLUMN IF NOT EXISTS query_sql TEXT");
            $pg->exec("ALTER TABLE crawl_report_cache ADD COLUMN IF NOT EXISTS query_params JSONB");
            $pg->exec("ALTER TABLE crawl_report_cache ADD COLUMN IF NOT EXISTS category_dependent SMALLINT DEFAULT 0");
            self::$tableReady = true;
        } catch (\Throwable $e) {
            error_log('[ReportPrecompute] ensureTable failed: ' . $e->getMessage());
        } finally {
            error_reporting($prev);
        }
    }

    /** @return array<int,object>|null null = entrée absente (→ lazy compute). */
    private static function read(int $crawlId, string $key): ?array
    {
        self::ensureTable();
        try {
            $pg = PostgresDatabase::getInstance()->getConnection();
            $stmt = $pg->prepare("SELECT payload FROM crawl_report_cache WHERE crawl_id = :c AND report_key = :k");
            $stmt->execute([':c' => $crawlId, ':k' => $key]);
            $raw = $stmt->fetchColumn();
            if ($raw === false || $raw === null) {
                return null;
            }
            $decoded = json_decode((string) $raw); // objets (stdClass), comme FETCH_OBJ
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            return null; // table absente / erreur → miss, le rapport calcule en live
        }
    }

    /**
     * Upsert d'un fragment + sa requête (pour permettre le recompute).
     *
     * @param array<int,object|array> $rows
     */
    private static function write(int $crawlId, string $key, array $rows, ?string $sql = null, array $params = [], bool $categoryDependent = false): void
    {
        self::ensureTable();
        try {
            $pg = PostgresDatabase::getInstance()->getConnection();
            $stmt = $pg->prepare("
                INSERT INTO crawl_report_cache (crawl_id, report_key, payload, query_sql, query_params, category_dependent, updated_at)
                VALUES (:c, :k, :p, :sql, :prm, :cd, CURRENT_TIMESTAMP)
                ON CONFLICT (crawl_id, report_key) DO UPDATE SET
                    payload = :p2, query_sql = :sql2, query_params = :prm2,
                    category_dependent = :cd2, updated_at = CURRENT_TIMESTAMP
            ");
            $json = json_encode(array_values($rows), JSON_UNESCAPED_UNICODE);
            $prm  = json_encode((object) $params, JSON_UNESCAPED_UNICODE);
            $cd   = $categoryDependent ? 1 : 0;
            $stmt->execute([
                ':c' => $crawlId, ':k' => $key, ':p' => $json, ':sql' => $sql, ':prm' => $prm, ':cd' => $cd,
                ':p2' => $json, ':sql2' => $sql, ':prm2' => $prm, ':cd2' => $cd,
            ]);
        } catch (\Throwable $e) {
            error_log("[ReportPrecompute] write {$key} crawl {$crawlId} failed: " . $e->getMessage());
        }
    }
}
