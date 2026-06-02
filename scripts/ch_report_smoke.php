<?php
/**
 * Dev smoke test: render every single-crawl report page against a ClickHouse
 * crawl through the ChPdo shim, catching SQL/rendering fatals.
 *
 *   docker exec scouter-scouter-1 php /app/scripts/ch_report_smoke.php <crawlId>
 *
 * It mirrors dashboard.php's setup (minus auth) then includes each report with
 * output buffered, reporting OK / FAIL per page. Not shipped in prod paths.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

require '/app/vendor/autoload.php';
require '/app/web/config/i18n.php';
require '/app/web/config/component.php';
require '/app/app/Util/CategoryColors.php';
require '/app/app/Util/HttpCodes.php';

use App\Database\CrawlDatabase;
use App\Database\ChPdo;

$crawlId = (int)($argv[1] ?? 0);
if (!$crawlId) { fwrite(STDERR, "usage: ch_report_smoke.php <crawlId>\n"); exit(2); }

// --- replicate dashboard.php's report context (single-crawl, CH) ---
$crawlRecord = CrawlDatabase::getCrawlById($crawlId);
if (!$crawlRecord) { fwrite(STDERR, "crawl $crawlId not found\n"); exit(2); }
$crawlId = (int)$crawlRecord->id;
$compareId = null; $compareRecord = null;
$categoriesMap = ChPdo::categoriesMap($crawlId);
$categoryColors = [];
foreach ($categoriesMap as $r) { $categoryColors[$r['cat']] = $r['color']; }
$GLOBALS['categoriesMap'] = $categoriesMap;
$GLOBALS['categoryColors'] = $categoryColors;
$pdo = new ChPdo($crawlId);
$GLOBALS['chReportPdo'] = $pdo;
$globalStats = $crawlRecord;
$projectDir = $crawlRecord->path ?? (string)$crawlId;
$crawlRepo = new App\Database\CrawlRepository();
$compareStoreHtml = true; $storeHtml = true;
$isAdmin = true; $isViewer = false; $canCreate = true;
$currentUserId = (int)($crawlRecord->user_id ?? 0);

if (!function_exists('getCategoryColor')) {
    function getCategoryColor($category) {
        $c = $GLOBALS['categoryColors'] ?? [];
        if (empty($category) || $category === 'N/A') return '#95a5a6';
        return $c[$category] ?? '#95a5a6';
    }
}
// Stubs for helpers defined in dashboard.php (not loaded by this harness) so we
// only surface real SQL/data failures, not missing-dashboard-helper noise.
if (!function_exists('getCodeColor'))  { function getCodeColor($c) { return '#888'; } }
if (!function_exists('getCodeLabel'))  { function getCodeLabel($c) { return (string)$c; } }
if (!function_exists('getCodeClass'))  { function getCodeClass($c) { return 'code'; } }
if (!function_exists('getCodeFullLabel')) { function getCodeFullLabel($c) { return (string)$c; } }
if (!function_exists('getTextColorForBackground')) { function getTextColorForBackground($hex) { return '#fff'; } }
if (!function_exists('getCodeBackgroundColor')) { function getCodeBackgroundColor($c, $o = 0.3) { return '#888'; } }
if (!function_exists('getCodeDisplayValue')) { function getCodeDisplayValue($c) { return $c; } }

// Each report in dashboard.php runs in its own request with $globalStats freshly
// set to the crawl record. Some reports (content-richness, structured-data)
// reassign $globalStats to a local aggregate; since this harness runs them all in
// ONE process, restore the crawl record before each so the leak can't cause a
// false failure in a later report (e.g. accessibility's crawled/urls ratios).
$freshGlobalStats = $crawlRecord;

$reports = [
    'home','depth','codes','code-changes','pagerank','pagerank-leak','inlinks','outlinks',
    'seo-tags','duplication','headings','content-richness','structured-data','response-time',
    'accessibility','extractions','sitemap','redirect-chains','lost-urls','new-urls',
    // link-explorer shares a function name with url-explorer (buildFilterConditions)
    // so they can't both be included in one process — test it on its own.
    'url-explorer',
];
if (in_array('__link_explorer_only__', $argv, true)) { $reports = ['link-explorer']; }

$pass = 0; $fail = 0;
foreach ($reports as $page) {
    $file = "/app/web/pages/{$page}.php";
    if (!is_file($file)) { echo "  skip  {$page} (no file)\n"; continue; }
    $_GET['page'] = $page; $_GET['crawl'] = $crawlId;
    $globalStats = $freshGlobalStats; // undo any prior report's $globalStats reassignment
    ob_start();
    try {
        include $file;
        ob_end_clean();
        echo "  OK    {$page}\n";
        $pass++;
    } catch (\Throwable $e) {
        ob_end_clean();
        $fail++;
        echo "  FAIL  {$page}: " . str_replace("\n", ' ', substr($e->getMessage(), 0, 240)) . "\n";
    }
}
echo "\n=== $pass OK, $fail FAIL ===\n";
