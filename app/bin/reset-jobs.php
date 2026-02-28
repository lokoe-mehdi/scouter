<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database\PostgresDatabase;

echo "=== NETTOYAGE COMPLET DES JOBS ET CRAWLS ===\n\n";

$db = PostgresDatabase::getInstance()->getConnection();

// 1. Nettoyer les JOBS bloqués
echo "1. Vérification des jobs...\n";
$stmt = $db->query("SELECT id, status, project_dir FROM jobs WHERE status IN ('running', 'queued', 'stopping')");
$stuckJobs = $stmt->fetchAll(PDO::FETCH_OBJ);

if (count($stuckJobs) > 0) {
    echo "   Trouvé " . count($stuckJobs) . " job(s) bloqué(s):\n";
    foreach ($stuckJobs as $job) {
        echo "   - Job #{$job->id} ({$job->project_dir}) : {$job->status}\n";
    }
    
    // Marquer tous comme 'stopped' (cohérent avec les crawls)
    $db->exec("UPDATE jobs SET status = 'stopped' WHERE status IN ('running', 'stopping')");
    $db->exec("UPDATE jobs SET status = 'stopped' WHERE status = 'queued'");
    echo "   ✓ Jobs nettoyés (status: stopped)\n";
} else {
    echo "   ✓ Aucun job bloqué\n";
}

// 2. Nettoyer les CRAWLS bloqués
echo "\n2. Vérification des crawls...\n";
$stmt = $db->query("SELECT id, path, status FROM crawls WHERE status IN ('running', 'queued', 'stopping')");
$stuckCrawls = $stmt->fetchAll(PDO::FETCH_OBJ);

if (count($stuckCrawls) > 0) {
    echo "   Trouvé " . count($stuckCrawls) . " crawl(s) bloqué(s):\n";
    foreach ($stuckCrawls as $crawl) {
        echo "   - Crawl #{$crawl->id} ({$crawl->path}) : {$crawl->status}\n";
    }
    
    $db->exec("UPDATE crawls SET status = 'stopped', in_progress = 0 WHERE status IN ('running', 'stopping', 'queued')");
    echo "   ✓ Crawls nettoyés (status: stopped)\n";
} else {
    echo "   ✓ Aucun crawl bloqué\n";
}

// 3. Synchroniser les statuts jobs/crawls
echo "\n3. Synchronisation des statuts...\n";
$syncResult = $db->exec("
    UPDATE jobs SET status = 'stopped' 
    FROM crawls c 
    WHERE jobs.project_dir = c.path 
    AND c.status = 'stopped' 
    AND jobs.status NOT IN ('stopped', 'failed')
");
echo "   ✓ $syncResult job(s) synchronisé(s) avec crawls\n";

echo "\n=== NETTOYAGE TERMINÉ ===\n";
