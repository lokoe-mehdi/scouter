<?php
/**
 * Composant Chart - Graphiques réutilisables
 * 
 * Affiche des graphiques Highcharts (bar chart vertical/horizontal ou donut)
 * 
 * Configuration requise via $chartConfig:
 * - type: 'bar', 'horizontalBar' ou 'donut'
 * - title: titre du graphique
 * - subtitle: description/sous-titre
 * - categories: tableau des catégories pour l'axe X (bar/horizontalBar uniquement)
 * - series: tableau de séries avec name, data, et optionnellement color
 * - colors: tableau de couleurs custom (optionnel, sinon palette par défaut)
 * - height: hauteur du graphique en pixels (défaut: 300)
 * - xAxisTitle: titre de l'axe X (bar/horizontalBar uniquement)
 * - yAxisTitle: titre de l'axe Y (bar/horizontalBar uniquement)
 */

// Charger la classe Palette si pas déjà fait
if (!class_exists('Palette')) {
    require_once __DIR__ . '/../config/palette.php';
}

// Inclure le composant scope-modal (singleton)
require_once __DIR__ . '/scope-modal.php';

// Validation de la configuration
if (!isset($chartConfig)) {
    throw new Exception('$chartConfig doit être défini avant d\'inclure le composant chart.php');
}

$type = $chartConfig['type'] ?? 'bar';
$title = $chartConfig['title'] ?? 'Graphique';
$subtitle = $chartConfig['subtitle'] ?? '';
$categories = $chartConfig['categories'] ?? [];
$series = $chartConfig['series'] ?? [];
$customColors = $chartConfig['colors'] ?? null;
$height = $chartConfig['height'] ?? 300;
$xAxisTitle = $chartConfig['xAxisTitle'] ?? '';
$yAxisTitle = $chartConfig['yAxisTitle'] ?? '';
$stacking = $chartConfig['stacking'] ?? null; // null, 'normal', 'percent'
$yAxisMax = $chartConfig['yAxisMax'] ?? null;
$logarithmic = $chartConfig['logarithmic'] ?? false; // Échelle logarithmique pour l'axe Y
$xAxisMin = $chartConfig['xAxisMin'] ?? null;
$xAxisMax = $chartConfig['xAxisMax'] ?? null;
$tooltipFormat = $chartConfig['tooltipFormat'] ?? null; // Format personnalisé du tooltip
$legendPosition = $chartConfig['legendPosition'] ?? 'right'; // Position de la légende: 'right', 'bottom', 'left', 'top'
$sqlQueryRaw = $chartConfig['sqlQuery'] ?? null; // Requête SQL source
$showActions = !isset($chartConfig['actions']) || $chartConfig['actions'] !== false; // Afficher les boutons d'actions

// Fonction pour nettoyer la requête SQL (enlever crawl_id pour SQL Explorer)
if (!function_exists('cleanSqlForExplorer')) {
    function cleanSqlForExplorer($sql) {
        if (!$sql) return null;
        
        // IMPORTANT: Les patterns avec alias (p.crawl_id) doivent être traités EN PREMIER
        // sinon le pattern générique laisse le préfixe "p." derrière
        $patterns = [
            // Variantes avec alias EN PREMIER (p.crawl_id, pages.crawl_id, c.crawl_id)
            '/\b\w+\.crawl_id\s*=\s*:\s*crawl_id\s+AND\s+/i',
            '/\s+AND\s+\w+\.crawl_id\s*=\s*:\s*crawl_id\b/i',
            '/\bWHERE\s+\w+\.crawl_id\s*=\s*:\s*crawl_id\s*(?=\s*$|\s*GROUP|\s*ORDER|\s*LIMIT)/im',
            // Puis les patterns sans alias
            '/\bcrawl_id\s*=\s*:\s*crawl_id\s+AND\s+/i',
            '/\s+AND\s+crawl_id\s*=\s*:\s*crawl_id\b/i',
            '/\bWHERE\s+crawl_id\s*=\s*:\s*crawl_id\s*$/im',
            // Variantes avec valeur numérique (crawl_id = 123)
            '/\b\w+\.crawl_id\s*=\s*\d+\s+AND\s+/i',
            '/\s+AND\s+\w+\.crawl_id\s*=\s*\d+\b/i',
            '/\bcrawl_id\s*=\s*\d+\s+AND\s+/i',
            '/\s+AND\s+crawl_id\s*=\s*\d+\b/i',
        ];
        
        $cleaned = $sql;
        foreach ($patterns as $pattern) {
            $cleaned = preg_replace($pattern, '', $cleaned);
        }
        
        // Nettoyer les espaces multiples et les retours à la ligne en trop
        $cleaned = preg_replace('/\n\s*\n/', "\n", $cleaned);
        $cleaned = preg_replace('/[ \t]+/', ' ', $cleaned);
        $cleaned = trim($cleaned);
        
        return $cleaned;
    }
}

