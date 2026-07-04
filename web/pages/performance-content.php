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
$gscAgg = \App\Gsc\PerformanceReport::gscPageAgg();
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

$sqlProblem = "
    SELECT p.url AS url, p.category AS category, p.word_count AS wc,
           g.clicks AS clicks, g.impressions AS impressions,
           if(g.impressions = 0, 0, g.clicks / g.impressions) AS ctr,
           if(g.impressions = 0, 0, g.pos_num / g.impressions) AS position
    FROM pages p INNER JOIN ( {$gscAgg} ) g ON g.page = p.url
    WHERE p.crawl_id = :crawl_id AND p.crawled = true AND p.in_crawl = TRUE AND p.code = 200 AND p.is_html = true
      AND p.word_count < 200 AND g.impressions > 0
    ORDER BY g.impressions DESC LIMIT 100";
$stmt = $pdo->prepare($sqlProblem); $stmt->execute($pAll); $problem = $stmt->fetchAll();
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

    $prows = [];
    foreach ($problem as $r) {
        $prows[] = [
            'url' => perf_url_cell($r->url),
            'category' => ($r->category ?? '') !== '' ? $r->category : __('common.uncategorized'),
            'category_color' => getCategoryColor(($r->category ?? '') !== '' ? $r->category : ''),
            'wc' => perf_num($r->wc),
            'clicks' => perf_num($r->clicks), 'impressions' => perf_num($r->impressions),
            'ctr' => perf_ctr($r->ctr), 'position' => perf_pos($r->position),
        ];
    }
    perf_render_url_table($prows, [
        'title' => __('performance.content_problem_title'), 'subtitle' => __('performance.content_problem_subtitle'),
        'maxLines' => 15, 'extraColumns' => [['key' => 'wc', 'label' => __('performance.col_word_count'), 'type' => 'badge-warning']],
    ]);
    ?>
</div>
