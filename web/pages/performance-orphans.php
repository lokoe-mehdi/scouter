<?php
/**
 * PERFORMANCE — Orphan pages (Search Console × crawl).
 * Pages Google shows in the results (impressions) whose URL the crawl never
 * reached — orphans (not linked internally, excluded by the crawl config, anchor
 * / parameter variants…). Surfaces how much visibility comes from pages your
 * crawl can't see, and lists them so you can wire them into the site.
 */
$perf = \App\Gsc\PerformanceReport::context((int) $crawlId, !empty($useCh));
if (!$perf['available']) { \App\Gsc\PerformanceReport::renderUnavailable($perf); return; }
require_once __DIR__ . '/../partials/performance-helpers.php';

$from = $perf['from']; $to = $perf['to'];
$projectId = (int) $perf['projectId'];
$inner  = \App\Gsc\PerformanceReport::gscPageAgg($from, $to);   // page, clicks, impressions, pos_num
$pAll   = [':crawl_id' => (int) $crawlId];
$notInCrawl = "page NOT IN (SELECT url FROM pages WHERE crawl_id = :crawl_id AND crawled = true AND in_crawl = TRUE)";
// A GSC property (esp. sc-domain:) can span several subdomains, but a crawl
// focuses on specific host(s). Restrict orphans to the domain(s) the crawl
// actually covers so we don't count pages from other subdomains.
$sameDomain = "domain(page) IN (SELECT DISTINCT domain FROM pages WHERE crawl_id = :crawl_id AND crawled = true AND in_crawl = TRUE)";

// --- Coverage of the crawl's INDEXABLE pages --------------------------------
$sqlCoverage = "
    SELECT count() AS indexable,
           countIf(g.impressions > 0) AS with_impr,
           countIf(g.clicks > 0) AS with_clicks
    FROM pages p LEFT JOIN ( {$inner} ) g ON g.page = p.url
    WHERE p.crawl_id = :crawl_id AND p.crawled = true AND p.in_crawl = TRUE AND p.compliant = 1";
$stmt = $pdo->prepare($sqlCoverage); $stmt->execute($pAll); $cov = $stmt->fetch();

// --- Crawl vs orphan split (traffic that comes from pages not in the crawl) --
$sqlSplit = "
    SELECT sumIf(impressions, orphan) AS orphan_impr, sumIf(impressions, NOT orphan) AS matched_impr,
           sumIf(clicks, orphan) AS orphan_clicks, sumIf(clicks, NOT orphan) AS matched_clicks,
           countIf(orphan) AS orphan_urls, countIf(NOT orphan) AS matched_urls
    FROM (
        SELECT clicks, impressions, {$notInCrawl} AS orphan
        FROM ( {$inner} ) WHERE impressions > 0 AND {$sameDomain}
    )";
$stmt = $pdo->prepare($sqlSplit); $stmt->execute($pAll); $split = $stmt->fetch();

// --- Orphan impressions by category (which templates Google indexes but the
//     crawl misses) — the project's URL rules applied to the GSC page URL. ----
$catExpr = \App\Gsc\GscCategories::caseExpr($projectId, 'page');
$sqlByCat = "
    SELECT ({$catExpr}) AS category, count() AS urls, sum(clicks) AS clicks, sum(impressions) AS impressions
    FROM ( {$inner} )
    WHERE impressions > 0 AND {$notInCrawl} AND {$sameDomain}
    GROUP BY category
    ORDER BY sum(impressions) DESC";
$stmt = $pdo->prepare($sqlByCat); $stmt->execute($pAll); $byCat = $stmt->fetchAll();

// --- Orphan URL list — full custom query for the standard paginated urlTable
//     (these URLs aren't in `pages`, so the columns are aliased to url/gsc_*). --
$sqlList = "
    SELECT page AS url, ({$catExpr}) AS category, clicks AS gsc_clicks, impressions AS gsc_impressions,
           if(impressions = 0, 0, clicks / impressions) AS gsc_ctr,
           if(impressions = 0, 0, pos_num / impressions) AS gsc_position
    FROM ( {$inner} )
    WHERE impressions > 0 AND {$notInCrawl} AND {$sameDomain}
    ORDER BY impressions DESC";

$matchedImpr = (int) ($split->matched_impr ?? 0); $orphanImpr = (int) ($split->orphan_impr ?? 0);
$matchedClicks = (int) ($split->matched_clicks ?? 0); $orphanClicks = (int) ($split->orphan_clicks ?? 0);
$orphanUrls = (int) ($split->orphan_urls ?? 0);
$CRAWL_COLOR = '#4ECDC4'; $ORPHAN_COLOR = '#E67E22';
?>

<h1 class="page-title"><?= __('performance.orphans_title') ?></h1>
<?php include __DIR__ . '/../partials/performance-controls.php'; ?>
<div class="perf-note"><span class="material-symbols-outlined">info</span><span><?= __('performance.orphans_note') ?></span></div>

