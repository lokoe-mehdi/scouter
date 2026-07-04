<?php
/**
 * PERFORMANCE — SEO tags (title / meta description) × Search Console.
 * The title & meta description are what Google shows in the SERP, so they drive
 * CTR directly. Splits clicks / impressions by tag health (OK / duplicate /
 * missing) and lists visible pages whose title is missing or duplicated.
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

$statusLabels = ['1_ok' => __('performance.tag_ok'), '2_duplicate' => __('performance.tag_duplicate'), '3_missing' => __('performance.tag_missing')];
$statusColors = ['1_ok' => '#6bd899', '2_duplicate' => '#d8bf6b', '3_missing' => '#d86b6b'];

/** Build the bucket rows for one status column (title_status / metadesc_status). */
$statusRows = function (string $col) use ($pdo, $metrics, $base, $pAll, $statusLabels, $statusColors): array {
    $expr = "multiIf(p.{$col} = 'duplicate', '2_duplicate', p.{$col} = 'empty' OR p.{$col} = '', '3_missing', '1_ok')";
    $sql = "SELECT {$expr} AS bucket, {$metrics} {$base} GROUP BY bucket ORDER BY bucket";
    $st = $pdo->prepare($sql); $st->execute($pAll); $raw = $st->fetchAll();
    $rows = [];
    foreach ($raw as $r) {
        $rows[] = [
            'label' => $statusLabels[$r->bucket] ?? $r->bucket, 'color' => $statusColors[$r->bucket] ?? '#95a5a6',
            'urls' => $r->urls, 'clicks' => $r->clicks, 'impressions' => $r->impressions,
            'ctr' => ((int) $r->impressions > 0 ? (int) $r->clicks / (int) $r->impressions : 0), 'position' => $r->position,
        ];
    }
    return [$rows, $sql];
};

[$titleRows, $sqlTitle] = $statusRows('title_status');
[$metaRows, $sqlMeta]   = $statusRows('metadesc_status');

// Visible pages with a missing/duplicate title (worst CTR offenders first).
$sqlProblem = "
    SELECT p.url AS url, p.category AS category,
           multiIf(p.title_status = 'duplicate', 'duplicate', 'missing') AS title_issue,
           g.clicks AS clicks, g.impressions AS impressions,
           if(g.impressions = 0, 0, g.clicks / g.impressions) AS ctr,
           if(g.impressions = 0, 0, g.pos_num / g.impressions) AS position
    FROM pages p INNER JOIN ( {$gscAgg} ) g ON g.page = p.url
    WHERE p.crawl_id = :crawl_id AND p.crawled = true AND p.in_crawl = TRUE AND p.code = 200 AND p.is_html = true
      AND (p.title_status = 'duplicate' OR p.title_status = 'empty' OR p.title_status = '') AND g.impressions > 0
    ORDER BY g.impressions DESC LIMIT 100";
$stmt = $pdo->prepare($sqlProblem); $stmt->execute($pAll); $problem = $stmt->fetchAll();
?>

<h1 class="page-title"><?= __('performance.seo_tags_title') ?></h1>
<?php include __DIR__ . '/../partials/performance-controls.php'; ?>
<div class="perf-note"><span class="material-symbols-outlined">info</span><span><?= __('performance.seo_tags_note') ?></span></div>

<div style="display:flex; flex-direction:column; gap:1.5rem;">
    <h2 class="perf-section-title"><?= __('performance.seo_title_section') ?></h2>
    <?php
    perf_render_buckets([
        'rows' => $titleRows, 'sql' => $sqlTitle, 'dimLabel' => __('performance.dim_title'),
        'clicksTitle' => __('performance.title_clicks_title'), 'clicksSubtitle' => __('performance.title_clicks_subtitle'),
        'imprTitle' => __('performance.title_impr_title'), 'imprSubtitle' => __('performance.title_impr_subtitle'),
        'tableTitle' => __('performance.title_table_title'), 'tableSubtitle' => __('performance.title_table_subtitle'),
    ]);
    ?>

    <h2 class="perf-section-title"><?= __('performance.seo_meta_section') ?></h2>
    <?php
    perf_render_buckets([
        'rows' => $metaRows, 'sql' => $sqlMeta, 'dimLabel' => __('performance.dim_metadesc'),
        'clicksTitle' => __('performance.meta_clicks_title'), 'clicksSubtitle' => __('performance.meta_clicks_subtitle'),
        'imprTitle' => __('performance.meta_impr_title'), 'imprSubtitle' => __('performance.meta_impr_subtitle'),
        'tableTitle' => __('performance.meta_table_title'), 'tableSubtitle' => __('performance.meta_table_subtitle'),
    ]);

    $prows = [];
    foreach ($problem as $r) {
        $prows[] = [
            'url' => perf_url_cell($r->url),
            'category' => ($r->category ?? '') !== '' ? $r->category : __('common.uncategorized'),
            'category_color' => getCategoryColor(($r->category ?? '') !== '' ? $r->category : ''),
            'title_issue' => $r->title_issue === 'duplicate' ? __('performance.tag_duplicate') : __('performance.tag_missing'),
            'clicks' => perf_num($r->clicks), 'impressions' => perf_num($r->impressions),
            'ctr' => perf_ctr($r->ctr), 'position' => perf_pos($r->position),
        ];
    }
    perf_render_url_table($prows, [
        'title' => __('performance.seo_problem_title'), 'subtitle' => __('performance.seo_problem_subtitle'),
        'maxLines' => 15, 'extraColumns' => [['key' => 'title_issue', 'label' => __('performance.col_title_issue'), 'type' => 'badge-warning']],
    ]);
    ?>
</div>
