<?php
/**
 * PERFORMANCE — Content richness (word count) × Search Console.
 * Does thin content under-earn? Lists thin pages that already get impressions —
 * the best candidates to enrich.
 */
$perf = \App\Gsc\PerformanceReport::context((int) $crawlId, !empty($useCh));
if (!$perf['available']) { \App\Gsc\PerformanceReport::renderUnavailable($perf); return; }
require_once __DIR__ . '/../partials/performance-helpers.php';

$from = $perf['from']; $to = $perf['to'];
$gscAgg = \App\Gsc\PerformanceReport::gscPageAgg($from, $to);
$wpos   = \App\Gsc\PerformanceReport::weightedPos('g');
$pAll   = [':crawl_id' => (int) $crawlId, ':from' => $from, ':to' => $to];
$base   = "FROM pages p LEFT JOIN ( {$gscAgg} ) g ON g.page = p.url WHERE p.crawl_id = :crawl_id AND p.crawled = true AND p.in_crawl = TRUE AND p.code = 200 AND p.is_html = true";
$metrics = "count() AS urls, sum(g.clicks) AS clicks, sum(g.impressions) AS impressions, {$wpos} AS position";

$bucketExpr = "multiIf(p.word_count < 200, '1_thin', p.word_count < 500, '2_low', p.word_count < 1000, '3_medium', '4_rich')";
$sqlBuckets = "SELECT {$bucketExpr} AS bucket, {$metrics} {$base} GROUP BY bucket ORDER BY bucket";
$stmt = $pdo->prepare($sqlBuckets); $stmt->execute($pAll); $raw = $stmt->fetchAll();

$labels = ['1_thin' => __('performance.wc_thin'), '2_low' => __('performance.wc_low'), '3_medium' => __('performance.wc_medium'), '4_rich' => __('performance.wc_rich')];
$colors = ['1_thin' => '#d86b6b', '2_low' => '#d8bf6b', '3_medium' => '#4ECDC4', '4_rich' => '#6bd899'];
$rows = [];
foreach ($raw as $r) {
    $rows[] = [
        'label' => $labels[$r->bucket] ?? $r->bucket, 'color' => $colors[$r->bucket] ?? '#95a5a6',
        'urls' => $r->urls, 'clicks' => $r->clicks, 'impressions' => $r->impressions,
        'ctr' => ((int) $r->impressions > 0 ? (int) $r->clicks / (int) $r->impressions : 0), 'position' => $r->position,
    ];
}

?>

<h1 class="page-title"><?= __('performance.content_title') ?></h1>
<?php include __DIR__ . '/../partials/performance-controls.php'; ?>
<div class="perf-note"><span class="material-symbols-outlined">info</span><span><?= __('performance.content_note') ?></span></div>

<div style="display:flex; flex-direction:column; gap:1.5rem;">
    <?php
    perf_render_buckets([
        'rows' => $rows, 'sql' => $sqlBuckets, 'dimLabel' => __('performance.dim_word_count'),
        'clicksTitle' => __('performance.content_clicks_title'), 'clicksSubtitle' => __('performance.content_clicks_subtitle'),
        'imprTitle' => __('performance.content_impr_title'), 'imprSubtitle' => __('performance.content_impr_subtitle'),
        'tableTitle' => __('performance.content_table_title'), 'tableSubtitle' => __('performance.content_table_subtitle'),
    ]);

    perf_url_table([
        'id' => 'perf_content_problem',
        'title' => __('performance.content_problem_title'),
        'whereClause' => "WHERE c.crawled = true AND c.in_crawl = TRUE AND c.code = 200 AND c.is_html = true AND c.word_count < 200 AND g.impressions > 0",
        'orderBy' => 'ORDER BY g.impressions DESC',
        'extraColumns' => ['word_count'],
        'gscJoin' => $gscAgg, 'pdo' => $pdo, 'crawlId' => (int) $crawlId,
    ]);
    ?>
</div>
