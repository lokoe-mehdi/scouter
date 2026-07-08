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
$gscAgg = \App\Gsc\PerformanceReport::gscPageAgg($from, $to);
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

    perf_url_table([
        'id' => 'perf_codes_problem',
        'title' => __('performance.codes_problem_title'),
        'whereClause' => "WHERE c.crawled = true AND c.in_crawl = TRUE AND c.code != 200 AND g.impressions > 0",
        'orderBy' => 'ORDER BY g.impressions DESC',
        'extraColumns' => ['code'],
        'gscJoin' => $gscAgg, 'pdo' => $pdo, 'crawlId' => (int) $crawlId,
    ]);
    ?>
</div>
