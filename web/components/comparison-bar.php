<?php
/**
 * Comparison Control Bar — Lightweight
 *
 * Only shows the comparison crawl selector (the current crawl is already
 * visible in the global header). Includes swap and close buttons.
 *
 * Required variables:
 * - $crawlId, $crawlRecord, $compareId, $compareRecord
 * - $domainCrawls, $page
 */

$baseDate = null;
if ($compareRecord && !empty($compareRecord->started_at)) {
    // Handle timestamps with or without microseconds
    $baseDate = new DateTime($compareRecord->started_at);
}
$baseDateStr = $baseDate ? $baseDate->format('d/m/Y H:i') : __('comparison.select_placeholder');
?>

<div class="comparison-bar" id="comparisonBar">
    <!-- Label -->
    <span class="comparison-bar-label"><?= __('comparison.select_crawl') ?></span>

    <!-- Crawl selector -->
    <div class="crawl-selector comparison-bar-selector">
        <button class="crawl-selector-btn comparison-bar-control-btn" id="compareSelectorBtn" onclick="toggleCompareDropdown(event)">
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
                            <span class="crawl-item-id">#<?= $crawl['crawl_id'] ?></span>
                        </div>
                        <?php if ($isCurrentCrawl): ?>
                            <span class="crawl-item-badge"><?= __('comparison.badge_reference') ?></span>
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
                    </div>
                <?= $isCurrentCrawl ? '</div>' : '</a>' ?>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Swap -->
    <?php if ($compareId): ?>
    <a href="?crawl=<?= $compareId ?>&page=<?= urlencode($page) ?>&compare=<?= $crawlId ?>" class="comparison-bar-swap" title="<?= __('comparison.swap') ?>">
        <span class="material-symbols-outlined">swap_horiz</span>
    </a>
    <?php endif; ?>
</div>

<script>
function toggleCompareDropdown(e) {
    e.stopPropagation();
    const dropdown = document.getElementById('compareDropdown');
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
    const comparisonPages = ['comparison-overview', 'new-urls', 'lost-urls', 'code-changes', 'depth-comparison', 'accessibility-comparison', 'seo-tags-comparison', 'headings-comparison', 'content-richness-comparison', 'duplication-comparison', 'structured-data-comparison', 'inlinks-comparison', 'outlinks-comparison', 'pagerank-comparison', 'pagerank-leak-comparison'];
    if (!comparisonPages.includes(currentPage)) {
        url.searchParams.set('page', 'comparison-overview');
    }
    window.location = url.toString();
}
</script>
