<?php
/**
 * Crawl Comparison - Lost URLs
 *
 * Shows URLs present in the comparison crawl but absent from the current crawl.
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

// Count new URLs (in current, not in comparison)
$stmtNew = $pdo->prepare("
    SELECT COUNT(*) FROM pages
    WHERE crawl_id = :current AND url NOT IN (
        SELECT url FROM pages WHERE crawl_id = :compare
    )
");
$stmtNew->execute([':current' => $safeCrawlId, ':compare' => $safeCompareId]);
$newCount = (int)$stmtNew->fetchColumn();

// Count lost URLs (in comparison, not in current)
$stmtLost = $pdo->prepare("
    SELECT COUNT(*) FROM pages
    WHERE crawl_id = :compare AND url NOT IN (
        SELECT url FROM pages WHERE crawl_id = :current
    )
");
$stmtLost->execute([':compare' => $safeCompareId, ':current' => $safeCrawlId]);
$lostCount = (int)$stmtLost->fetchColumn();

// Count common URLs
$stmtCommon = $pdo->prepare("
    SELECT COUNT(*) FROM pages a
    JOIN pages b ON a.url = b.url AND b.crawl_id = :compare
    WHERE a.crawl_id = :current
");
$stmtCommon->execute([':current' => $safeCrawlId, ':compare' => $safeCompareId]);
$commonCount = (int)$stmtCommon->fetchColumn();

// Compare crawl date for display
$compareDate = date('Y-m-d H:i', strtotime($compareRecord->started_at ?? $compareRecord->created_at ?? 'now'));
?>

<h1 class="page-title"><?= __('comparison.lost_urls_title') ?></h1>
<p class="page-subtitle" style="color: var(--text-secondary); margin-bottom: 1.5rem;">
    <?= __('comparison.lost_urls_desc') ?> — <?= __('comparison.comparing_with') ?> <?= htmlspecialchars($compareDate) ?>
</p>

<div class="cards-grid">
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
    'whereClause' => "WHERE c.url NOT IN (SELECT url FROM pages WHERE crawl_id = {$safeCrawlId})",
    'orderBy' => 'ORDER BY c.url ASC',
    'defaultColumns' => ['url', 'code', 'depth', 'category', 'inlinks'],
    'pdo' => $pdo,
    'crawlId' => $safeCompareId,
    'perPage' => 100,
    'projectDir' => $compareId
]);

// Restore original categories map
$GLOBALS['categoriesMap'] = $savedCategoriesMap;
$GLOBALS['categoryColors'] = $savedCategoryColors;
?>
