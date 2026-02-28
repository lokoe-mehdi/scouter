<?php
/**
 * Composant Simple Table
 * 
 * Affiche un tableau simple avec gestion des types de colonnes
 * 
 * @param array $config Configuration du tableau
 *   - string $title: Titre du tableau
 *   - string $subtitle (optional): Sous-titre du tableau
 *   - array $columns: Configuration des colonnes
 *     - string $key: Clé de la donnée
 *     - string $label: Libellé de la colonne
 *     - string $type: Type de colonne (default, bold, badge-success, badge-warning, badge-danger, badge-info, badge-color, badge-autodetect, percent_bar)
 *   - array $data: Données du tableau (tableau associatif)
 *   - int $maxLines (optional): Nombre de lignes visibles par défaut (défaut: 5, 0 = toutes)
 */

$title = $config['title'] ?? 'Tableau';
$subtitle = $config['subtitle'] ?? null;
$columns = $config['columns'] ?? [];
$data = $config['data'] ?? [];
$categoryColors = $config['categoryColors'] ?? $GLOBALS['categoryColors'] ?? [];
$maxLines = $config['maxLines'] ?? 5;

// Déterminer si on doit afficher le bouton "Voir plus"
$totalLines = count($data);
$showExpandButton = $maxLines > 0 && $totalLines > $maxLines;

// Générer un ID unique pour ce tableau
$tableId = 'table-' . uniqid();

// Flag pour n'inclure le CSS qu'une seule fois
static $cssAdded = false;

?>

<?php if (!$cssAdded): ?>
<style>
.chart-action-btn {
    background: none;
    border: none;
    cursor: pointer !important;
    padding: 0.5rem;
    color: var(--text-secondary);
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    transition: all 0.2s;
    position: relative;
}

.chart-action-btn:hover {
    background: var(--background);
    color: var(--primary-color);
}

.chart-action-btn .material-symbols-outlined {
    font-size: 1.25rem;
    cursor: pointer !important;
}

.chart-tooltip {
    position: absolute;
    top: 100%;
    right: 0;
    margin-top: 0.5rem;
    background: var(--text-primary);
    color: white;
    padding: 0.5rem 0.75rem;
    border-radius: 4px;
    font-size: 0.75rem;
    white-space: nowrap;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s;
    z-index: 1000;
}

.chart-tooltip::before {
    content: '';
    position: absolute;
    bottom: 100%;
    right: 0.5rem;
    border: 4px solid transparent;
    border-bottom-color: var(--text-primary);
}

.chart-action-btn:hover .chart-tooltip {
    opacity: 1;
}

.table-expand-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.5rem 0;
    margin: 1rem auto 0;
    background: none;
    border: none;
    color: var(--text-secondary);
    cursor: pointer;
    transition: all 0.2s;
    font-size: 0.8125rem;
    font-weight: 400;
    text-decoration: underline;
    text-decoration-color: transparent;
    text-underline-offset: 3px;
}

.table-expand-btn:hover {
    color: var(--primary-color);
}

.table-expand-btn:hover .expand-text {
    text-decoration:underline;
    text-decoration-color: var(--primary-color);
}

.table-expand-btn .material-symbols-outlined {
    font-size: 1rem;
    transition: transform 0.3s;
}


.table-expand-btn.expanded .material-symbols-outlined {
    transform: rotate(180deg);
}

.table-row-hidden {
    display: none;
}

