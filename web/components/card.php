<?php
/**
 * Composant Card - Carte de statistique
 * 
 * Affiche une carte avec icône, titre, valeur et description
 * 
 * Configuration requise via $cardConfig:
 * - color: nom de la couleur (primary, success, warning, error, color1-color20)
 * - icon: nom de l'icône Material Symbols (ex: "link", "check_circle")
 * - title: titre de la carte
 * - value: valeur principale à afficher
 * - desc: description/sous-titre
 */

// Charger la classe Palette si pas déjà fait
if (!class_exists('Palette')) {
    require_once __DIR__ . '/../config/palette.php';
}

// Validation de la configuration
if (!isset($cardConfig)) {
    throw new Exception('$cardConfig doit être défini avant d\'inclure le composant card.php');
}

$color = $cardConfig['color'] ?? 'primary';
$icon = $cardConfig['icon'] ?? 'info';
$title = $cardConfig['title'] ?? 'Titre';
$value = $cardConfig['value'] ?? '0';
$desc = $cardConfig['desc'] ?? '';

// Instancier la palette et récupérer la couleur
$palette = new Palette();
$hexColor = $palette->getColor($color);

// Générer un ID unique pour cette instance de carte (pour le style inline)
$cardInstanceId = 'card-' . uniqid();
?>

<style>
    .<?= $cardInstanceId ?> {
        background: var(--card-bg);
        border-radius: 12px;
        padding: 1.5rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        border-left: 4px solid <?= $hexColor ?>;
    }
    
    .<?= $cardInstanceId ?>:hover {
        transform: translateY(-4px);
        box-shadow: 0 4px 16px rgba(0,0,0,0.12);
    }
    
    .<?= $cardInstanceId ?> .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
    }
    
    .<?= $cardInstanceId ?> .card-title {
        font-size: 1rem;
        color: var(--text-secondary);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .<?= $cardInstanceId ?> .card-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: <?= $hexColor ?>15;
    }
    
    .<?= $cardInstanceId ?> .card-icon .material-symbols-outlined {
        font-size: 24px;
        color: <?= $hexColor ?>;
    }
    
    .<?= $cardInstanceId ?> .card-value {
        font-size: 2rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
    }
    
    .<?= $cardInstanceId ?> .card-desc {
        font-size: 0.875rem;
        color: var(--text-secondary);
    }
</style>

<div class="<?= $cardInstanceId ?>">
    <div class="card-header">
        <div>
            <div class="card-title"><?= htmlspecialchars($title) ?></div>
        </div>
        <div class="card-icon">
            <span class="material-symbols-outlined"><?= htmlspecialchars($icon) ?></span>
        </div>
    </div>
    <div class="card-value"><?= $value ?></div>
    <div class="card-desc"><?= htmlspecialchars($desc) ?></div>
</div>
