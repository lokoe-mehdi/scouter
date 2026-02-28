<?php
/**
 * Migration : Recalcul rétroactif des statistiques de tous les crawls
 * 
 * Corrige :
 * - response_time : Calculé uniquement sur les URLs code=200 (au lieu de crawled=true)
 * - depth_max : Calculé uniquement sur les URLs crawled=true (au lieu de toutes)
 * 
 * Date : 2025-12-05
 */

require_once __DIR__ . '/../app/Database/PostgresDatabase.php';

use App\Database\PostgresDatabase;

echo "=== Migration : Recalcul des statistiques des crawls ===\n\n";

try {
    $pdo = PostgresDatabase::getInstance()->getConnection();
    
    // Récupérer tous les crawls
    $stmt = $pdo->query("SELECT id, path, domain FROM crawls ORDER BY id");
    $crawls = $stmt->fetchAll(PDO::FETCH_OBJ);
    
    echo "Crawls trouvés : " . count($crawls) . "\n\n";
    
    foreach ($crawls as $crawl) {
        echo "Crawl #{$crawl->id} ({$crawl->domain})...\n";
        
        // Total URLs
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE crawl_id = :crawl_id");
        $stmt->execute([':crawl_id' => $crawl->id]);
        $urls = (int)$stmt->fetchColumn();
        
        // URLs crawlées
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE crawl_id = :crawl_id AND crawled = true");
        $stmt->execute([':crawl_id' => $crawl->id]);
        $crawled = (int)$stmt->fetchColumn();
        
        // URLs compliant
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE crawl_id = :crawl_id AND compliant = true");
        $stmt->execute([':crawl_id' => $crawl->id]);
        $compliant = (int)$stmt->fetchColumn();
        
        // Duplicates (URLs non canoniques)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE crawl_id = :crawl_id AND canonical = false");
        $stmt->execute([':crawl_id' => $crawl->id]);
        $duplicates = (int)$stmt->fetchColumn();
        
        // Temps de réponse moyen (uniquement sur les URLs code 200)
        $stmt = $pdo->prepare("SELECT AVG(response_time) FROM pages WHERE crawl_id = :crawl_id AND code = 200 AND response_time > 0");
        $stmt->execute([':crawl_id' => $crawl->id]);
        $responseTime = (float)$stmt->fetchColumn() ?: 0;
        
        // Profondeur max (uniquement sur les URLs crawlées)
        $stmt = $pdo->prepare("SELECT MAX(depth) FROM pages WHERE crawl_id = :crawl_id AND crawled = true");
        $stmt->execute([':crawl_id' => $crawl->id]);
        $depthMax = (int)$stmt->fetchColumn();
        
        // Mise à jour
        $stmt = $pdo->prepare("
            UPDATE crawls SET 
                urls = :urls,
                crawled = :crawled,
                compliant = :compliant,
                duplicates = :duplicates,
                response_time = :response_time,
                depth_max = :depth_max
            WHERE id = :id
        ");
        
        $stmt->execute([
            ':urls' => $urls,
            ':crawled' => $crawled,
            ':compliant' => $compliant,
            ':duplicates' => $duplicates,
            ':response_time' => round($responseTime, 2),
            ':depth_max' => $depthMax,
            ':id' => $crawl->id
        ]);
        
        echo "  ✓ URLs: $urls | Crawled: $crawled | Compliant: $compliant | ";
        echo "TTFB: " . round($responseTime, 2) . "ms | Depth max: $depthMax\n";
    }
    
    echo "\n=== Migration terminée avec succès ! ===\n";
    echo "Tous les crawls ont été mis à jour avec les nouvelles formules.\n";
    
} catch (Exception $e) {
    echo "\n❌ Erreur : " . $e->getMessage() . "\n";
    exit(1);
}
