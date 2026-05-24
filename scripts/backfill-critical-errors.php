<?php
/**
 * Backfill crawls.critical_errors (pages 4xx+5xx) pour les crawls existants.
 *
 * Pourquoi : le KPI "Erreurs critiques" de la homepage lit crawls.critical_errors.
 * Pour un crawl `data_store=clickhouse`, les pages PG ont été purgées → un
 * backfill basé sur PG renvoie 0. La vérité est dans ClickHouse, donc on compte
 * là-bas. Les crawls encore en PG sont comptés sur PG.
 *
 * Idempotent. À lancer une fois après déploiement :
 *   docker exec scouter-scouter-1 php /app/scripts/backfill-critical-errors.php
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
require '/app/vendor/autoload.php';

use App\Database\PostgresDatabase;
use App\Database\ClickHouseDatabase;

$pdo = PostgresDatabase::getInstance()->getConnection();

// Colonne au cas où la migration n'a pas tourné (idempotent).
$pdo->exec("ALTER TABLE crawls ADD COLUMN IF NOT EXISTS critical_errors INTEGER DEFAULT 0");

$chOn = ClickHouseDatabase::enabled();
$ch   = $chOn ? ClickHouseDatabase::getInstance() : null;

$rows = $pdo->query("SELECT id, data_store, COALESCE(urls,0) AS urls FROM crawls WHERE status != 'deleting' ORDER BY id")
            ->fetchAll(PDO::FETCH_ASSOC);

$upd = $pdo->prepare("UPDATE crawls SET critical_errors = :n WHERE id = :id");
$done = 0; $errors = 0;

foreach ($rows as $r) {
    $id = (int)$r['id'];
    if ((int)$r['urls'] === 0) { continue; }   // crawl vide / en échec
    $store = $r['data_store'] ?? 'pg';
    $n = null;

    try {
        if ($store === 'clickhouse' && $ch) {
            // ReplacingMergeTree → dédup par id.
            $n = (int)$ch->selectValue(
                "SELECT countDistinct(id) FROM pages WHERE crawl_id = {cid:Int32} AND code >= 400 AND crawled = 1",
                ['cid' => [$id, 'Int32']]
            );
        } else {
            $st = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE crawl_id = :id AND code >= 400 AND crawled = TRUE AND in_crawl = TRUE");
            $st->execute([':id' => $id]);
            $n = (int)$st->fetchColumn();
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "crawl #$id ($store): " . $e->getMessage() . "\n");
        $errors++;
        continue;
    }

    $upd->execute([':n' => $n, ':id' => $id]);
    $done++;
    echo "crawl #$id ($store): critical_errors = $n\n";
}

echo "\nDone. Updated $done crawls" . ($errors ? ", $errors errors" : "") . ".\n";
