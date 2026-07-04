<?php
/**
 * PERFORMANCE — HTTP status codes × Search Console.
 * Where do clicks / impressions land by response-code family? Traffic reaching
 * 3xx/4xx/5xx URLs is wasted or broken and worth fixing first.
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

$sqlBuckets = "SELECT concat(toString(intDiv(p.code, 100)), 'xx') AS bucket, {$metrics} {$base} GROUP BY bucket ORDER BY min(p.code)";
$stmt = $pdo->prepare($sqlBuckets); $stmt->execute($pAll); $raw = $stmt->fetchAll();

$rows = [];
foreach ($raw as $r) {
    $family = (int) substr((string) $r->bucket, 0, 1);
    $rows[] = [
        'label' => $r->bucket . ' — ' . getCodeLabel($family * 100 ?: 0),
        'color' => getCodeColor($family * 100 ?: 0),
        'urls' => $r->urls, 'clicks' => $r->clicks, 'impressions' => $r->impressions,
        'ctr' => ((int) $r->impressions > 0 ? (int) $r->clicks / (int) $r->impressions : 0), 'position' => $r->position,
    ];
}

// URLs that draw impressions but don't answer 200 (redirects / errors).
$sqlProblem = "
    SELECT p.url AS url, p.category AS category, p.code AS code,
           g.clicks AS clicks, g.impressions AS impressions,
           if(g.impressions = 0, 0, g.clicks / g.impressions) AS ctr,
           if(g.impressions = 0, 0, g.pos_num / g.impressions) AS position
    FROM pages p INNER JOIN ( {$gscAgg} ) g ON g.page = p.url
    WHERE p.crawl_id = :crawl_id AND p.crawled = true AND p.in_crawl = TRUE AND p.code != 200 AND g.impressions > 0
    ORDER BY g.impressions DESC LIMIT 100";
$stmt = $pdo->prepare($sqlProblem); $stmt->execute($pAll); $problem = $stmt->fetchAll();
?>

<h1 class="page-title"><?= __('performance.codes_title') ?></h1>
<?php include __DIR__ . '/../partials/performance-controls.php'; ?>
<div class="perf-note"><span class="material-symbols-outlined">info</span><span><?= __('performance.codes_note') ?></span></div>

<div style="display:flex; flex-direction:column; gap:1.5rem;">
    <?php
    perf_render_buckets([
        'rows' => $rows, 'sql' => $sqlBuckets, 'dimLabel' => __('performance.dim_code'),
        'clicksTitle' => __('performance.codes_clicks_title'), 'clicksSubtitle' => __('performance.codes_clicks_subtitle'),
        'imprTitle' => __('performance.codes_impr_title'), 'imprSubtitle' => __('performance.codes_impr_subtitle'),
        'tableTitle' => __('performance.codes_table_title'), 'tableSubtitle' => __('performance.codes_table_subtitle'),
    ]);

    $prows = [];
    foreach ($problem as $r) {
        $prows[] = [
            'url' => perf_url_cell($r->url),
            'category' => ($r->category ?? '') !== '' ? $r->category : __('common.uncategorized'),
            'category_color' => getCategoryColor(($r->category ?? '') !== '' ? $r->category : ''),
            'code' => (string) $r->code,
            'clicks' => perf_num($r->clicks), 'impressions' => perf_num($r->impressions),
            'ctr' => perf_ctr($r->ctr), 'position' => perf_pos($r->position),
        ];
    }
    perf_render_url_table($prows, [
        'title' => __('performance.codes_problem_title'), 'subtitle' => __('performance.codes_problem_subtitle'),
        'maxLines' => 15, 'extraColumns' => [['key' => 'code', 'label' => __('performance.col_code'), 'type' => 'badge-autodetect']],
    ]);
    ?>
</div>
