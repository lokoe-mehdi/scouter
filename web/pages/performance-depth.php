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
$gscAgg = \App\Gsc\PerformanceReport::gscPageAgg($from, $to);
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

    perf_url_table([
        'id' => 'perf_depth_problem',
        'title' => __('performance.depth_problem_title'),
        'whereClause' => "WHERE c.crawled = true AND c.in_crawl = TRUE AND c.depth >= 3 AND g.clicks > 0",
        'orderBy' => 'ORDER BY g.clicks DESC',
        'extraColumns' => ['depth'],
        'gscJoin' => $gscAgg, 'pdo' => $pdo, 'crawlId' => (int) $crawlId,
    ]);
    ?>
</div>
