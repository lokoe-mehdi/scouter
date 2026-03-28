<?php
/**
 * Comparison Control Bar
 *
 * Sticky bar shown on comparison pages (new-urls, lost-urls).
 * Displays Crawl A (reference) vs Crawl B (baseline) with a dropdown to change B
 * and a swap button to invert A/B.
 *
 * Required variables:
 * - $crawlId, $crawlRecord, $compareId, $compareRecord
 * - $domainCrawls, $page
 */

// Resolve dates
$refDate = null;
if (!empty($crawlRecord->started_at)) {
    $refDate = DateTime::createFromFormat('Y-m-d H:i:s', $crawlRecord->started_at);
}
$baseDate = null;
if ($compareRecord && !empty($compareRecord->started_at)) {
    $baseDate = DateTime::createFromFormat('Y-m-d H:i:s', $compareRecord->started_at);
}
$refDateStr = $refDate ? $refDate->format('d/m/Y H:i') : __('header.date_unknown');
$baseDateStr = $baseDate ? $baseDate->format('d/m/Y H:i') : __('comparison.select_placeholder');
?>

<div class="comparison-bar" id="comparisonBar">
    <!-- Crawl A (Reference) -->
    <div class="comparison-bar-crawl comparison-bar-ref">
        <span class="comparison-bar-badge comparison-bar-badge--ref"><?= __('comparison.badge_reference') ?></span>
        <span class="comparison-bar-date"><span class="material-symbols-outlined">schedule</span><?= $refDateStr ?></span>
    </div>

    <!-- Swap -->
    <?php if ($compareId): ?>
    <a href="?crawl=<?= $compareId ?>&page=<?= urlencode($page) ?>&compare=<?= $crawlId ?>" class="comparison-bar-icon" title="<?= __('comparison.swap') ?>">
        <span class="material-symbols-outlined">swap_horiz</span>
    </a>
    <?php else: ?>
    <span class="material-symbols-outlined comparison-bar-icon" style="cursor: default;">compare_arrows</span>
    <?php endif; ?>

    <!-- Crawl B (Baseline) — dropdown -->
    <div class="comparison-bar-crawl comparison-bar-base">
        <span class="comparison-bar-badge comparison-bar-badge--base"><?= __('comparison.badge_baseline') ?></span>
        <div class="crawl-selector comparison-bar-selector">
            <button class="crawl-selector-btn" id="compareSelectorBtn" onclick="toggleCompareDropdown(event)">
                <span class="material-symbols-outlined">schedule</span>
                <?= $baseDateStr ?>
                <span class="material-symbols-outlined">expand_more</span>
            </button>
            <div class="crawl-dropdown" id="compareDropdown">
                <?php foreach ($domainCrawls as $crawl):
                    $crawlStatus = $crawl['status'] ?? $crawl['job_status'] ?? 'finished';
                    if (!in_array($crawlStatus, ['finished', 'stopped', 'error', 'completed'])) continue;
                    $crawlDate = DateTime::createFromFormat('Y-m-d H:i:s', $crawl['date']);
                    $isCurrentCrawl = ($crawl['crawl_id'] == $crawlId);
                    $isCompare = ($compareId == $crawl['crawl_id']);
                ?>
                    <?php if ($isCurrentCrawl): ?>
                    <div class="crawl-dropdown-item disabled">
                    <?php else: ?>
                    <a href="javascript:void(0)" onclick="changeCompareCrawl(<?= $crawl['crawl_id'] ?>)"
                       class="crawl-dropdown-item <?= $isCompare ? 'active' : '' ?>">
                    <?php endif; ?>
                        <div class="crawl-item-main">
                            <div class="crawl-item-date">
                                <?= $crawlDate ? $crawlDate->format('d/m/Y H:i') : __('header.date_unknown') ?>
                            </div>
                            <?php if ($isCurrentCrawl): ?>
                                <span class="crawl-item-badge"><?= __('header.badge_current') ?></span>
                            <?php elseif ($isCompare): ?>
                                <span class="crawl-item-badge crawl-item-badge--baseline"><?= __('comparison.badge_baseline') ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="crawl-item-row">
                            <div class="crawl-item-stats">
                                <span><?= number_format($crawl['stats']['urls']) ?> URLs</span>
                                <span>•</span>
                                <span><?= number_format($crawl['stats']['crawled']) ?> <?= __('header.crawled') ?></span>
                            </div>
                            <?php if (!empty($crawl['config'])): ?>
                            <div class="crawl-item-config">
                                <span class="material-symbols-outlined config-mini <?= ($crawl['config']['general']['crawl_mode'] ?? 'classic') === 'javascript' ? 'active' : '' ?>" title="Mode JavaScript">javascript</span>
                                <span class="material-symbols-outlined config-mini <?= !empty($crawl['config']['advanced']['respect']['robots']) ? 'active' : '' ?>" title="Respect du robots.txt">smart_toy</span>
                                <span class="material-symbols-outlined config-mini <?= !empty($crawl['config']['advanced']['respect']['canonical']) ? 'active' : '' ?>" title="Respect des canonicals">content_copy</span>
                                <span class="material-symbols-outlined config-mini <?= !empty($crawl['config']['advanced']['nofollow']) ? 'active' : 'inactive' ?>" title="Respect du nofollow">link_off</span>
                                <span class="config-depth-mini" title="Profondeur max"><?= $crawl['config']['general']['depthMax'] ?? '-' ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?= $isCurrentCrawl ? '</div>' : '</a>' ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script>
function toggleCompareDropdown(e) {
    e.stopPropagation();
    const dropdown = document.getElementById('compareDropdown');
    const btn = document.getElementById('compareSelectorBtn');
    const headerDropdown = document.getElementById('crawlDropdown');
    if (headerDropdown) headerDropdown.classList.remove('show');
    dropdown.classList.toggle('show');
}

document.addEventListener('click', function(e) {
    const dropdown = document.getElementById('compareDropdown');
    const btn = document.getElementById('compareSelectorBtn');
    if (dropdown && btn && !btn.contains(e.target) && !dropdown.contains(e.target)) {
        dropdown.classList.remove('show');
    }
});

function changeCompareCrawl(compareId) {
    const url = new URL(window.location);
    url.searchParams.set('compare', compareId);
    const currentPage = url.searchParams.get('page');
    if (!['comparison-overview', 'new-urls', 'lost-urls'].includes(currentPage)) {
        url.searchParams.set('page', 'comparison-overview');
    }
    window.location = url.toString();
}
</script>