// Fonction pour extraire les conditions WHERE et générer le scope
if (!function_exists('extractScopeFromSql')) {
    function extractScopeFromSql($sql) {
        if (!$sql) return null;
        
        // Extraire la clause WHERE
        if (!preg_match('/\bWHERE\s+(.+?)(?:\bGROUP\b|\bORDER\b|\bLIMIT\b|\bHAVING\b|$)/is', $sql, $matches)) {
            return null;
        }
        
        $whereClause = trim($matches[1]);
        
        // Supprimer les conditions crawl_id
        $whereClause = preg_replace('/\b\w*\.?crawl_id\s*=\s*:\s*crawl_id\s*(AND\s+)?/i', '', $whereClause);
        $whereClause = preg_replace('/\s+AND\s*$/i', '', $whereClause);
        $whereClause = trim($whereClause);
        
        if (empty($whereClause)) {
            return null;
        }
        
        // Séparer les conditions par AND
        $conditions = preg_split('/\s+AND\s+/i', $whereClause);
        $scopeItems = [];
        
        foreach ($conditions as $condition) {
            $condition = trim($condition);
            if (empty($condition)) continue;
            
            // Nettoyer les alias de table (p., pages., etc.)
            $condition = preg_replace('/\b\w+\.(\w+)/', '$1', $condition);
            
            // Formater pour l'affichage
            $condition = trim($condition);
            if (!empty($condition)) {
                $scopeItems[] = $condition;
            }
        }
        
        return $scopeItems;
    }
}

$sqlQuery = cleanSqlForExplorer($sqlQueryRaw);
$scopeItems = extractScopeFromSql($sqlQueryRaw);

// Générer un ID unique pour ce graphique
$chartId = 'chart-' . uniqid();

// Préparer les couleurs
$palette = new Palette();
$defaultColors = [];
for ($i = 1; $i <= 20; $i++) {
    $defaultColors[] = $palette->getColor('color' . $i);
}

// Si des couleurs custom sont fournies, les utiliser, sinon palette par défaut
$colors = $customColors ?? $defaultColors;

// Assigner les couleurs aux séries si elles n'en ont pas déjà
foreach ($series as $index => &$serie) {
    if (!isset($serie['color'])) {
        $serie['color'] = $colors[$index % count($colors)];
    }
}
unset($serie);

// Flag pour n'ajouter le CSS qu'une seule fois
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

.chart-view-container {
    position: relative;
    width: 100%;
    height: 85%;
    display: flex;
    justify-content: center;
    align-items: center;
    flex-grow: 1;
}

.chart-view, .table-view {
    width: 100%;
}

.table-view table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
}

.table-view th {
    background: var(--background);
    color: var(--text-primary);
    font-weight: 600;
    padding: 0.75rem;
    text-align: left;
    border-bottom: 2px solid var(--border-color);
    position: sticky;
    top: 0;
    z-index: 10;
}

.table-view td {
    padding: 0.75rem;
    border-bottom: 1px solid var(--border-color);
    color: var(--text-primary);
}

.table-view tr:hover {
    background: var(--background);
}

.table-view td:first-child {
    font-weight: 500;
}

.table-view {
    max-height: 600px;
    overflow-y: auto;
    border-radius: 8px;
    border: 1px solid var(--border-color);
}

.chart-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.8);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 2rem;
}

.chart-modal.active {
    display: flex;
}

.chart-modal-content {
    background: #2C3E50;
    border-radius: 12px;
    padding: 0;
    max-width: 95vw;
    height: 95vh;
    width: 95vw;
    position: relative;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.chart-modal-header {
    background: linear-gradient(135deg, #1a252f 0%, #2C3E50 100%);
    color: white;
    padding: 1rem 1.5rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-radius: 12px 12px 0 0;
}

.chart-modal-header h2 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.chart-modal-header .chart-modal-subtitle {
    margin: 0.25rem 0 0 0;
    font-size: 0.85rem;
    opacity: 0.7;
}

.chart-modal-body {
    flex: 1;
    padding: 1.5rem;
    overflow: hidden;
    display: flex;
    gap: 1.5rem;
    min-height: 0;
    background: var(--card-bg);
}

#chart-modal-table {
    width: 30%;
    overflow-y: auto;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    background: white;
}

#chart-modal-container {
    flex: 1;
    min-height: 0;
    height: 100%;
}

#chart-modal-container .highcharts-container {
    height: 100% !important;
}

#chart-modal-container .highcharts-root {
    height: 100% !important;
}

#chart-modal-table table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
}

#chart-modal-table th {
    background: var(--background);
    color: var(--text-primary);
    font-weight: 600;
    padding: 0.5rem;
    text-align: left;
    border-bottom: 2px solid var(--border-color);
    position: sticky;
    top: 0;
    z-index: 10;
    font-size: 0.8rem;
}

#chart-modal-table td {
    padding: 0.5rem;
    border-bottom: 1px solid var(--border-color);
    color: var(--text-primary);
    font-size: 0.8rem;
}

#chart-modal-table tr:hover {
    background: var(--background);
}

#chart-modal-table td:first-child {
    font-weight: 500;
}

.chart-modal-close {
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    cursor: pointer;
    padding: 0.5rem;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: rgba(255, 255, 255, 0.8);
    transition: all 0.2s;
}

.chart-modal-close:hover {
    background: rgba(231, 76, 60, 0.9);
    border-color: rgba(231, 76, 60, 0.9);
    color: white;
}

