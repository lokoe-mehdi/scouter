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
        <h2 style="margin-top: 1rem; color: var(--text-primary);"><?= __('comparison.no_compare') ?></h2>
        <p style="color: var(--text-secondary); margin-top: 0.5rem;"><?= __('comparison.no_compare_desc') ?></p>
    </div>
    <?php
    return;
}

$safeCompareId = intval($compareId);
$safeCrawlId = intval($crawlId);

// Count new URLs (in current, not in comparison) — crawled only
$stmtNew = $pdo->prepare("
    SELECT COUNT(*) FROM pages
    WHERE crawl_id = :current AND crawled = true AND url NOT IN (
        SELECT url FROM pages WHERE crawl_id = :compare AND crawled = true
    )
");
$stmtNew->execute([':current' => $safeCrawlId, ':compare' => $safeCompareId]);
$newCount = (int)$stmtNew->fetchColumn();

// Count lost URLs (in comparison, not in current) — crawled only
$stmtLost = $pdo->prepare("
    SELECT COUNT(*) FROM pages
    WHERE crawl_id = :compare AND crawled = true AND url NOT IN (
        SELECT url FROM pages WHERE crawl_id = :current AND crawled = true
    )
");
$stmtLost->execute([':compare' => $safeCompareId, ':current' => $safeCrawlId]);
$lostCount = (int)$stmtLost->fetchColumn();

// Count common URLs — crawled only
$stmtCommon = $pdo->prepare("
    SELECT COUNT(*) FROM pages a
    JOIN pages b ON a.url = b.url AND b.crawl_id = :compare AND b.crawled = true
    WHERE a.crawl_id = :current AND a.crawled = true
");
$stmtCommon->execute([':current' => $safeCrawlId, ':compare' => $safeCompareId]);
$commonCount = (int)$stmtCommon->fetchColumn();

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
        'whereClause' => "WHERE c.crawled = true AND c.url NOT IN (SELECT url FROM pages_{$safeCompareId} WHERE crawled = true)",
        'orderBy' => 'ORDER BY c.url ASC',
        'defaultColumns' => ['url', 'code', 'depth', 'category', 'inlinks'],
        'pdo' => $pdo,
        'crawlId' => $safeCrawlId,
        'perPage' => 100,
        'projectDir' => $crawlId
    ]);
    ?>

</div>
