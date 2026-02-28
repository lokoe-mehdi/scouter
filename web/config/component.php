<?php
/**
 * Classe Component - Gestionnaire de composants réutilisables
 * 
 * Permet d'appeler les composants de manière propre et élégante
 * avec des méthodes statiques
 */
class Component {
    
    /**
     * Chemin vers le dossier des composants
     */
    private static $componentsPath = __DIR__ . '/../components/';
    
    /**
     * Affiche une carte de statistique
     * 
     * @param array $config Configuration de la carte
     *   - color: nom de la couleur (primary, success, warning, error, color1-color20)
     *   - icon: nom de l'icône Material Symbols
     *   - title: titre de la carte
     *   - value: valeur principale à afficher
     *   - desc: description/sous-titre
     * 
     * @example
     * Component::card([
     *     'color' => 'primary',
     *     'icon' => 'link',
     *     'title' => 'Total URLs',
     *     'value' => '2,621',
     *     'desc' => 'URLs découvertes'
     * ]);
     */
    public static function card(array $config) {
        $cardConfig = $config;
        include self::$componentsPath . 'card.php';
    }
    
    /**
     * Affiche un tableau d'URLs (wrapper pour url-table.php)
     * 
     * @param array $config Configuration du tableau
     */
    public static function urlTable(array $config) {
        $urlTableConfig = $config;
        include self::$componentsPath . 'url-table.php';
    }
    
    /**
     * Affiche un tableau de liens (wrapper pour link-table.php)
     * 
     * @param array $config Configuration du tableau
     */
    public static function linkTable(array $config) {
        $linkTableConfig = $config;
        include self::$componentsPath . 'link-table.php';
    }
    
    /**
     * Affiche la modal de détails d'URL
     */
    public static function urlDetailsModal() {
        include self::$componentsPath . 'url-details-modal.php';
    }
    
    /**
     * Affiche le dropdown d'administration
     * 
     * @param bool $isInSubfolder Indique si on est dans un sous-dossier
     */
    public static function adminDropdown($isInSubfolder = false) {
        include self::$componentsPath . 'admin-dropdown.php';
    }
    
    /**
     * Affiche un graphique Highcharts (bar vertical/horizontal, donut, area ou line)
     * 
     * @param array $config Configuration du graphique
     *   - type: 'bar' (vertical), 'horizontalBar', 'donut', 'area' ou 'line'
     *   - title: titre du graphique
     *   - subtitle: description/sous-titre
     *   - categories: tableau des catégories pour l'axe (bar/horizontalBar/line)
     *   - series: tableau de séries avec name, data, et optionnellement color
     *   - colors: tableau de couleurs custom (optionnel, sinon palette par défaut)
     *   - height: hauteur en pixels (défaut: 300)
     *   - xAxisTitle: titre de l'axe X
     *   - yAxisTitle: titre de l'axe Y
     *   - xAxisMin: valeur minimale de l'axe X (optionnel)
     *   - xAxisMax: valeur maximale de l'axe X (optionnel)
     *   - logarithmic: échelle logarithmique pour l'axe Y (défaut: false, pour area)
     *   - stacking: 'normal' ou 'percent' pour empiler les séries (bar/horizontalBar)
     * 
     * @example
     * Component::chart([
     *     'type' => 'line',
     *     'title' => 'PageRank par profondeur',
     *     'subtitle' => 'Évolution du PageRank',
     *     'categories' => ['Niveau 0', 'Niveau 1', 'Niveau 2'],
     *     'series' => [
     *         ['name' => 'PageRank', 'data' => [0.15, 0.10, 0.05]]
     *     ],
     *     'xAxisTitle' => 'Profondeur',
     *     'yAxisTitle' => 'PageRank moyen'
     * ]);
     */
    public static function chart(array $config) {
        $chartConfig = $config;
        include self::$componentsPath . 'chart.php';
    }
    
    /**
     * Affiche un tableau simple avec gestion des types de colonnes
     * 
     * @param array $config Configuration du tableau
     *   - title: titre du tableau
     *   - subtitle: sous-titre optionnel
     *   - columns: tableau de colonnes avec key, label et type
     *     - key: clé de la donnée
     *     - label: libellé de la colonne
     *     - type: type de colonne (default, bold, badge-success, badge-warning, badge-danger, badge-info, badge-color, badge-autodetect, percent_bar)
     *   - data: tableau de données (tableau associatif)
     * 
     * @example
     * Component::simpleTable([
     *     'title' => 'Statistiques par catégorie',
     *     'subtitle' => 'Vue d\'ensemble',
     *     'columns' => [
     *         ['key' => 'category', 'label' => 'Catégorie', 'type' => 'badge-color'],
     *         ['key' => 'total', 'label' => 'Total', 'type' => 'default'],
     *         ['key' => 'percent', 'label' => '% Compliant', 'type' => 'percent_bar']
     *     ],
     *     'data' => $tableData
     * ]);
     */
    public static function simpleTable(array $config) {
        include self::$componentsPath . 'simple-table.php';
    }
}