/* Les styles de la modale SQL sont maintenant dans scope-modal.php */
</style>
<?php 
$cssAdded = true;
endif; 
?>

<!-- Chart Card -->
<div class="chart-card">
    <div class="chart-header" style="display: flex; justify-content: space-between; align-items: flex-start;">
        <div>
            <h3 class="chart-title"><?= htmlspecialchars($title) ?></h3>
            <?php if ($subtitle): ?>
                <p class="chart-subtitle"><?= htmlspecialchars($subtitle) ?></p>
            <?php endif; ?>
        </div>
        <?php if ($showActions): ?>
        <div style="display: flex; gap: 0.5rem;">
            <button onclick="toggleChartView('<?= $chartId ?>')" class="chart-action-btn" id="toggle-<?= $chartId ?>" title="Afficher en tableau">
                <span class="material-symbols-outlined">table_chart</span>
            </button>
            <button onclick="copyChartData('<?= $chartId ?>', event)" class="chart-action-btn" title="Copier les données">
                <span class="material-symbols-outlined">content_copy</span>
            </button>
            <button onclick="downloadChart('<?= $chartId ?>')" class="chart-action-btn" title="Enregistrer l'image">
                <span class="material-symbols-outlined">download</span>
            </button>
            <?php if ($sqlQuery): ?>
            <button onclick="showChartSqlScope('<?= $chartId ?>')" class="chart-action-btn" title="Voir la requête SQL">
                <span class="material-symbols-outlined">database</span>
            </button>
            <?php endif; ?>
            <button onclick="expandChart('<?= $chartId ?>', '<?= addslashes(str_replace(["\r", "\n"], ' ', $title)) ?>', '<?= addslashes(str_replace(["\r", "\n"], ' ', $subtitle)) ?>')" class="chart-action-btn" title="Agrandir">
                <span class="material-symbols-outlined">fullscreen</span>
            </button>
        </div>
        <?php endif; ?>
    </div>
    <div class="chart-view-container">
        <div id="<?= $chartId ?>" class="chart-view active" style="<?= $type === 'horizontalBar' ? '' : 'height: ' . $height . 'px;' ?>"></div>
        <div id="table-<?= $chartId ?>" class="table-view" style="display: none;"></div>
    </div>
</div>

<script>
<?php if ($type === 'bar'): ?>
// Bar Chart (Column - Vertical)
Highcharts.chart('<?= $chartId ?>', {
    chart: { 
        type: 'column'
    },
    title: { 
        text: null 
    },
    xAxis: {
        categories: <?= json_encode($categories) ?>,
        <?php if ($xAxisTitle): ?>
        title: { text: '<?= addslashes($xAxisTitle) ?>' }
        <?php endif; ?>
    },
    yAxis: {
        title: { text: '<?= addslashes($yAxisTitle) ?>' }
        <?php if ($yAxisMax !== null): ?>
        ,max: <?= $yAxisMax ?>
        <?php endif; ?>
        <?php if ($stacking === 'percent'): ?>
        ,labels: {
            format: '{value}%'
        }
        <?php endif; ?>
    },
    legend: { 
        enabled: true,
        navigation: {
            activeColor: '#5a6c7d',
            inactiveColor: '#ccc',
            style: {
                color: '#5a6c7d'
            }
        }
    },
    plotOptions: {
        column: {
            borderRadius: 4
            <?php if ($stacking === 'percent'): ?>
            ,stacking: 'percent'
            ,dataLabels: {
                enabled: true,
                formatter: function() {
                    return this.y === 0 ? null : this.percentage.toFixed(1) + '%';
                }
            }
            <?php elseif ($stacking === 'normal'): ?>
            ,stacking: 'normal'
            ,dataLabels: {
                enabled: true,
                formatter: function() {
                    return this.y === 0 ? null : this.y;
                }
            }
            <?php else: ?>
            ,dataLabels: {
                enabled: true,
                formatter: function() {
                    return this.y === 0 ? null : this.y;
                }
            }
            <?php endif; ?>
        }
    },
    <?php if ($stacking === 'percent'): ?>
    tooltip: {
        pointFormat: '<span style="color:{series.color}">{series.name}</span>: <b>{point.y}</b> ({point.percentage:.1f}%)<br/>',
        shared: false
    },
    <?php endif; ?>
    series: <?= json_encode(array_map(function($s) {
        $serie = [
            'name' => $s['name'] ?? 'Série',
            'data' => $s['data'] ?? []
        ];
        
        // Gestion des couleurs
        if (isset($s['colorByPoint']) && $s['colorByPoint'] === true) {
            $serie['colorByPoint'] = true;
            if (isset($s['colors'])) {
                $serie['colors'] = $s['colors'];
            }
        } else if (isset($s['color'])) {
            $serie['color'] = $s['color'];
        } else {
            $serie['color'] = '#4ECDC4';
        }
        
        return $serie;
    }, $series)) ?>,
    exporting: {
        enabled: false
    },
    credits: { 
        enabled: false 
    }
});

