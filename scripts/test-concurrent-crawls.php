<?php
/**
 * Script de test: Lance N crawls simultanés sur un domaine
 * Usage: php scripts/test-concurrent-crawls.php [nb_crawls] [url]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Database\PostgresDatabase;
use App\Job\JobManager;
use App\Database\CrawlRepository;

$nbCrawls = (int)($argv[1] ?? 10);
$baseUrl = $argv[2] ?? 'https://lokoe.fr';

echo "===========================================\n";
echo "  TEST: $nbCrawls crawls simultanés\n";
echo "  URL: $baseUrl\n";
echo "===========================================\n\n";

$pdo = PostgresDatabase::getInstance()->getConnection();
$jobManager = new JobManager();
$crawlRepo = new CrawlRepository();

// Récupérer le premier user et projet
$stmt = $pdo->query("SELECT id FROM users ORDER BY id LIMIT 1");
$user = $stmt->fetch();
if (!$user) {
    die("Erreur: Aucun utilisateur trouvé\n");
}

$stmt = $pdo->query("SELECT id FROM projects WHERE user_id = {$user->id} ORDER BY id LIMIT 1");
$project = $stmt->fetch();
if (!$project) {
    die("Erreur: Aucun projet trouvé\n");
}

echo "User ID: {$user->id}, Project ID: {$project->id}\n\n";

$domain = parse_url($baseUrl, PHP_URL_HOST);

for ($i = 1; $i <= $nbCrawls; $i++) {
    $timestamp = date('Y-m-d-H-i-s') . "-$i";
    $path = "{$domain}/{$timestamp}";
    
    // Créer le crawl
    $stmt = $pdo->prepare("
        INSERT INTO crawls (project_id, url, domain, path, status, depth, created_at)
        VALUES (:project_id, :url, :domain, :path, 'queued', 3, NOW())
        RETURNING id
    ");
    $stmt->execute([
        ':project_id' => $project->id,
        ':url' => $baseUrl,
        ':domain' => $domain,
        ':path' => $path
    ]);
    $crawlId = $stmt->fetchColumn();
    
    // Créer le job
    $jobId = $jobManager->createJob($path, 'crawl');
    
    echo "[$i/$nbCrawls] Crawl #$crawlId, Job #$jobId créé ($path)\n";
}

echo "\n✓ $nbCrawls crawls ajoutés à la queue!\n";
echo "Les workers vont les traiter automatiquement.\n";