<div style="display:flex; flex-direction:column; gap:1.5rem;">

    <!-- Coverage scorecards -->
    <div class="scorecards">
        <?php
        $indexable = (int) ($cov->indexable ?? 0);
        $withImpr  = (int) ($cov->with_impr ?? 0);
        Component::card(['color' => 'primary', 'icon' => 'folder', 'title' => __('performance.orph_indexable'),
            'value' => perf_num($indexable), 'desc' => __('performance.orph_indexable_desc')]);
        Component::card(['color' => 'info', 'icon' => 'visibility', 'title' => __('performance.orph_with_impr'),
            'value' => perf_num($withImpr) . ($indexable > 0 ? ' (' . round($withImpr / $indexable * 100) . '%)' : ''),
            'desc' => __('performance.orph_with_impr_desc')]);
        $withClicks = (int) ($cov->with_clicks ?? 0);
        Component::card(['color' => 'success', 'icon' => 'ads_click', 'title' => __('performance.orph_with_clicks'),
            'value' => perf_num($withClicks) . ($indexable > 0 ? ' (' . round($withClicks / $indexable * 100) . '%)' : ''),
            'desc' => __('performance.orph_with_clicks_desc')]);
        Component::card(['color' => 'color1', 'icon' => 'link_off', 'title' => __('performance.orph_orphans'),
            'value' => perf_num($orphanUrls), 'desc' => __('performance.orph_orphans_desc')]);
        ?>
    </div>

    <!-- Where does the search visibility come from: crawl vs orphan -->
    <div class="charts-grid">
        <?php
        Component::chart(['type' => 'donut', 'title' => __('performance.orph_impr_split_title'),
            'subtitle' => __('performance.orph_impr_split_subtitle'), 'legendPosition' => 'bottom', 'height' => 340,
            'series' => [['name' => __('performance.metric_impressions'), 'data' => [
                ['name' => __('performance.orph_in_crawl'), 'y' => $matchedImpr, 'color' => $CRAWL_COLOR],
                ['name' => __('performance.orph_orphan'), 'y' => $orphanImpr, 'color' => $ORPHAN_COLOR],
            ]]], 'sqlQuery' => $sqlSplit]);
        Component::chart(['type' => 'donut', 'title' => __('performance.orph_clicks_split_title'),
            'subtitle' => __('performance.orph_clicks_split_subtitle'), 'legendPosition' => 'bottom', 'height' => 340,
            'series' => [['name' => __('performance.metric_clicks'), 'data' => [
                ['name' => __('performance.orph_in_crawl'), 'y' => $matchedClicks, 'color' => $CRAWL_COLOR],
                ['name' => __('performance.orph_orphan'), 'y' => $orphanClicks, 'color' => $ORPHAN_COLOR],
            ]]], 'sqlQuery' => $sqlSplit]);
        ?>
    </div>

    <!-- Orphan traffic by category: impressions AND clicks bars per category -->
    <?php
    $catNames = []; $catImpr = []; $catClicks = [];
    foreach ($byCat as $r) {
        $catNames[]  = ($r->category ?? '') !== '' ? $r->category : __('common.uncategorized');
        $catImpr[]   = (int) $r->impressions;
        $catClicks[] = (int) $r->clicks;
    }
    if (array_sum($catImpr) > 0) {
        Component::chart(['type' => 'horizontalBar', 'title' => __('performance.orph_cat_title'),
            'subtitle' => __('performance.orph_cat_subtitle'), 'categories' => $catNames,
            // Clicks on a SECONDARY axis so their bars stay visible next to the
            // much larger impressions.
            'dualAxis' => ['primary' => __('performance.metric_impressions'), 'secondary' => __('performance.metric_clicks')],
            'series' => [
                ['name' => __('performance.metric_impressions'), 'data' => $catImpr, 'color' => '#3498DB', 'yAxis' => 0],
                ['name' => __('performance.metric_clicks'), 'data' => $catClicks, 'color' => '#4ECDC4', 'yAxis' => 1],
            ],
            'sqlQuery' => $sqlByCat]);
    }

    // --- Orphan URL table — the standard PAGINATED urlTable, driven by a custom
    //     GSC-only query (these URLs aren't in the crawl `pages`). ----
    perf_gsc_url_table([
        'id' => 'perf_orphans',
        'title' => __('performance.orph_table_title'),
        'sqlQuery' => $sqlList,
        'sqlParams' => $pAll,
        'defaultColumns' => ['url', 'category', 'gsc_clicks', 'gsc_impressions', 'gsc_ctr', 'gsc_position'],
        'pdo' => $pdo,
        'crawlId' => (int) $crawlId,
    ]);
    ?>
</div>
