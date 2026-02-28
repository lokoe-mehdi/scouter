<?php
/**
 * Classe Palette - Gestion des couleurs de l'application
 * 
 * Contient les couleurs principales et une palette de 20 couleurs pastel
 */
class Palette {
    /**
     * @var array Tableau des couleurs disponibles
     */
    private $colors = [
        // Couleurs principales (issues du CSS)
        'primary' => '#4ECDC4',
        'info' => '#4ECDC4',
        'success' => '#2ECC71',
        'warning' => '#F39C12',
        'error' => '#E74C3C',
        
        // Palette de 20 couleurs harmonieuses avec bon contraste pour texte blanc
        'color1' => '#4ECDC4',   // Turquoise (primaire) - ne pas changer
        'color2' => '#FF6B6B',   // Rouge corail
        'color3' => '#FFB84D',   // Orange clair
        'color4' => '#51CF66',   // Vert clair
        'color5' => '#4DABF7',   // Bleu ciel
        'color6' => '#A78BFA',   // Violet clair
        'color7' => '#FF8C42',   // Orange pêche
        'color8' => '#26C6DA',   // Cyan
        'color9' => '#5C9BD5',   // Bleu moyen
        'color10' => '#B794F6',  // Lavande
        'color11' => '#F06595',  // Rose vif
        'color12' => '#FD7E14',  // Orange vif
        'color13' => '#20B2AA',  // Turquoise mer
        'color14' => '#8D99AE',  // Gris bleu
        'color15' => '#6C757D',  // Gris moyen
        'color16' => '#EC4899',  // Rose bonbon
        'color17' => '#14B8A6',  // Teal
        'color18' => '#F59E0B',  // Ambre
        'color19' => '#10B981',  // Émeraude
        'color20' => '#6366F1',  // Indigo
    ];
    
    /**
     * Récupère une couleur de la palette
     * 
     * @param string $colorName Nom de la couleur (primary, success, warning, error, color1-color20)
     * @return string Code hexadécimal de la couleur ou primary par défaut
     */
    public function getColor($colorName) {
        return isset($this->colors[$colorName]) ? $this->colors[$colorName] : $this->colors['primary'];
    }
    
    /**
     * Récupère toutes les couleurs
     * 
     * @return array Tableau associatif de toutes les couleurs
     */
    public function getAllColors() {
        return $this->colors;
    }
    
    /**
     * Vérifie si une couleur existe
     * 
     * @param string $colorName Nom de la couleur
     * @return bool True si la couleur existe
     */
    public function hasColor($colorName) {
        return isset($this->colors[$colorName]);
    }
}
