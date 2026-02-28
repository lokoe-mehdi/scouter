<?php

namespace App\Util;

/**
 * Gestion des couleurs des catégories de pages
 * 
 * Cette classe fournit une palette de couleurs prédéfinies pour
 * l'affichage visuel des catégories dans les graphiques et tableaux.
 * Attribue une couleur unique et cohérente à chaque catégorie.
 * 
 * @package    Scouter
 * @subpackage Visualization
 * @author     Mehdi Colin
 * @version    1.0.0
 */
class CategoryColors {
    
    private static $palette = [
        '#3498db', '#2ecc71', '#f39c12', '#9b59b6', '#e74c3c',
        '#1abc9c', '#e67e22', '#34495e', '#16a085', '#c0392b',
        '#27ae60', '#2980b9', '#8e44ad', '#f1c40f', '#d35400',
        '#7f8c8d', '#95a5a6', '#bdc3c7', '#c0392b', '#2c3e50'
    ];
    
    /**
     * Génère le mapping catégorie => couleur (ancienne version SQLite)
     * @param PDO $pdo
     * @return array
     */
    public static function getCategoryColorMapping($pdo) {
        $mapping = [];
        
        // Récupérer toutes les catégories, triées par ordre alphabétique
        $stmt = $pdo->query("SELECT cat FROM categories ORDER BY cat ASC");
        $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Attribuer une couleur à chaque catégorie
        foreach($categories as $index => $category) {
            if(empty($category)) continue;
            
            // Utiliser le modulo pour faire une rotation si plus de 20 catégories
            $colorIndex = $index % count(self::$palette);
            $mapping[$category] = self::$palette[$colorIndex];
        }
        
        return $mapping;
    }
    
    /**
     * Génère le mapping catégorie => couleur pour un crawl_id (PostgreSQL)
     * Utilise les couleurs définies par l'utilisateur dans la table categories
     * @param PDO $pdo
     * @param int $crawlId
     * @return array
     */
    public static function getCategoryColorMappingFromCrawlId($pdo, $crawlId) {
        $mapping = [];
        
        // Récupérer les catégories avec leurs couleurs définies par l'utilisateur
        $stmt = $pdo->prepare("SELECT cat, color FROM categories WHERE crawl_id = :crawl_id ORDER BY cat ASC");
        $stmt->execute([':crawl_id' => $crawlId]);
        
        while ($row = $stmt->fetch(PDO::FETCH_OBJ)) {
            if(empty($row->cat)) continue;
            // Utiliser la couleur de la base ou #aaaaaa par défaut
            $mapping[$row->cat] = $row->color ?? '#aaaaaa';
        }
        
        return $mapping;
    }
    
    /**
     * Retourne la couleur d'une catégorie
     * @param string $category
     * @param array $mapping
     * @return string
     */
    public static function getColor($category, $mapping) {
        if(empty($category)) {
            return '#95a5a6'; // Gris pour les catégories vides
        }
        
        return $mapping[$category] ?? '#95a5a6';
    }
    
    /**
     * Retourne la palette de couleurs
     * @return array
     */
    public static function getPalette() {
        return self::$palette;
    }
}
