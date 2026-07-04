<?php
/**
 * PERFORMANCE — Internal inlinks × Search Console.
 * Correlate how many internal links point to a page with the clicks / impressions
 * it earns. Surfaces under-linked pages that already perform (link them more).
 */
$perf = \App\Gsc\PerformanceReport::context((int) $crawlId, !empty($useCh));
if (!$perf['available']) { \App\Gsc\PerformanceReport::renderUnavailable($perf); return; }
require_once __DIR__ . '/../partials/performance-helpers.php';

$from = $perf['from']; $to = $perf['to'];
$gscAgg = \App\Gsc\PerformanceReport::gscPageAgg();
$wpos   = \App\Gsc\PerformanceReport::weightedPos('g');
$pAll   = [':crawl_id' => (int) $crawlId, ':from' => $from, ':to' => $to];
$base   = "FROM pages p LEFT JOIN ( {$gscAgg} ) g ON g.page = p.url WHERE p.crawl_id = :crawl_id AND p.crawled = true AND p.in_crawl = TRUE AND p.compliant = 1";
$metrics = "count() AS urls, sum(g.clicks) AS clicks, sum(g.impressions) AS impressions, {$wpos} AS position";

$bucketExpr = "multiIf(p.inlinks = 0, '1_none', p.inlinks < 5, '2_low', p.inlinks < 20, '3_medium', '4_high')";
$sqlBuckets = "SELECT {$bucketExpr} AS bucket, {$metrics} {$base} GROUP BY bucket ORDER BY bucket";
$stmt = $pdo->prepare($sqlBuckets); $stmt->execute($pAll); $raw = $stmt->fetchAll();

$labels = ['1_none' => __('performance.inlinks_none'), '2_low' => __('performance.inlinks_low'), '3_medium' => __('performance.inlinks_medium'), '4_high' => __('performance.inlinks_high')];
$colors = ['1_none' => '#d86b6b', '2_low' => '#d8bf6b', '3_medium' => '#4ECDC4', '4_high' => '#6bd899'];
$rows = [];
foreach ($raw as $r) {
    $rows[] = [
        'label' => $labels[$r->bucket] ?? $r->bucket, 'color' => $colors[$r->bucket] ?? '#95a5a6',
        'urls' => $r->urls, 'clicks' => $r->clicks, 'impressions' => $r->impressions,
        'ctr' => ((int) $r->impressions > 0 ? (int) $r->clicks / (int) $r->impressions : 0), 'position' => $r->position,
    ];
}

// Under-linked winners: pages that earn clicks with few internal links.
$sqlProblem = "
    SELECT p.url AS url, p.category AS category, p.inlinks AS inlinks,
           g.clicks AS clicks, g.impressions AS impressions,
           if(g.impressions = 0, 0, g.clicks / g.impressions) AS ctr,
           if(g.impressions = 0, 0, g.pos_num / g.impressions) AS position
    FROM pages p INNER JOIN ( {$gscAgg} ) g ON g.page = p.url
    WHERE p.crawl_id = :crawl_id AND p.crawled = true AND p.in_crawl = TRUE AND p.compliant = 1
      AND p.inlinks <= 1 AND g.clicks > 0
    ORDER BY g.clicks DESC LIMIT 100";
$stmt = $pdo->prepare($sqlProblem); $stmt->execute($pAll); $problem = $stmt->fetchAll();
?>

<h1 class="page-title"><?= __('performance.inlinks_title') ?></h1>
<?php include __DIR__ . '/../partials/performance-controls.php'; ?>
<div class="perf-note"><span class="material-symbols-outlined">info</span><span><?= __('performance.inlinks_note') ?></span></div>

<div style="display:flex; flex-direction:column; gap:1.5rem;">
    <?php
    perf_render_buckets([
        'rows' => $rows, 'sql' => $sqlBuckets, 'dimLabel' => __('performance.dim_inlinks'),
        'clicksTitle' => __('performance.inlinks_clicks_title'), 'clicksSubtitle' => __('performance.inlinks_clicks_subtitle'),
        'imprTitle' => __('performance.inlinks_impr_title'), 'imprSubtitle' => __('performance.inlinks_impr_subtitle'),
        'tableTitle' => __('performance.inlinks_table_title'), 'tableSubtitle' => __('performance.inlinks_table_subtitle'),
    ]);

    $prows = [];
    foreach ($problem as $r) {
        $prows[] = [
            'url' => perf_url_cell($r->url),
            'category' => ($r->category ?? '') !== '' ? $r->category : __('common.uncategorized'),
            'category_color' => getCategoryColor(($r->category ?? '') !== '' ? $r->category : ''),
            'inlinks' => (string) $r->inlinks,
            'clicks' => perf_num($r->clicks), 'impressions' => perf_num($r->impressions),
            'ctr' => perf_ctr($r->ctr), 'position' => perf_pos($r->position),
        ];
    }
    perf_render_url_table($prows, [
        'title' => __('performance.inlinks_problem_title'), 'subtitle' => __('performance.inlinks_problem_subtitle'),
        'maxLines' => 15, 'extraColumns' => [['key' => 'inlinks', 'label' => __('performance.col_inlinks'), 'type' => 'badge-info']],
    ]);
    ?>
</div>
