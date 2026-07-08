<?php
/**
 * PERFORMANCE — Response time (TTFB) × Search Console.
 * Do slow pages capture fewer clicks / a worse position? Lists slow, visible
 * URLs where speed is likely capping performance.
 */
$perf = \App\Gsc\PerformanceReport::context((int) $crawlId, !empty($useCh));
if (!$perf['available']) { \App\Gsc\PerformanceReport::renderUnavailable($perf); return; }
require_once __DIR__ . '/../partials/performance-helpers.php';

$from = $perf['from']; $to = $perf['to'];
$gscAgg = \App\Gsc\PerformanceReport::gscPageAgg($from, $to);
$wpos   = \App\Gsc\PerformanceReport::weightedPos('g');
$pAll   = [':crawl_id' => (int) $crawlId, ':from' => $from, ':to' => $to];
// Speed only meaningful on real HTML 200s.
$base   = "FROM pages p LEFT JOIN ( {$gscAgg} ) g ON g.page = p.url WHERE p.crawl_id = :crawl_id AND p.crawled = true AND p.in_crawl = TRUE AND p.code = 200 AND p.is_html = true";
$metrics = "count() AS urls, sum(g.clicks) AS clicks, sum(g.impressions) AS impressions, {$wpos} AS position";

$bucketExpr = "multiIf(p.response_time < 200, '1_fast', p.response_time < 600, '2_medium', '3_slow')";
$sqlBuckets = "SELECT {$bucketExpr} AS bucket, {$metrics} {$base} GROUP BY bucket ORDER BY bucket";
$stmt = $pdo->prepare($sqlBuckets); $stmt->execute($pAll); $raw = $stmt->fetchAll();

$labels = ['1_fast' => __('performance.rt_fast'), '2_medium' => __('performance.rt_medium'), '3_slow' => __('performance.rt_slow')];
$colors = ['1_fast' => '#6bd899', '2_medium' => '#d8bf6b', '3_slow' => '#d86b6b'];
$rows = [];
foreach ($raw as $r) {
    $rows[] = [
        'label' => $labels[$r->bucket] ?? $r->bucket, 'color' => $colors[$r->bucket] ?? '#95a5a6',
        'urls' => $r->urls, 'clicks' => $r->clicks, 'impressions' => $r->impressions,
        'ctr' => ((int) $r->impressions > 0 ? (int) $r->clicks / (int) $r->impressions : 0), 'position' => $r->position,
    ];
}

?>

<h1 class="page-title"><?= __('performance.response_time_title') ?></h1>
<?php include __DIR__ . '/../partials/performance-controls.php'; ?>
<div class="perf-note"><span class="material-symbols-outlined">info</span><span><?= __('performance.response_time_note') ?></span></div>

<div style="display:flex; flex-direction:column; gap:1.5rem;">
    <?php
    perf_render_buckets([
        'rows' => $rows, 'sql' => $sqlBuckets, 'dimLabel' => __('performance.dim_response_time'),
        'clicksTitle' => __('performance.rt_clicks_title'), 'clicksSubtitle' => __('performance.rt_clicks_subtitle'),
        'imprTitle' => __('performance.rt_impr_title'), 'imprSubtitle' => __('performance.rt_impr_subtitle'),
        'tableTitle' => __('performance.rt_table_title'), 'tableSubtitle' => __('performance.rt_table_subtitle'),
    ]);

    perf_url_table([
        'id' => 'perf_rt_problem',
        'title' => __('performance.rt_problem_title'),
        'whereClause' => "WHERE c.crawled = true AND c.in_crawl = TRUE AND c.code = 200 AND c.is_html = true AND c.response_time >= 600 AND g.impressions > 0",
        'orderBy' => 'ORDER BY g.impressions DESC',
        'extraColumns' => ['response_time'],
        'gscJoin' => $gscAgg, 'pdo' => $pdo, 'crawlId' => (int) $crawlId,
    ]);
    ?>
</div>