.table-row-collapsing {
    animation: slideDown 0.3s ease-out;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>
<?php 
$cssAdded = true;
endif; 
?>

<div class="table-card" id="<?= $tableId ?>" data-max-lines="<?= $maxLines ?>">
    <div class="table-header" style="display: flex; justify-content: space-between; align-items: flex-start;">
        <div>
            <h3 class="table-title"><?= htmlspecialchars($title) ?></h3>
            <?php if ($subtitle): ?>
                <p class="table-subtitle" style="margin: 0.25rem 0 0 0; color: var(--text-secondary); font-size: 0.875rem;">
                    <?= htmlspecialchars($subtitle) ?>
                </p>
            <?php endif; ?>
        </div>
        <div style="display: flex; gap: 0.5rem;">
            <button onclick="copyTableData('<?= $tableId ?>', event)" class="chart-action-btn">
                <span class="material-symbols-outlined">content_copy</span>
                <span class="chart-tooltip">Copier les données</span>
            </button>
        </div>
    </div>
    <table class="data-table">
        <thead>
            <tr>
                <?php foreach($columns as $column): ?>
                    <th><?= htmlspecialchars($column['label']) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach($data as $index => $row): ?>
            <tr class="<?= ($showExpandButton && $index >= $maxLines) ? 'table-row-hidden' : '' ?>" data-row-index="<?= $index ?>">
                <?php foreach($columns as $column): ?>
                    <?php
                    $key = $column['key'];
                    $type = $column['type'] ?? 'default';
                    $value = $row[$key] ?? '';
                    ?>
                    
                    <td>
                        <?php if ($type === 'default'): ?>
                            <?= htmlspecialchars($value) ?>
                        
                        <?php elseif ($type === 'category'): ?>
                            <?php 
                            // Afficher le badge de catégorie avec sa couleur
                            $categoryColor = $row[$key . '_color'] ?? $row['category_color'] ?? '#95a5a6';
                            ?>
                            <span style="display: inline-flex; align-items: center; gap: 0.5rem;">
                                <span style="width: 10px; height: 10px; border-radius: 50%; background: <?= htmlspecialchars($categoryColor) ?>; flex-shrink: 0;"></span>
                                <?= htmlspecialchars($value) ?>
                            </span>
                            
                        <?php elseif ($type === 'bold'): ?>
                            <strong><?= htmlspecialchars($value) ?></strong>
                            
                        <?php elseif ($type === 'badge-success'): ?>
                            <span class="badge badge-success"><?= htmlspecialchars($value) ?></span>
                            
                        <?php elseif ($type === 'badge-warning'): ?>
                            <span class="badge badge-warning"><?= htmlspecialchars($value) ?></span>
                            
                        <?php elseif ($type === 'badge-error' || $type === 'badge-danger'): ?>
                            <span class="badge badge-danger"><?= htmlspecialchars($value) ?></span>
                            
                        <?php elseif ($type === 'badge-info'): ?>
                            <span class="badge badge-info"><?= htmlspecialchars($value) ?></span>
                            
                        <?php elseif ($type === 'badge-color'): ?>
                            <?php 
                            // Utiliser les couleurs de catégories de la base
                            $bgColor = $categoryColors[$value] ?? '#aaaaaa';
                            $textColor = function_exists('getTextColorForBackground') ? getTextColorForBackground($bgColor) : '#fff';
                            ?>
                            <span class="badge" style="background: <?= $bgColor ?>; color: <?= $textColor ?>;">
                                <?= htmlspecialchars($value) ?>
                            </span>
                            
                        <?php elseif ($type === 'badge-autodetect'): ?>
                            <?php 
                            // Auto-détecter le type de badge
                            // Codes HTTP : utiliser getCodeColor() pour le texte et getCodeBackgroundColor() pour le fond
                            if (is_numeric($value)) {
                                $code = (int)$value;
                                $textColor = function_exists('getCodeColor') ? getCodeColor($code) : '#95a5a6';
                                $bgColor = function_exists('getCodeBackgroundColor') ? getCodeBackgroundColor($code, 0.3) : 'rgba(149, 165, 166, 0.3)';
                                // Utiliser getCodeDisplayValue pour afficher "JS Redirect" au lieu de 311
                                $displayValue = function_exists('getCodeDisplayValue') ? getCodeDisplayValue($code) : $value;
                                ?>
                                <span class="badge" style="background: <?= $bgColor ?>; color: <?= $textColor ?>; font-weight: 600;"><?= htmlspecialchars($displayValue) ?></span>
                                <?php
                            } else {
                                // Valeurs textuelles
                                $valueLower = strtolower($value);
                                $badgeType = 'info';
                                if ($valueLower === 'unique') $badgeType = 'success';
                                elseif ($valueLower === 'duplicate') $badgeType = 'warning';
                                elseif ($valueLower === 'empty') $badgeType = 'danger';
                                $badgeClass = 'badge-' . $badgeType;
                                ?>
                                <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($value) ?></span>
                                <?php
                            }
                            ?>
                            
                        <?php elseif ($type === 'percent_bar'): ?>
                            <?php 
                            // Conversion en pourcentage si entre 0 et 1
                            $percent = is_numeric($value) ? (float)$value : 0;
                            if ($percent <= 1 && $percent >= 0) {
                                $percent = $percent * 100;
                            }
                            $percent = round($percent, 1);
                            ?>
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <div style="flex: 1; background: #E1E8ED; height: 8px; border-radius: 4px; overflow: hidden;">
                                    <div style="width: <?= $percent ?>%; height: 100%; background: #6bd899;"></div>
                                </div>
                                <span style="min-width: 50px; text-align: right; font-weight: 600;">
                                    <?= $percent . '%' ?>
                                </span>
                            </div>
                            
                        <?php endif; ?>
                    </td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <?php if ($showExpandButton): ?>
    <div style="text-align: center;">
        <button class="table-expand-btn" onclick="toggleTableRows('<?= $tableId ?>')">
            <span class="expand-text">Voir toutes les lignes (<?= $totalLines - $maxLines ?> de plus)</span>
            <span class="material-symbols-outlined">expand_more</span>
        </button>
    </div>
    <?php endif; ?>
</div>

<script>
// Stocker les données brutes du tableau
if (typeof window.tableData === 'undefined') {
    window.tableData = {};
}

window.tableData['<?= $tableId ?>'] = {
    columns: <?= json_encode(array_map(function($col) { return $col['label']; }, $columns), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    data: <?= json_encode(array_map(function($row) use ($columns) {
        $rowData = [];
        foreach($columns as $column) {
            $key = $column['key'];
            $value = $row[$key] ?? '';
            
            // Pour percent_bar, convertir en pourcentage
            if (($column['type'] ?? 'default') === 'percent_bar') {
                $percent = is_numeric($value) ? (float)$value : 0;
                if ($percent <= 1 && $percent >= 0) {
                    $percent = $percent * 100;
                }
                $value = round($percent, 1) . '%';
            }
            
            $rowData[] = $value;
        }
        return $rowData;
    }, $data), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
};

// Fonction pour afficher/masquer les lignes du tableau
if (typeof window.toggleTableRows === 'undefined') {
    window.toggleTableRows = function(tableId) {
        const table = document.getElementById(tableId);
        if (!table) return;
        
        const btn = table.querySelector('.table-expand-btn');
        const expandText = btn.querySelector('.expand-text');
        const isExpanded = btn.classList.contains('expanded');
        const maxLines = parseInt(table.dataset.maxLines || 5);
        const allRows = table.querySelectorAll('tbody tr');
        const totalLines = allRows.length;
        
        if (isExpanded) {
            // Replier - cacher toutes les lignes au-delà de maxLines
            allRows.forEach((row, index) => {
                if (index >= maxLines) {
                    row.classList.add('table-row-hidden');
                    row.classList.remove('table-row-collapsing');
                }
            });
            btn.classList.remove('expanded');
            expandText.textContent = `Voir toutes les lignes (${totalLines - maxLines} de plus)`;
            
            // Scroll smooth vers le début du tableau
            table.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else {
            // Déplier - afficher toutes les lignes cachées
            const hiddenRows = table.querySelectorAll('.table-row-hidden');
            hiddenRows.forEach((row, index) => {
                setTimeout(() => {
                    row.classList.remove('table-row-hidden');
                    row.classList.add('table-row-collapsing');
                    setTimeout(() => {
                        row.classList.remove('table-row-collapsing');
                    }, 300);
                }, index * 30); // Effet cascade
            });
            btn.classList.add('expanded');
            expandText.textContent = 'Réduire le tableau';
        }
    };
}

// Définir la fonction globale pour copier les données du tableau
if (typeof window.copyTableData === 'undefined') {
    window.copyTableData = function(tableId, event) {
        const tableInfo = window.tableData[tableId];
        if (!tableInfo) return;
        
        // Construire le TSV
        let tsvData = '';
        
        // En-têtes
        tsvData = tableInfo.columns.join('\t') + '\n';
        
        // Données
        tableInfo.data.forEach(row => {
            tsvData += row.join('\t') + '\n';
        });
        
        // Copier dans le presse-papiers
        navigator.clipboard.writeText(tsvData).then(() => {
            // Notification globale
            if (typeof showGlobalStatus === 'function') {
                showGlobalStatus('✓ Données du tableau copiées !', 'success');
            }
        }).catch(err => {
            console.error('Erreur lors de la copie:', err);
            // Notification d'erreur
            if (typeof showGlobalStatus === 'function') {
                showGlobalStatus('Erreur lors de la copie des données', 'error');
            }
        });
    };
}
</script>
