<?php
/**
 * PERFORMANCE — Indexability × Search Console.
 * Do the clicks / impressions land on INDEXABLE pages? Surfaces non-indexable
 * URLs (noindex, blocked, non-200…) that Google still shows — lost potential.
 */
$perf = \App\Gsc\PerformanceReport::context((int) $crawlId, !empty($useCh));
if (!$perf['available']) { \App\Gsc\PerformanceReport::renderUnavailable($perf); return; }
require_once __DIR__ . '/../partials/performance-helpers.php';

$from = $perf['from']; $to = $perf['to'];
$gscAgg = \App\Gsc\PerformanceReport::gscPageAgg();
$wpos   = \App\Gsc\PerformanceReport::weightedPos('g');
$pAll   = [':crawl_id' => (int) $crawlId, ':from' => $from, ':to' => $to];
$base   = "FROM pages p LEFT JOIN ( {$gscAgg} ) g ON g.page = p.url WHERE p.crawl_id = :crawl_id AND p.crawled = true AND p.in_crawl = TRUE";
$metrics = "count() AS urls, sum(g.clicks) AS clicks, sum(g.impressions) AS impressions, {$wpos} AS position";

// Indexability status (priority order: reason a page is NOT indexable).
$bucketExpr = "multiIf(p.compliant = 1, 'indexable', p.noindex = 1, 'noindex', p.blocked = 1, 'blocked', p.code != 200, 'non_200', 'other')";
$sqlBuckets = "SELECT {$bucketExpr} AS bucket, {$metrics} {$base} GROUP BY bucket ORDER BY sum(g.impressions) DESC";
$stmt = $pdo->prepare($sqlBuckets); $stmt->execute($pAll); $raw = $stmt->fetchAll();

$labels = [
    'indexable'     => __('performance.idx_indexable'),
    'noindex'       => __('performance.idx_noindex'),
    'blocked'       => __('performance.idx_blocked'),
    'non_200'       => __('performance.idx_non_200'),
    'other'         => __('performance.idx_other'),
];
$colors = ['indexable' => '#6bd899', 'noindex' => '#d8bf6b', 'blocked' => '#e0a86b', 'non_200' => '#d86b6b', 'other' => '#95a5a6'];

$rows = [];
foreach ($raw as $r) {
    $rows[] = [
        'label' => $labels[$r->bucket] ?? $r->bucket, 'color' => $colors[$r->bucket] ?? '#95a5a6',
        'urls' => $r->urls, 'clicks' => $r->clicks, 'impressions' => $r->impressions,
        'ctr' => ((int) $r->impressions > 0 ? (int) $r->clicks / (int) $r->impressions : 0), 'position' => $r->position,
    ];
}

// Non-indexable URLs that still receive impressions (the actionable list).
$sqlProblem = "
    SELECT p.url AS url, p.category AS category,
           multiIf(p.noindex = 1, 'noindex', p.blocked = 1, 'blocked', p.code != 200, concat(toString(p.code)), 'other') AS reason,
           g.clicks AS clicks, g.impressions AS impressions,
           if(g.impressions = 0, 0, g.clicks / g.impressions) AS ctr,
           if(g.impressions = 0, 0, g.pos_num / g.impressions) AS position
    FROM pages p INNER JOIN ( {$gscAgg} ) g ON g.page = p.url
    WHERE p.crawl_id = :crawl_id AND p.crawled = true AND p.in_crawl = TRUE AND p.compliant = 0 AND g.impressions > 0
    ORDER BY g.impressions DESC LIMIT 100";
$stmt = $pdo->prepare($sqlProblem); $stmt->execute($pAll); $problem = $stmt->fetchAll();
?>

<h1 class="page-title"><?= __('performance.indexability_title') ?></h1>
<?php include __DIR__ . '/../partials/performance-controls.php'; ?>
<div class="perf-note"><span class="material-symbols-outlined">info</span><span><?= __('performance.indexability_note') ?></span></div>

<div style="display:flex; flex-direction:column; gap:1.5rem;">
    <?php
    perf_render_buckets([
        'rows' => $rows, 'sql' => $sqlBuckets, 'dimLabel' => __('performance.dim_indexability'),
        'clicksTitle' => __('performance.idx_clicks_title'), 'clicksSubtitle' => __('performance.idx_clicks_subtitle'),
        'imprTitle' => __('performance.idx_impr_title'), 'imprSubtitle' => __('performance.idx_impr_subtitle'),
        'tableTitle' => __('performance.idx_table_title'), 'tableSubtitle' => __('performance.idx_table_subtitle'),
    ]);

    $prows = [];
    foreach ($problem as $r) {
        $prows[] = [
            'url' => perf_url_cell($r->url),
            'category' => ($r->category ?? '') !== '' ? $r->category : __('common.uncategorized'),
            'category_color' => getCategoryColor(($r->category ?? '') !== '' ? $r->category : ''),
            'reason' => $r->reason,
            'clicks' => perf_num($r->clicks), 'impressions' => perf_num($r->impressions),
            'ctr' => perf_ctr($r->ctr), 'position' => perf_pos($r->position),
        ];
    }
    perf_render_url_table($prows, [
        'title' => __('performance.idx_problem_title'), 'subtitle' => __('performance.idx_problem_subtitle'),
        'maxLines' => 15, 'extraColumns' => [['key' => 'reason', 'label' => __('performance.col_reason'), 'type' => 'badge-warning']],
    ]);
    ?>
</div>
