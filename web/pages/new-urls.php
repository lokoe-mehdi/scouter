<?php
/**
 * Crawl Comparison - New URLs
 *
 * Shows URLs present in the current crawl but absent from the comparison crawl.
 * Only considers crawled URLs (crawled = true).
 */

if (!$compareId) {
    ?>
    <div style="padding: 3rem; text-align: center; max-width: 600px; margin: 2rem auto;">
        <span class="material-symbols-outlined" style="font-size: 4rem; color: var(--text-tertiary);">compare_arrows</span>
        <h2 style="margin-top: 1rem; color: var(--text-primary);"><?= __('comparison.single_crawl') ?></h2>
        <p style="color: var(--text-secondary); margin-top: 0.5rem;"><?= __('comparison.single_crawl_desc') ?></p>
    </div>
    <?php
    return;
}

$safeCompareId = intval($compareId);
$safeCrawlId = intval($crawlId);

// Scorecards pré-calculés dans dashboard.php (NOT EXISTS, O(n) au lieu de NOT IN O(n²))
$newCount = $compNewCount;
$lostCount = $compLostCount;
$commonCount = $compCommonCount;

?>

<?php include __DIR__ . '/../components/comparison-bar.php'; ?>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">

    <div class="scorecards">
    <?php
    Component::card([
        'color' => 'success',
        'icon' => 'add_circle',
        'title' => __('comparison.card_new'),
        'value' => number_format($newCount)
    ]);
    Component::card([
        'color' => 'error',
        'icon' => 'remove_circle',
        'title' => __('comparison.card_lost'),
        'value' => number_format($lostCount)
    ]);
    Component::card([
        'color' => 'info',
        'icon' => 'sync',
        'title' => __('comparison.card_common'),
        'value' => number_format($commonCount)
    ]);
    ?>
    </div>

    <?php
    Component::urlTable([
        'title' => __('comparison.new_urls_title'),
        'id' => 'new_urls_table',
        'whereClause' => "WHERE c.crawled = true AND NOT EXISTS (SELECT 1 FROM pages_{$safeCompareId} b WHERE b.crawled = true AND b.url = c.url)",
        'orderBy' => 'ORDER BY c.url ASC',
        'defaultColumns' => ['url', 'code', 'depth', 'category', 'inlinks'],
        'pdo' => $pdo,
        'crawlId' => $safeCrawlId,
        'perPage' => 100,
        'projectDir' => $crawlId
    ]);
    ?>

</div>
