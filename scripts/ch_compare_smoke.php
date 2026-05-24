<?php
/**
 * Dev smoke test: render every COMPARISON report against two ClickHouse crawls
 * through the ChPdo shim (comparison mode), catching SQL/render fatals.
 *
 *   docker exec scouter-scouter-1 php /tmp/ch_compare_smoke.php <crawlId> <compareId>
 *
 * Use the same id twice for a same-project self-compare when no second crawl of
 * the project exists (exercises the SQL machinery: pages@id, both-crawls bare
 * pages, NOT IN diffs, LEFT JOIN pages@id).
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
require '/app/vendor/autoload.php';
require '/app/web/config/i18n.php';
require '/app/web/config/component.php';
require '/app/app/Util/CategoryColors.php';
require '/app/app/Util/HttpCodes.php';

use App\Database\CrawlDatabase;
use App\Database\ChPdo;

$crawlId   = (int)($argv[1] ?? 0);
$compareId = (int)($argv[2] ?? 0);
if (!$crawlId || !$compareId) { fwrite(STDERR, "usage: <crawlId> <compareId>\n"); exit(2); }

$crawlRecord   = CrawlDatabase::getCrawlById($crawlId);
$compareRecord = CrawlDatabase::getCrawlById($compareId);
$crawlId = (int)$crawlRecord->id; $compareId = (int)$compareRecord->id;
$globalStats = $crawlRecord;
$categoriesMap = ChPdo::categoriesMap($crawlId);
$categoryColors = []; foreach ($categoriesMap as $r) { $categoryColors[$r['cat']] = $r['color']; }
$GLOBALS['categoriesMap'] = $categoriesMap; $GLOBALS['categoryColors'] = $categoryColors;
$pdo = new ChPdo($crawlId, $compareId);
$GLOBALS['chReportPdo'] = $pdo;
$projectDir = $crawlRecord->path ?? (string)$crawlId;
$crawlRepo = new App\Database\CrawlRepository();
$compareStoreHtml = true; $storeHtml = true; $isAdmin = true; $isViewer = false; $canCreate = true;

if (!function_exists('getCategoryColor')) { function getCategoryColor($c){ $m=$GLOBALS['categoryColors']??[]; return $m[$c]??'#95a5a6'; } }
if (!function_exists('getCodeColor')) { function getCodeColor($c){return '#888';} }
if (!function_exists('getCodeLabel')) { function getCodeLabel($c){return (string)$c;} }
if (!function_exists('getCodeFullLabel')) { function getCodeFullLabel($c){return (string)$c;} }
if (!function_exists('getCodeClass')) { function getCodeClass($c){return 'code';} }
if (!function_exists('getTextColorForBackground')) { function getTextColorForBackground($c){return '#000';} }
if (!function_exists('getCodeColor')) { function getCodeColor($c){return '#888';} }
if (!function_exists('getCodeLabel')) { function getCodeLabel($c){return (string)$c;} }
if (!function_exists('getCodeFullLabel')) { function getCodeFullLabel($c){return (string)$c;} }
if (!function_exists('getCodeClass')) { function getCodeClass($c){return 'code';} }

// Comparison scorecards (same NOT IN form dashboard.php precomputes).
$safeCrId = $crawlId; $safeCompId = $compareId;
$compNewCount = (int)$pdo->query("SELECT COUNT(*) FROM pages a WHERE a.crawl_id = {$safeCrId} AND a.crawled = true AND a.in_crawl = TRUE AND a.url NOT IN (SELECT url FROM pages WHERE crawl_id = {$safeCompId} AND crawled = true AND in_crawl = TRUE)")->fetchColumn();
$compLostCount = (int)$pdo->query("SELECT COUNT(*) FROM pages a WHERE a.crawl_id = {$safeCompId} AND a.crawled = true AND a.in_crawl = TRUE AND a.url NOT IN (SELECT url FROM pages WHERE crawl_id = {$safeCrId} AND crawled = true AND in_crawl = TRUE)")->fetchColumn();
$compCommonCount = (int)$pdo->query("SELECT COUNT(*) FROM pages a WHERE a.crawl_id = {$safeCrId} AND a.crawled = true AND a.in_crawl = TRUE AND a.url IN (SELECT url FROM pages WHERE crawl_id = {$safeCompId} AND crawled = true AND in_crawl = TRUE)")->fetchColumn();

$pages = [
    'comparison-overview','new-urls','lost-urls','code-changes','depth-comparison',
    'accessibility-comparison','sitemap-comparison','seo-tags-comparison','headings-comparison',
    'content-richness-comparison','duplication-comparison','structured-data-comparison',
    'inlinks-comparison','outlinks-comparison','pagerank-comparison','pagerank-leak-comparison',
];
$pass=0; $fail=0;
foreach ($pages as $page) {
    $file = "/app/web/pages/{$page}.php";
    if (!is_file($file)) { echo "  skip  {$page}\n"; continue; }
    $_GET['page']=$page; $_GET['crawl']=$crawlId; $_GET['compare']=$compareId;
    ob_start();
    try { include $file; ob_end_clean(); echo "  OK    {$page}\n"; $pass++; }
    catch (\Throwable $e) { ob_end_clean(); $fail++; echo "  FAIL  {$page}: ".str_replace("\n"," ",substr($e->getMessage(),0,200))."\n"; }
}
echo "\n=== $pass OK, $fail FAIL (new=$compNewCount lost=$compLostCount common=$compCommonCount) ===\n";
