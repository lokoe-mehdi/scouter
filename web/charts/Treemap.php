<?php
namespace Charts;

/**
 * Classe Treemap - Graphique de type treemap/heatmap
 * 
 * Affiche des rectangles proportionnels aux valeurs avec gradient de couleur.
 * Idéal pour visualiser des distributions hiérarchiques ou des clusters.
 */
class Treemap extends Chart
{
    private $data;
    private $title;
    private $subtitle;
    private $colorAxis;

    /**
     * @param string $title Titre du graphique
     * @param array $data Tableau d'objets avec: name, value, colorValue (optionnel)
     * @param string $subtitle Sous-titre optionnel
     * @param array $colorAxis Configuration des couleurs [minColor, maxColor]
     */
    public function __construct($title, $data, $subtitle = "", $colorAxis = null)
    {
        $this->title = $title;
        $this->data = $data;
        $this->subtitle = $subtitle;
        $this->colorAxis = $colorAxis ?? ['minColor' => '#fca5a5', 'maxColor' => '#dc2626'];
    }

    /**
     * Génère le HTML/JS pour afficher le treemap
     * 
     * @param string $id ID unique du container
     * @param int $height Hauteur en pixels (défaut: 400)
     */
    public function draw($id, $height = 400)
    {
        // Préparer les données
        $treemapData = [];
        foreach ($this->data as $index => $item) {
            $entry = [
                'name' => $item->name ?? $item['name'] ?? 'Item ' . ($index + 1),
                'value' => $item->value ?? $item['value'] ?? 0,
                'colorValue' => $item->colorValue ?? $item['colorValue'] ?? $index
            ];
            
            // Ajouter des propriétés custom si présentes
            if (isset($item->clusterId) || isset($item['clusterId'])) {
                $entry['clusterId'] = $item->clusterId ?? $item['clusterId'];
            }
            
            $treemapData[] = $entry;
        }

        $dataJson = json_encode($treemapData, JSON_UNESCAPED_UNICODE);
        $colorAxisJson = json_encode($this->colorAxis);
        $titleEscaped = htmlspecialchars($this->title);
        $subtitleEscaped = htmlspecialchars($this->subtitle);

        $html = <<<HTML
<div id="{$id}" style="min-width: 300px; height: {$height}px; margin: 0 auto"></div>

<script type="text/javascript">
Highcharts.chart('{$id}', {
    chart: {
        type: 'treemap',
        height: {$height}
    },
    title: {
        text: '{$titleEscaped}'
    },
    subtitle: {
        text: '{$subtitleEscaped}'
    },
    colorAxis: {$colorAxisJson},
    series: [{
        type: 'treemap',
        layoutAlgorithm: 'squarified',
        data: {$dataJson},
        dataLabels: {
            enabled: true,
            format: '{point.name}: {point.value}',
            style: {
                fontSize: '12px',
                fontWeight: 'bold',
                color: 'white',
                textOutline: '2px contrast'
            }
        },
        borderWidth: 2,
        borderColor: '#ffffff',
        cursor: 'pointer',
        point: {
            events: {
                click: function() {
                    if (typeof this.options.clusterId !== 'undefined' && typeof scrollToCluster === 'function') {
                        scrollToCluster(this.options.clusterId);
                    }
                }
            }
        }
    }],
    tooltip: {
        pointFormat: '<b>{point.name}</b><br/>Valeur: {point.value}'
    },
    credits: {
        enabled: false
    }
});
</script>
HTML;

        echo $html;
    }

    /**
     * Retourne les données du treemap
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * Retourne le titre
     */
    public function getTitle()
    {
        return $this->title;
    }
}
