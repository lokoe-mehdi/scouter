<?php
/**
 * Crawl Comparison - Lost URLs
 *
 * Shows URLs present in the comparison crawl but absent from the current crawl.
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

// Save current categories map
$savedCategoriesMap = $GLOBALS['categoriesMap'];
$savedCategoryColors = $GLOBALS['categoryColors'];

// Load categories from the comparison crawl
$compareCategoriesMap = [];
$compareCategoryColors = [];
$stmt = $pdo->prepare("SELECT id, cat, color FROM categories WHERE crawl_id = :crawl_id");
$stmt->execute([':crawl_id' => $safeCompareId]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $compareCategoriesMap[$row['id']] = [
        'cat' => $row['cat'],
        'color' => $row['color']
    ];
    $compareCategoryColors[$row['cat']] = $row['color'];
}

// Override globals for url-table rendering (lost URLs come from comparison crawl)
$GLOBALS['categoriesMap'] = $compareCategoriesMap;
$GLOBALS['categoryColors'] = $compareCategoryColors;

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
        'title' => __('comparison.lost_urls_title'),
        'id' => 'lost_urls_table',
        'whereClause' => "WHERE c.crawled = true AND c.url NOT IN (SELECT url FROM pages_{$safeCrawlId} WHERE crawled = true)",
        'orderBy' => 'ORDER BY c.url ASC',
        'defaultColumns' => ['url', 'code', 'depth', 'category', 'inlinks'],
        'pdo' => $pdo,
        'crawlId' => $safeCompareId,
        'perPage' => 100,
        'projectDir' => $compareId
    ]);
    ?>

</div>

<?php
// Restore original categories map
$GLOBALS['categoriesMap'] = $savedCategoriesMap;
$GLOBALS['categoryColors'] = $savedCategoryColors;
?>
