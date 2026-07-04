<?php
/**
 * PERFORMANCE — Internal PageRank × Search Console.
 * Is internal link authority (computed PageRank) converting into clicks /
 * impressions? Tiers are impression-weighted quantiles of `pri`. Surfaces
 * high-authority pages that earn no clicks (mis-spent internal linking).
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

// Quantile thresholds of PageRank across the crawl's indexable pages.
$sqlBuckets = "
    WITH (SELECT quantilesExact(0.5, 0.8, 0.95)(pri) FROM pages
          WHERE crawl_id = :crawl_id AND crawled = true AND in_crawl = TRUE AND compliant = 1) AS q
    SELECT multiIf(p.pri <= q[1], '1_low', p.pri <= q[2], '2_medium', p.pri <= q[3], '3_high', '4_top') AS bucket,
           {$metrics} {$base} GROUP BY bucket ORDER BY bucket";
$stmt = $pdo->prepare($sqlBuckets); $stmt->execute($pAll); $raw = $stmt->fetchAll();

$labels = ['1_low' => __('performance.pr_low'), '2_medium' => __('performance.pr_medium'), '3_high' => __('performance.pr_high'), '4_top' => __('performance.pr_top')];
$colors = ['1_low' => '#cfe8e6', '2_medium' => '#8fd8d1', '3_high' => '#4ECDC4', '4_top' => '#2b9e95'];
$rows = [];
foreach ($raw as $r) {
    $rows[] = [
        'label' => $labels[$r->bucket] ?? $r->bucket, 'color' => $colors[$r->bucket] ?? '#95a5a6',
        'urls' => $r->urls, 'clicks' => $r->clicks, 'impressions' => $r->impressions,
        'ctr' => ((int) $r->impressions > 0 ? (int) $r->clicks / (int) $r->impressions : 0), 'position' => $r->position,
    ];
}

// High internal authority, but no clicks — authority not converting.
$sqlProblem = "
    SELECT p.url AS url, p.category AS category, round(p.pri, 4) AS pri, p.inlinks AS inlinks,
           g.clicks AS clicks, g.impressions AS impressions,
           if(g.impressions = 0, 0, g.clicks / g.impressions) AS ctr,
           if(g.impressions = 0, 0, g.pos_num / g.impressions) AS position
    FROM pages p LEFT JOIN ( {$gscAgg} ) g ON g.page = p.url
    WHERE p.crawl_id = :crawl_id AND p.crawled = true AND p.in_crawl = TRUE AND p.compliant = 1
      AND (g.clicks = 0 OR isNull(g.clicks))
    ORDER BY p.pri DESC LIMIT 100";
$stmt = $pdo->prepare($sqlProblem); $stmt->execute($pAll); $problem = $stmt->fetchAll();
?>

<h1 class="page-title"><?= __('performance.pagerank_title') ?></h1>
<?php include __DIR__ . '/../partials/performance-controls.php'; ?>
<div class="perf-note"><span class="material-symbols-outlined">info</span><span><?= __('performance.pagerank_note') ?></span></div>

<div style="display:flex; flex-direction:column; gap:1.5rem;">
    <?php
    perf_render_buckets([
        'rows' => $rows, 'sql' => $sqlBuckets, 'dimLabel' => __('performance.dim_pagerank'),
        'clicksTitle' => __('performance.pr_clicks_title'), 'clicksSubtitle' => __('performance.pr_clicks_subtitle'),
        'imprTitle' => __('performance.pr_impr_title'), 'imprSubtitle' => __('performance.pr_impr_subtitle'),
        'tableTitle' => __('performance.pr_table_title'), 'tableSubtitle' => __('performance.pr_table_subtitle'),
    ]);

    $prows = [];
    foreach ($problem as $r) {
        $prows[] = [
            'url' => perf_url_cell($r->url),
            'category' => ($r->category ?? '') !== '' ? $r->category : __('common.uncategorized'),
            'category_color' => getCategoryColor(($r->category ?? '') !== '' ? $r->category : ''),
            'pri' => number_format((float) $r->pri, 4, '.', ' '),
            'clicks' => perf_num($r->clicks), 'impressions' => perf_num($r->impressions),
            'ctr' => perf_ctr($r->ctr), 'position' => perf_pos($r->position),
        ];
    }
    perf_render_url_table($prows, [
        'title' => __('performance.pr_problem_title'), 'subtitle' => __('performance.pr_problem_subtitle'),
        'maxLines' => 15, 'extraColumns' => [['key' => 'pri', 'label' => __('performance.col_pagerank'), 'type' => 'badge-info']],
    ]);
    ?>
</div>
