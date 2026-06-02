<?php
/**
 * Parity harness — PHP side.
 *
 * Runs the PHP categorizer (App\Analysis\CategorizationService) on one crawl,
 * exactly as PostProcessor::categorize does: read the project's
 * categorization_config (YAML) and apply it. Used by the Go parity test
 * (crawler-go/internal/postprocess/parity_test.go) to prove the Go and PHP
 * categorizers assign identical categories.
 *
 * Usage:  PARITY_DSN=... PARITY_USER=... PARITY_PASS=... php php_categorize.php <crawl_id>
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Analysis\CategorizationService;

$crawlId = (int)($argv[1] ?? 0);
if ($crawlId <= 0) {
    fwrite(STDERR, "usage: php php_categorize.php <crawl_id>\n");
    exit(2);
}

$dsn  = getenv('PARITY_DSN')  ?: 'pgsql:host=127.0.0.1;port=55432;dbname=scouter';
$user = getenv('PARITY_USER') ?: 'scouter';
$pass = getenv('PARITY_PASS') ?: 'test';

$pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Mirror PostProcessor::categorize: project-level config wins.
$stmt = $pdo->prepare("SELECT project_id FROM crawls WHERE id = :id");
$stmt->execute([':id' => $crawlId]);
$projectId = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT categorization_config FROM projects WHERE id = :id");
$stmt->execute([':id' => $projectId]);
$yaml = (string)$stmt->fetchColumn();

if ($yaml === '') {
    fwrite(STDERR, "no categorization_config for project $projectId\n");
    exit(3);
}

$svc = new CategorizationService($pdo);
$count = $svc->applyCategorization($crawlId, $yaml, $projectId);

echo "PHP categorized $count pages on crawl $crawlId\n";
