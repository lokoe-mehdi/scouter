<?php
/**
 * Crawl Comparison - New URLs
 *
 * Shows URLs present in the current crawl but absent from the comparison crawl.
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

<h1 class="page-title"><?= __('comparison.new_urls_title') ?></h1>
<p class="page-subtitle" style="color: var(--text-secondary); margin-bottom: 1.5rem;">
    <?= __('comparison.new_urls_desc') ?> — <?= __('comparison.comparing_with') ?> <?= htmlspecialchars($compareDate) ?>
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
    'title' => __('comparison.new_urls_title'),
    'id' => 'new_urls_table',
    'whereClause' => "WHERE c.url NOT IN (SELECT url FROM pages WHERE crawl_id = {$safeCompareId})",
    'orderBy' => 'ORDER BY c.url ASC',
    'defaultColumns' => ['url', 'code', 'depth', 'category', 'inlinks'],
    'pdo' => $pdo,
    'crawlId' => $safeCrawlId,
    'perPage' => 100,
    'projectDir' => $crawlId
]);
?>
