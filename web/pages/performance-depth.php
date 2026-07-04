<?php
/**
 * PERFORMANCE — Crawl depth × Search Console.
 * How clicks / impressions distribute across click-depth from the start URL.
 * Deep pages that still earn traffic are candidates to surface higher.
 */
$perf = \App\Gsc\PerformanceReport::context((int) $crawlId, !empty($useCh));
if (!$perf['available']) { \App\Gsc\PerformanceReport::renderUnavailable($perf); return; }
require_once __DIR__ . '/../partials/performance-helpers.php';

$from = $perf['from']; $to = $perf['to'];
$gscAgg = \App\Gsc\PerformanceReport::gscPageAgg();
$wpos   = \App\Gsc\PerformanceReport::weightedPos('g');
$pAll   = [':crawl_id' => (int) $crawlId, ':from' => $from, ':to' => $to];
$base   = "FROM pages p LEFT JOIN ( {$gscAgg} ) g ON g.page = p.url WHERE p.crawl_id = :crawl_id AND p.crawled = true AND p.in_crawl = TRUE AND p.depth >= 0";
$metrics = "count() AS urls, sum(g.clicks) AS clicks, sum(g.impressions) AS impressions, {$wpos} AS position";

$sqlBuckets = "SELECT toString(p.depth) AS bucket, {$metrics} {$base} GROUP BY bucket ORDER BY min(p.depth)";
$stmt = $pdo->prepare($sqlBuckets); $stmt->execute($pAll); $raw = $stmt->fetchAll();

// Shallow → deep colour ramp (teal to red).
$ramp = ['#4ECDC4', '#6bd899', '#c9d86b', '#d8bf6b', '#e0a86b', '#d86b6b', '#c0504d'];
$rows = [];
foreach ($raw as $i => $r) {
    $rows[] = [
        'label' => __('performance.depth_level', ['n' => (int) $r->bucket]),
        'color' => $ramp[min($i, count($ramp) - 1)],
        'urls' => $r->urls, 'clicks' => $r->clicks, 'impressions' => $r->impressions,
        'ctr' => ((int) $r->impressions > 0 ? (int) $r->clicks / (int) $r->impressions : 0), 'position' => $r->position,
    ];
}

// Deep pages that still earn clicks (surface-higher opportunities).
$sqlProblem = "
    SELECT p.url AS url, p.category AS category, p.depth AS depth,
           g.clicks AS clicks, g.impressions AS impressions,
           if(g.impressions = 0, 0, g.clicks / g.impressions) AS ctr,
           if(g.impressions = 0, 0, g.pos_num / g.impressions) AS position
    FROM pages p INNER JOIN ( {$gscAgg} ) g ON g.page = p.url
    WHERE p.crawl_id = :crawl_id AND p.crawled = true AND p.in_crawl = TRUE AND p.depth >= 3 AND g.clicks > 0
    ORDER BY g.clicks DESC LIMIT 100";
$stmt = $pdo->prepare($sqlProblem); $stmt->execute($pAll); $problem = $stmt->fetchAll();
?>

<h1 class="page-title"><?= __('performance.depth_title') ?></h1>
<?php include __DIR__ . '/../partials/performance-controls.php'; ?>
<div class="perf-note"><span class="material-symbols-outlined">info</span><span><?= __('performance.depth_note') ?></span></div>

<div style="display:flex; flex-direction:column; gap:1.5rem;">
    <?php
    perf_render_buckets([
        'rows' => $rows, 'sql' => $sqlBuckets, 'dimLabel' => __('performance.dim_depth'),
        'clicksTitle' => __('performance.depth_clicks_title'), 'clicksSubtitle' => __('performance.depth_clicks_subtitle'),
        'imprTitle' => __('performance.depth_impr_title'), 'imprSubtitle' => __('performance.depth_impr_subtitle'),
        'tableTitle' => __('performance.depth_table_title'), 'tableSubtitle' => __('performance.depth_table_subtitle'),
    ]);

    $prows = [];
    foreach ($problem as $r) {
        $prows[] = [
            'url' => perf_url_cell($r->url),
            'category' => ($r->category ?? '') !== '' ? $r->category : __('common.uncategorized'),
            'category_color' => getCategoryColor(($r->category ?? '') !== '' ? $r->category : ''),
            'depth' => (string) $r->depth,
            'clicks' => perf_num($r->clicks), 'impressions' => perf_num($r->impressions),
            'ctr' => perf_ctr($r->ctr), 'position' => perf_pos($r->position),
        ];
    }
    perf_render_url_table($prows, [
        'title' => __('performance.depth_problem_title'), 'subtitle' => __('performance.depth_problem_subtitle'),
        'maxLines' => 15, 'extraColumns' => [['key' => 'depth', 'label' => __('performance.col_depth'), 'type' => 'badge-info']],
    ]);
    ?>
</div>