<?php elseif ($type === 'horizontalBar'): ?>
// Bar Chart (Horizontal)
<?php
// Calculer la hauteur dynamique : 40px par catégorie, minimum 300px
$dynamicHeight = max(300, count($categories) * 40);
?>
Highcharts.chart('<?= $chartId ?>', {
    chart: { 
        type: 'bar',
        height: <?= $dynamicHeight ?>
    },
    title: { 
        text: null 
    },
    xAxis: {
        categories: <?= json_encode($categories) ?>,
        <?php if ($xAxisTitle): ?>
        title: { text: '<?= addslashes($xAxisTitle) ?>' }
        <?php endif; ?>
    },
    yAxis: {
        title: { text: '<?= addslashes($yAxisTitle) ?>' }
        <?php if ($yAxisMax !== null): ?>
        ,max: <?= $yAxisMax ?>
        <?php endif; ?>
        <?php if ($stacking === 'percent'): ?>
        ,labels: {
            format: '{value}%'
        }
        <?php endif; ?>
    },
    legend: { 
        enabled: true,
        navigation: {
            activeColor: '#5a6c7d',
            inactiveColor: '#ccc',
            style: {
                color: '#5a6c7d'
            }
        }
    },
    plotOptions: {
        bar: {
            borderRadius: 4
            <?php if ($stacking): ?>
            ,stacking: '<?= $stacking ?>'
            ,dataLabels: {
                enabled: true,
                formatter: function() {
                    return this.y === 0 ? null : this.percentage.toFixed(1) + '%';
                }
            }
            <?php else: ?>
            ,dataLabels: {
                enabled: true,
                formatter: function() {
                    return this.y === 0 ? null : this.y;
                }
            }
            <?php endif; ?>
        }
    },
    <?php if ($stacking): ?>
    tooltip: {
        pointFormat: '<span style="color:{series.color}">{series.name}</span>: <b>{point.y}</b> ({point.percentage:.1f}%)<br/>',
        shared: false
    },
    <?php endif; ?>
    series: <?= json_encode(array_map(function($s) {
        $serie = [
            'name' => $s['name'] ?? 'Série',
            'data' => $s['data'] ?? []
        ];
        
        // Gestion des couleurs
        if (isset($s['colorByPoint']) && $s['colorByPoint'] === true) {
            $serie['colorByPoint'] = true;
            if (isset($s['colors'])) {
                $serie['colors'] = $s['colors'];
            }
        } else if (isset($s['color'])) {
            $serie['color'] = $s['color'];
        } else {
            $serie['color'] = '#4ECDC4';
        }
        
        return $serie;
    }, $series)) ?>,
    exporting: {
        enabled: false
    },
    credits: { 
        enabled: false 
    }
});

<?php elseif ($type === 'donut'): ?>
// Donut Chart
Highcharts.chart('<?= $chartId ?>', {
    chart: { 
        type: 'pie'
    },
    title: { 
        text: null 
    },
    legend: {
        enabled: true,
        <?php if ($legendPosition === 'bottom'): ?>
        align: 'center',
        verticalAlign: 'bottom',
        layout: 'horizontal'
        <?php elseif ($legendPosition === 'top'): ?>
        align: 'center',
        verticalAlign: 'top',
        layout: 'horizontal'
        <?php elseif ($legendPosition === 'left'): ?>
        align: 'left',
        verticalAlign: 'middle',
        layout: 'vertical'
        <?php else: ?>
        align: 'right',
        verticalAlign: 'middle',
        layout: 'vertical'
        <?php endif; ?>,
        navigation: {
            activeColor: '#5a6c7d',
            inactiveColor: '#ccc',
            style: {
                color: '#5a6c7d'
            }
        }
    },
    tooltip: {
        pointFormat: '<b>{point.percentage:.1f}%</b> ({point.y:.2f})'
    },
    plotOptions: {
        pie: {
            innerSize: '60%',
            showInLegend: true,
            dataLabels: {
                enabled: true,
                formatter: function() {
                    return this.y === 0 ? null : this.point.name + ': ' + this.percentage.toFixed(1) + '%';
                }
            }
        }
    },
    series: [{
        name: '<?= addslashes($series[0]['name'] ?? 'Valeur') ?>',
        data: <?= json_encode(array_map(function($item, $index) use ($palette) {
            // Si la couleur n'est pas spécifiée, utiliser la palette
            $color = $item['color'] ?? $palette->getColor('color' . (($index % 20) + 1));
            return [
                'name' => $item['name'] ?? '',
                'y' => $item['y'] ?? 0,
                'color' => $color
            ];
        }, $series[0]['data'] ?? [], array_keys($series[0]['data'] ?? []))) ?>
    }],
    exporting: {
        enabled: false
    },
    credits: { 
        enabled: false 
    }
});

<?php elseif ($type === 'area'): ?>
// Area Chart
Highcharts.chart('<?= $chartId ?>', {
    chart: { 
        type: 'area'
    },
    title: { 
        text: null 
    },
    xAxis: {
        <?php if ($xAxisTitle): ?>
        title: { text: '<?= addslashes($xAxisTitle) ?>' }
        <?php endif; ?>
        <?php if ($xAxisMin !== null): ?>
        ,min: <?= $xAxisMin ?>
        <?php endif; ?>
        <?php if ($xAxisMax !== null): ?>
        ,max: <?= $xAxisMax ?>
        <?php endif; ?>
    },
    yAxis: {
        title: { text: '<?= addslashes($yAxisTitle) ?>' }
        <?php if ($logarithmic): ?>
        ,type: 'logarithmic'
        ,minorTickInterval: 0.1
        <?php endif; ?>
    },
    legend: { 
        enabled: false
    },
    <?php if ($tooltipFormat): ?>
    tooltip: {
        formatter: function() {
            return '<?= addslashes($tooltipFormat) ?>'
                .replace('{x}', this.x.toFixed(2))
                .replace('{y}', this.y);
        }
    },
    <?php endif; ?>
    plotOptions: {
        area: {
            fillColor: {
                linearGradient: {
                    x1: 0,
                    y1: 0,
                    x2: 0,
                    y2: 1
                },
                stops: [
                    [0, '<?= $series[0]['color'] ?? '#4ECDC4' ?>'],
                    [1, 'rgba(255, 255, 255, 0.05)']
                ]
            },
            marker: {
                radius: 3
            },
            lineWidth: 2,
            states: {
                hover: {
                    lineWidth: 3
                }
            },
            threshold: null,
            dataLabels: {
                enabled: false
            }
        }
    },
    series: <?= json_encode(array_map(function($s) {
        return [
            'name' => $s['name'] ?? 'Série',
            'data' => $s['data'] ?? [],
            'color' => $s['color'] ?? '#4ECDC4'
        ];
    }, $series)) ?>,
    exporting: {
        enabled: false
    },
    credits: { 
        enabled: false 
    }
});

<?php elseif ($type === 'line'): ?>
// Line Chart
Highcharts.chart('<?= $chartId ?>', {
    chart: { 
        type: 'line'
    },
    title: { 
        text: null 
    },
    xAxis: {
        categories: <?= json_encode($categories) ?>,
        <?php if ($xAxisTitle): ?>
        title: { text: '<?= addslashes($xAxisTitle) ?>' }
        <?php endif; ?>
    },
    yAxis: {
        title: { text: '<?= addslashes($yAxisTitle) ?>' }
        <?php if ($yAxisMax !== null): ?>
        ,max: <?= $yAxisMax ?>
        <?php endif; ?>
    },
    legend: { 
        enabled: true,
        navigation: {
            activeColor: '#5a6c7d',
            inactiveColor: '#ccc',
            style: {
                color: '#5a6c7d'
            }
        }
    },
    plotOptions: {
        line: {
            marker: {
                enabled: true,
                radius: 4
            },
            lineWidth: 2,
            states: {
                hover: {
                    lineWidth: 3
                }
            },
            dataLabels: {
                enabled: false
            }
        }
    },
    series: <?= json_encode(array_map(function($s) use ($palette) {
        static $colorIndex = 0;
        $color = $s['color'] ?? $palette->getColor('color' . (($colorIndex % 20) + 1));
        $colorIndex++;
        return [
            'name' => $s['name'] ?? 'Série',
            'data' => $s['data'] ?? [],
            'color' => $color
        ];
    }, $series)) ?>,
    exporting: {
        enabled: false
    },
    credits: { 
        enabled: false 
    }
});

<?php elseif ($type === 'treemap'): ?>
// Treemap Chart
<?php
$treemapData = $series[0]['data'] ?? [];
$treemapBorderWidth = $series[0]['borderWidth'] ?? 1;
$treemapBorderColor = $series[0]['borderColor'] ?? '#fecaca';
$tooltipEnabled = !isset($chartConfig['tooltip']) || $chartConfig['tooltip'] !== false;
?>
Highcharts.chart('<?= $chartId ?>', {
    chart: {
        type: 'treemap',
        height: <?= $height ?>
    },
    title: {
        text: null
    },
    exporting: {
        enabled: false
    },
    series: [{
        type: 'treemap',
        layoutAlgorithm: 'squarified',
        data: <?= json_encode($treemapData, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]' ?>,
        dataLabels: {
            enabled: true,
            format: '{point.name}',
            style: {
                fontSize: '10px',
                fontWeight: 'normal',
                color: 'white',
                textOutline: 'none'
            }
        },
        borderWidth: <?= $treemapBorderWidth ?>,
        borderColor: '<?= $treemapBorderColor ?>',
        cursor: 'pointer'
    }],
    tooltip: {
        enabled: <?= $tooltipEnabled ? 'true' : 'false' ?>,
        pointFormat: '<b>{point.name}</b><br/>{point.value} pages'
    },
    credits: {
        enabled: false
    }
});

<?php elseif ($type === 'sankey'): ?>
// Sankey Diagram
<?php
$sankeyData = $series[0]['data'] ?? [];
$sankeyNodes = $series[0]['nodes'] ?? [];
?>
Highcharts.chart('<?= $chartId ?>', {
    chart: {
        type: 'sankey',
        height: <?= $height ?>
    },
    title: {
        text: null
    },
    accessibility: {
        point: {
            valueDescriptionFormat: '{index}. {point.from} vers {point.to}, {point.weight}.'
        }
    },
    tooltip: {
        headerFormat: null,
        pointFormatter: function() {
            var from = this.fromNode.name.replace(/_L$/, '');
            var to = this.toNode.name.replace(/_R$/, '');
            return from + ' → ' + to + ': <b>' + Highcharts.numberFormat(this.weight, 2, ',', ' ') + '%</b> des liens';
        },
        nodeFormatter: function() {
            var name = this.name.replace(/_[LR]$/, '');
            var isLeft = this.id.endsWith('_L');
            var label = isLeft ? 'des liens sortants' : 'des liens entrants';
            return name + ': <b>' + Highcharts.numberFormat(this.sum, 2, ',', ' ') + '%</b> ' + label;
        }
    },
    series: [{
        type: 'sankey',
        name: '<?= addslashes($series[0]['name'] ?? 'Flux') ?>',
        keys: ['from', 'to', 'weight'],
        data: <?= json_encode($sankeyData) ?>,
        <?php if (!empty($sankeyNodes)): ?>
        nodes: <?= json_encode($sankeyNodes) ?>,
        <?php endif; ?>
        dataLabels: {
            style: {
                color: '#333',
                textOutline: 'none',
                fontWeight: 'normal',
                fontSize: '11px'
            }
        },
        nodeWidth: 20
    }],
    exporting: {
        enabled: false
    },
    credits: {
        enabled: false
    }
});
<?php endif; ?>

// Initialiser le stockage des instances de graphiques
if (typeof window.chartInstances === 'undefined') {
    window.chartInstances = {};
}

// Stocker l'instance du graphique
window.chartInstances['<?= $chartId ?>'] = Highcharts.charts[Highcharts.charts.length - 1];

// Stocker les métadonnées du graphique (xAxisTitle, etc.)
if (typeof window.chartMetadata === 'undefined') {
    window.chartMetadata = {};
}
window.chartMetadata['<?= $chartId ?>'] = {
    xAxisTitle: '<?= addslashes($xAxisTitle ?? "X") ?>',
    title: '<?= addslashes(str_replace(["\r", "\n"], ' ', $title)) ?>',
    sqlQuery: <?= $sqlQuery ? json_encode($sqlQuery, JSON_UNESCAPED_UNICODE) : 'null' ?>,
    scopeItems: <?= $scopeItems ? json_encode($scopeItems, JSON_UNESCAPED_UNICODE) : 'null' ?>
};

// Définir les fonctions globales une seule fois
if (typeof window.downloadChart === 'undefined') {
    // Fonction pour basculer entre vue graphique et tableau
    window.toggleChartView = function(chartId) {
        const chart = window.chartInstances[chartId];
        if (!chart) return;
        
        const chartView = document.getElementById(chartId);
        const tableView = document.getElementById('table-' + chartId);
        const toggleBtn = document.getElementById('toggle-' + chartId);
        const toggleIcon = toggleBtn.querySelector('.material-symbols-outlined');
        
        if (tableView.style.display === 'none') {
            // Passer en vue tableau
            chartView.style.display = 'none';
            tableView.style.display = 'block';
            toggleIcon.textContent = 'bar_chart';
            toggleBtn.setAttribute('data-tooltip', 'Afficher le graphique');
            
            // Générer le tableau si vide
            if (!tableView.innerHTML) {
                const tableHTML = generateTableFromChart(chart, chartId);
                tableView.innerHTML = tableHTML;
            }
        } else {
            // Passer en vue graphique
            chartView.style.display = 'block';
            tableView.style.display = 'none';
            toggleIcon.textContent = 'table_chart';
            toggleBtn.setAttribute('data-tooltip', 'Afficher en tableau');
        }
    };
    
    // Fonction pour générer le tableau HTML à partir du graphique
    function generateTableFromChart(chart, chartId) {
        const series = chart.series;
        const hasCategories = chart.xAxis && chart.xAxis[0] && chart.xAxis[0].categories && chart.xAxis[0].categories.length > 0;
        const isSankey = chart.options.chart.type === 'sankey' || (series[0] && series[0].type === 'sankey');
        
        let html = '<table>';
        
        // Sankey: afficher Source, Cible, % des liens
        if (isSankey && series.length > 0 && series[0].data) {
            html += '<thead><tr><th>Catégorie source</th><th>Catégorie cible</th><th>% des liens</th></tr></thead>';
            html += '<tbody>';
            series[0].data.forEach(point => {
                let from = point.from || point.fromNode?.name || '-';
                let to = point.to || point.toNode?.name || '-';
                // Enlever les suffixes _L et _R
                from = from.replace(/_L$/, '').trim();
                to = to.replace(/_R$/, '').trim();
                const weight = point.weight !== undefined ? point.weight.toFixed(2) + '%' : '-';
                html += '<tr><td>' + from + '</td><td>' + to + '</td><td>' + weight + '</td></tr>';
            });
            html += '</tbody>';
        } else if (hasCategories) {
            // Graphique avec catégories (bar, line, horizontalBar)
            const categories = chart.xAxis[0].categories;
            
            // En-têtes
            html += '<thead><tr><th>Catégorie</th>';
            series.forEach(s => {
                if (s.name && s.visible !== false) {
                    html += '<th>' + s.name + '</th>';
                }
            });
            html += '</tr></thead>';
            
            // Données
            html += '<tbody>';
            categories.forEach((cat, index) => {
                html += '<tr><td>' + cat + '</td>';
                series.forEach(s => {
                    if (s.name && s.visible !== false) {
                        const point = s.data[index];
                        const value = point && point.y !== undefined ? point.y : (point || 0);
                        html += '<td>' + value + '</td>';
                    }
                });
                html += '</tr>';
            });
            html += '</tbody>';
        } else {
            // Graphique sans catégories (donut, area)
            if (series.length > 0 && series[0].data && series[0].data.length > 0) {
                const firstPoint = series[0].data[0];
                
                if (firstPoint.name !== undefined && firstPoint.y !== undefined) {
                    // Donut
                    html += '<thead><tr><th>Libellé</th><th>Valeur</th></tr></thead>';
                    html += '<tbody>';
                    series[0].data.forEach(point => {
                        html += '<tr><td>' + point.name + '</td><td>' + point.y + '</td></tr>';
                    });
                    html += '</tbody>';
                } else {
                    // Area avec données [x,y]
                    const xLabel = window.chartMetadata && window.chartMetadata[chartId] 
                        ? window.chartMetadata[chartId].xAxisTitle 
                        : 'X';
                    html += '<thead><tr><th>' + xLabel + '</th>';
                    series.forEach(s => {
                        if (s.name && s.visible !== false) {
                            html += '<th>' + s.name + '</th>';
                        }
                    });
                    html += '</tr></thead>';
                    
                    // Obtenir toutes les valeurs X uniques
                    const xValues = new Set();
                    series.forEach(s => {
                        if (s.visible !== false) {
                            s.data.forEach(point => {
                                const x = Array.isArray(point) ? point[0] : (point.x !== undefined ? point.x : null);
                                if (x !== null) xValues.add(x);
                            });
                        }
                    });
                    
                    const sortedX = Array.from(xValues).sort((a, b) => a - b);
                    
                    html += '<tbody>';
                    sortedX.forEach(x => {
                        html += '<tr><td>' + x + '</td>';
                        series.forEach(s => {
                            if (s.visible !== false) {
                                const point = s.data.find(p => {
                                    const px = Array.isArray(p) ? p[0] : (p.x !== undefined ? p.x : null);
                                    return px === x;
                                });
                                const y = point ? (Array.isArray(point) ? point[1] : point.y) : '';
                                html += '<td>' + (y !== '' ? y : '-') + '</td>';
                            }
                        });
                        html += '</tr>';
                    });
                    html += '</tbody>';
                }
            }
        }
        
        html += '</table>';
        return html;
    }
    
    // Fonction pour copier les données du graphique
    window.copyChartData = function(chartId, event) {
        const chart = window.chartInstances[chartId];
        if (!chart) return;
        
        let data = '';
        const series = chart.series;
        
        // Vérifier si le graphique a des catégories (axe X)
        const hasCategories = chart.xAxis && chart.xAxis[0] && chart.xAxis[0].categories && chart.xAxis[0].categories.length > 0;
        
        if (hasCategories) {
            // Graphique avec catégories (bar, line, horizontalBar)
            const categories = chart.xAxis[0].categories;
            data = 'Catégorie';
            series.forEach(s => {
                if (s.name && s.visible !== false) {
                    data += '\t' + s.name;
                }
            });
            data += '\n';
            
            // Données
            categories.forEach((cat, index) => {
                data += cat;
                series.forEach(s => {
                    if (s.name && s.visible !== false) {
                        const point = s.data[index];
                        const value = point && point.y !== undefined ? point.y : (point || 0);
                        data += '\t' + value;
                    }
                });
                data += '\n';
            });
        } else {
            // Graphique sans catégories (donut, area avec données [x,y])
            if (series.length > 0 && series[0].data && series[0].data.length > 0) {
                const firstPoint = series[0].data[0];
                
                // Vérifier si c'est un donut (points avec name et y)
                if (firstPoint.name !== undefined && firstPoint.y !== undefined) {
                    data = 'Libellé\tValeur\n';
                    series[0].data.forEach(point => {
                        data += point.name + '\t' + point.y + '\n';
                    });
                } else {
                    // Area ou line avec données [x,y]
                    const xLabel = window.chartMetadata && window.chartMetadata[chartId] 
                        ? window.chartMetadata[chartId].xAxisTitle 
                        : 'X';
                    data = xLabel;
                    series.forEach(s => {
                        if (s.name && s.visible !== false) {
                            data += '\t' + s.name;
                        }
                    });
                    data += '\n';
                    
                    // Obtenir toutes les valeurs X uniques
                    const xValues = new Set();
                    series.forEach(s => {
                        if (s.visible !== false) {
                            s.data.forEach(point => {
                                const x = Array.isArray(point) ? point[0] : (point.x !== undefined ? point.x : null);
                                if (x !== null) xValues.add(x);
                            });
                        }
                    });
                    
                    // Trier les valeurs X
                    const sortedX = Array.from(xValues).sort((a, b) => a - b);
                    
                    // Pour chaque X, trouver les Y correspondants
                    sortedX.forEach(x => {
                        data += x;
                        series.forEach(s => {
                            if (s.visible !== false) {
                                const point = s.data.find(p => {
                                    const px = Array.isArray(p) ? p[0] : (p.x !== undefined ? p.x : null);
                                    return px === x;
                                });
                                const y = point ? (Array.isArray(point) ? point[1] : point.y) : '';
                                data += '\t' + (y !== '' ? y : '');
                            }
                        });
                        data += '\n';
                    });
                }
            }
        }
        
        // Copier dans le presse-papiers
        navigator.clipboard.writeText(data).then(() => {
            // Notification globale
            if (typeof showGlobalStatus === 'function') {
                showGlobalStatus('✓ Données du graphique copiées !', 'success');
            }
        }).catch(err => {
            console.error('Erreur lors de la copie:', err);
            // Notification d'erreur
            if (typeof showGlobalStatus === 'function') {
                showGlobalStatus('Erreur lors de la copie des données', 'error');
            }
        });
    };
    
    // Fonction pour télécharger le graphique en PNG
    window.downloadChart = function(chartId) {
        const chart = window.chartInstances[chartId];
        if (chart) {
            chart.exportChart({
                type: 'image/png',
                filename: chartId
            });
        }
    };

    // Fonction pour agrandir le graphique
    window.expandChart = function(chartId, chartTitle, chartSubtitle) {
        const chart = window.chartInstances[chartId];
        if (!chart) return;
        
        // Créer la modale si elle n'existe pas
        let modal = document.getElementById('chart-modal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'chart-modal';
            modal.className = 'chart-modal';
            modal.innerHTML = `
                <div class="chart-modal-content">
                    <div class="chart-modal-header">
                        <div>
                            <h2 id="chart-modal-title">
                                <span class="material-symbols-outlined">bar_chart</span>
                                <span id="chart-modal-title-text"></span>
                            </h2>
                            <p id="chart-modal-subtitle" class="chart-modal-subtitle"></p>
                        </div>
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <button id="chart-modal-copy-btn" class="chart-action-btn" style="color: white;" title="Copier les données">
                                <span class="material-symbols-outlined">content_copy</span>
                            </button>
                            <button id="chart-modal-download-btn" class="chart-action-btn" style="color: white;" title="Enregistrer l'image">
                                <span class="material-symbols-outlined">download</span>
                            </button>
                            <button class="chart-modal-close" onclick="closeChartModal()">
                                <span class="material-symbols-outlined">close</span>
                            </button>
                        </div>
                    </div>
                    <div class="chart-modal-body">
                        <div id="chart-modal-table"></div>
                        <div id="chart-modal-container"></div>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            
            // Fermer en cliquant en dehors
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeChartModal();
                }
            });
        }
        
        // Mettre à jour les boutons avec le bon chartId (à chaque ouverture)
        document.getElementById('chart-modal-copy-btn').onclick = (e) => copyChartData(chartId, e);
        document.getElementById('chart-modal-download-btn').onclick = () => downloadChart(chartId);
        
        // Afficher la modale
        modal.classList.add('active');
        
        // Mettre à jour le titre
        document.getElementById('chart-modal-title-text').textContent = chartTitle;
        
        // Mettre à jour le sous-titre
        const subtitle = document.getElementById('chart-modal-subtitle');
        subtitle.textContent = chartSubtitle || '';
        subtitle.style.display = chartSubtitle ? 'block' : 'none';
        
        // Générer et afficher le tableau
        const tableContainer = document.getElementById('chart-modal-table');
        const tableHTML = generateTableFromChart(chart, chartId);
        tableContainer.innerHTML = tableHTML;
        
        // Créer une copie du graphique dans la modale
        const container = document.getElementById('chart-modal-container');
        container.innerHTML = '';
        
        // Obtenir la configuration du graphique original et forcer la hauteur 100%
        const options = JSON.parse(JSON.stringify(chart.options));
        
        // Créer un nouveau graphique dans la modale avec hauteur auto
        setTimeout(() => {
            // Calculer la hauteur disponible
            const containerHeight = container.offsetHeight || container.parentElement.offsetHeight;
            options.chart = options.chart || {};
            options.chart.height = containerHeight > 0 ? containerHeight : null;
            
            Highcharts.chart('chart-modal-container', options);
        }, 150);
    };

    // Fonction pour fermer la modale
    window.closeChartModal = function() {
        const modal = document.getElementById('chart-modal');
        if (modal) {
            modal.classList.remove('active');
        }
    };

    // Fermer avec la touche Escape (une seule fois)
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeChartModal();
            closeChartSqlModal();
        }
    });

    // Fonction pour afficher la modale SQL Scope (utilise le composant partagé)
    window.showChartSqlScope = function(chartId) {
        const metadata = window.chartMetadata[chartId];
        if (!metadata) return;
        
        // Utiliser le composant scope-modal partagé
        if (typeof openScopeModal === 'function') {
            openScopeModal({
                title: metadata.title || 'Scope des données',
                scopeItems: metadata.scopeItems,
                sqlQuery: metadata.sqlQuery
            });
        }
    };
    
    // Compatibilité avec l'ancien nom
    window.closeChartSqlModal = function() {
        if (typeof closeScopeModal === 'function') {
            closeScopeModal();
        }
    };
}
</script>
