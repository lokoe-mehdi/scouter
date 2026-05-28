<?php
/**
 * SQL Explorer (PostgreSQL)
 * L'utilisateur voit les tables "pages" et "links" virtuelles
 * Les requêtes sont automatiquement transformées pour utiliser les partitions
 */

use App\Database\PostgresDatabase;

// AI-assisted SQL generation availability — same pattern as categorize.php.
// Visible to every user but disabled with a tooltip when an admin hasn't
// configured an OpenRouter key + light model in Settings.
$sqlAiConfigured = false;
try {
    $sqlAiKey   = \App\Settings\AppSettings::get('ai.openrouter.api_key');
    $sqlAiModel = \App\Settings\AppSettings::get('ai.openrouter.model_light');
    $sqlAiConfigured = $sqlAiKey !== null && $sqlAiKey !== '' && $sqlAiModel !== null && $sqlAiModel !== '';
} catch (\Throwable $e) {
    $sqlAiConfigured = false;
}
// AI reserved for admins + editors; hidden entirely for viewers.
$aiRoleAllowed = \App\AI\BudgetService::isAiEligibleRole($_SESSION['role'] ?? null);

/**
 * Structure des tables virtuelles — lue dynamiquement depuis information_schema.
 *
 * Mapping virtuel → réel : `categories` est un alias de `crawl_categories`
 * (réécriture côté QueryController). Toutes les autres tables portent le même
 * nom. La colonne `crawl_id` (clé de partitionnement) est masquée dans la vue
 * virtuelle, comme l'utilisateur n'a pas à la spécifier dans ses requêtes.
 */
$virtualToReal = [
    'pages'              => 'pages',
    'links'              => 'links',
    'categories'         => 'crawl_categories',
    'duplicate_clusters' => 'duplicate_clusters',
    'page_schemas'       => 'page_schemas',
    'redirect_chains'    => 'redirect_chains',
];
$hiddenColumns = ['crawl_id'];

$pdoForSchema = PostgresDatabase::getInstance()->getConnection();
$realNames = array_values($virtualToReal);
$placeholders = implode(',', array_fill(0, count($realNames), '?'));
$schemaStmt = $pdoForSchema->prepare("
    SELECT table_name, column_name, data_type, character_maximum_length, udt_name
    FROM information_schema.columns
    WHERE table_schema = 'public' AND table_name IN ($placeholders)
    ORDER BY table_name, ordinal_position
");
$schemaStmt->execute($realNames);

// Format a PostgreSQL data_type/udt_name pair into a SQL-style label
$formatType = function(string $dataType, ?int $maxLen, string $udt): string {
    if ($dataType === 'ARRAY') {
        // udt_name for arrays is "_basetype" → strip underscore, uppercase, append []
        $base = ltrim($udt, '_');
        return strtoupper($base) . '[]';
    }
    if ($dataType === 'character varying') {
        return $maxLen ? "VARCHAR($maxLen)" : 'VARCHAR';
    }
    if ($dataType === 'character') {
        return $maxLen ? "CHAR($maxLen)" : 'CHAR';
    }
    if ($dataType === 'timestamp without time zone') return 'TIMESTAMP';
    if ($dataType === 'double precision') return 'FLOAT';
    return strtoupper(str_replace(' ', '_', $dataType));
};

$columnsByReal = [];
while ($row = $schemaStmt->fetch(PDO::FETCH_ASSOC)) {
    if (in_array($row['column_name'], $hiddenColumns, true)) continue;
    $columnsByReal[$row['table_name']][] = [
        'name' => $row['column_name'],
        'type' => $formatType($row['data_type'], $row['character_maximum_length'], $row['udt_name']),
    ];
}

$tables = [];
foreach ($virtualToReal as $virtual => $real) {
    $tables[$virtual] = $columnsByReal[$real] ?? [];
}

// On a ClickHouse-backed crawl the queries run against ClickHouse (the PG
// partitions are purged), so the LEFT panel must show the CH virtual schema —
// real CH types (Map/Array/UInt8…), the derived columns (inlinks, pri,
// *_status), and the LIVE `category` (+ synthetic `cat_id`) — instead of the
// stale PostgreSQL information_schema (which still lists the dropped `cat_id`
// column and hides `category`). Mirrors exactly what the CH executor exposes.
if (\App\Database\CrawlStore::usesClickHouse((int)$crawlId)) {
    $tables = \App\Http\Controllers\ApiV1Controller::clickHouseVirtualSchema();
    $tables['crawl_categories'] = [
        ['name' => 'id', 'type' => 'Int32'],
        ['name' => 'cat', 'type' => 'String'],
        ['name' => 'color', 'type' => 'String'],
    ];
}

// Requête passée en paramètre GET (depuis la modale scope)
// Initial SQL : accept either `?query=<raw>` (legacy) or `?q=<base64url>` (Dr. Brief
// deeplinks — base64 lets us pack complex SQL with quotes / newlines through the URL).
$initialQuery = 'SELECT * FROM pages LIMIT 100';
if (isset($_GET['q']) && $_GET['q'] !== '') {
    $decoded = base64_decode(strtr($_GET['q'], '-_', '+/'), true);
    if ($decoded !== false && $decoded !== '') {
        $initialQuery = $decoded;
    }
} elseif (isset($_GET['query']) && $_GET['query'] !== '') {
    $initialQuery = $_GET['query'];
}

// Requêtes pré-enregistrées (adaptées pour PostgreSQL)
// Convention : chaque entrée porte un `category_key` machine-friendly (utilisé
// par le filtre JS) + un `category` traduit (affiché à l'utilisateur).
$savedQueries = [
    // === INDEXABILITÉ ===
    ['key' => 'response_codes',         'category_key' => 'indexability', 'query' => "SELECT\n\tcode,\n\tCOUNT(url) AS urls\nFROM pages\nWHERE crawled = true\nGROUP BY code\nORDER BY urls DESC"],
    ['key' => 'non_indexable_reasons',  'category_key' => 'indexability', 'query' => "SELECT\n\tCASE\n\t\tWHEN code != 200 AND code IS NOT NULL THEN 'Bad status'\n\t\tWHEN noindex = true THEN 'Noindex'\n\t\tWHEN canonical = false THEN 'Non canonical'\n\t\tWHEN blocked = true THEN 'Blocked by robots.txt'\n\t\tELSE 'Other'\n\tEND AS reason,\n\tCOUNT(*) AS urls\nFROM pages\nWHERE compliant = false AND external = false\nGROUP BY reason\nORDER BY urls DESC"],
    ['key' => 'canonical_different',    'category_key' => 'indexability', 'query' => "SELECT\n\turl,\n\tcanonical_value\nFROM pages\nWHERE crawled = true\n  AND canonical_value IS NOT NULL\n  AND canonical_value != ''\n  AND canonical_value != url\nORDER BY url\nLIMIT 100"],
    ['key' => 'pages_4xx_linked',       'category_key' => 'indexability', 'query' => "SELECT\n\tp.url,\n\tp.code,\n\tCOUNT(l.src) AS inlinks_count\nFROM pages p\nINNER JOIN links l ON l.target = p.id AND l.external = false\nWHERE p.code >= 400 AND p.code < 500\nGROUP BY p.url, p.code\nORDER BY inlinks_count DESC\nLIMIT 50"],
    ['key' => 'pages_5xx',              'category_key' => 'indexability', 'query' => "SELECT\n\turl,\n\tcode,\n\tdepth\nFROM pages\nWHERE code >= 500 AND code < 600\nORDER BY code, url\nLIMIT 100"],
    ['key' => 'out_of_scope',           'category_key' => 'indexability', 'query' => "SELECT\n\turl,\n\tdepth,\n\tinlinks\nFROM pages\nWHERE external = false AND blocked = false AND crawled = false\nORDER BY inlinks DESC\nLIMIT 100"],

    // === MAILLAGE INTERNE ===
    ['key' => 'orphan_pages',           'category_key' => 'internal_linking', 'query' => "SELECT\n\turl,\n\tdepth,\n\tcode\nFROM pages\nWHERE compliant = true AND inlinks = 0 AND depth > 0\nORDER BY depth\nLIMIT 100"],
    ['key' => 'single_inlink',          'category_key' => 'internal_linking', 'query' => "SELECT\n\turl,\n\tdepth,\n\tinlinks\nFROM pages\nWHERE compliant = true AND inlinks = 1\nORDER BY url\nLIMIT 100"],
    ['key' => 'top_inlinks',            'category_key' => 'internal_linking', 'query' => "SELECT\n\turl,\n\tinlinks,\n\tpri AS pagerank\nFROM pages\nWHERE crawled = true AND compliant = true\nORDER BY inlinks DESC\nLIMIT 50"],
    ['key' => 'top_anchors',            'category_key' => 'internal_linking', 'query' => "SELECT\n\tanchor,\n\tCOUNT(*) AS occurrences\nFROM links\nWHERE anchor IS NOT NULL AND anchor != ''\nGROUP BY anchor\nORDER BY occurrences DESC\nLIMIT 50"],
    ['key' => 'links_by_position',      'category_key' => 'internal_linking', 'query' => "SELECT\n\tposition,\n\tCOUNT(*) AS links_count\nFROM links\nGROUP BY position\nORDER BY links_count DESC"],
    ['key' => 'internal_nofollow',      'category_key' => 'internal_linking', 'query' => "SELECT\n\ts.url AS source,\n\tt.url AS target,\n\tl.anchor,\n\tl.position\nFROM links l\nLEFT JOIN pages s ON l.src = s.id\nLEFT JOIN pages t ON l.target = t.id\nWHERE l.external = false AND l.nofollow = true\nLIMIT 100"],
    ['key' => 'internal_links_to_4xx',  'category_key' => 'internal_linking', 'query' => "SELECT\n\ts.url AS source,\n\tt.url AS target,\n\tt.code,\n\tl.anchor,\n\tl.position\nFROM links l\nINNER JOIN pages s ON l.src = s.id\nINNER JOIN pages t ON l.target = t.id\nWHERE l.external = false\n  AND t.code >= 400 AND t.code < 500\nORDER BY t.code, s.url\nLIMIT 100"],
    ['key' => 'high_outlinks',          'category_key' => 'internal_linking', 'query' => "SELECT\n\turl,\n\toutlinks,\n\tinlinks\nFROM pages\nWHERE crawled = true AND outlinks > 100\nORDER BY outlinks DESC\nLIMIT 50"],

    // === PAGERANK ===
    ['key' => 'top_pagerank',           'category_key' => 'pagerank', 'query' => "SELECT\n\turl,\n\tpri AS pagerank,\n\tinlinks,\n\toutlinks\nFROM pages\nWHERE crawled = true AND compliant = true\nORDER BY pagerank DESC\nLIMIT 50"],
    ['key' => 'high_pr_non_indexable',  'category_key' => 'pagerank', 'query' => "SELECT\n\turl,\n\tpri AS pagerank,\n\tcode,\n\tnoindex,\n\tcanonical,\n\tblocked\nFROM pages\nWHERE crawled = true AND compliant = false AND pri > 0\nORDER BY pagerank DESC\nLIMIT 50"],
    ['key' => 'pr_leak_external',       'category_key' => 'pagerank', 'query' => "SELECT\n\tdomain,\n\tCOUNT(*) AS link_count,\n\tSUM(pri) AS total_pr\nFROM pages\nWHERE external = true\nGROUP BY domain\nORDER BY total_pr DESC\nLIMIT 30"],
    ['key' => 'dead_end_with_pr',       'category_key' => 'pagerank', 'query' => "SELECT\n\tp.url,\n\tp.pri AS pagerank\nFROM pages p\nWHERE p.crawled = true\n  AND p.pri > 0\n  AND p.id NOT IN (SELECT src FROM links)\nORDER BY pagerank DESC\nLIMIT 50"],

    // === SEO TAGS ===
    ['key' => 'duplicate_titles',       'category_key' => 'seo_tags', 'query' => "SELECT\n\ttitle,\n\tCOUNT(*) AS pages_count\nFROM pages\nWHERE crawled = true AND title IS NOT NULL AND title != ''\nGROUP BY title\nHAVING COUNT(*) > 1\nORDER BY pages_count DESC\nLIMIT 50"],
    ['key' => 'bad_length_titles',      'category_key' => 'seo_tags', 'query' => "SELECT\n\turl,\n\ttitle,\n\tLENGTH(title) AS title_length\nFROM pages\nWHERE crawled = true AND is_html = true\n  AND (title IS NULL OR title = '' OR LENGTH(title) < 30 OR LENGTH(title) > 60)\nORDER BY title_length ASC NULLS FIRST\nLIMIT 100"],
    ['key' => 'duplicate_h1',           'category_key' => 'seo_tags', 'query' => "SELECT\n\th1,\n\tCOUNT(*) AS pages_count\nFROM pages\nWHERE crawled = true AND h1 IS NOT NULL AND h1 != ''\nGROUP BY h1\nHAVING COUNT(*) > 1\nORDER BY pages_count DESC\nLIMIT 50"],
    ['key' => 'h1_not_matching_title',  'category_key' => 'seo_tags', 'query' => "SELECT\n\turl,\n\ttitle,\n\th1\nFROM pages\nWHERE crawled = true AND is_html = true\n  AND title IS NOT NULL AND title != ''\n  AND h1 IS NOT NULL AND h1 != ''\n  AND title != h1\nLIMIT 100"],
    ['key' => 'multiple_h1',            'category_key' => 'seo_tags', 'query' => "SELECT\n\turl,\n\th1\nFROM pages\nWHERE h1_multiple = true\nLIMIT 100"],
    ['key' => 'empty_metadesc',         'category_key' => 'seo_tags', 'query' => "SELECT\n\turl,\n\ttitle\nFROM pages\nWHERE crawled = true AND is_html = true\n  AND (metadesc IS NULL OR metadesc = '')\nLIMIT 100"],

    // === REDIRECTIONS ===
    ['key' => 'all_redirects',          'category_key' => 'redirects', 'query' => "SELECT\n\turl,\n\tcode,\n\tredirect_to\nFROM pages\nWHERE code >= 300 AND code < 400 AND redirect_to IS NOT NULL AND redirect_to != ''\nORDER BY code, url\nLIMIT 100"],
    ['key' => 'redirect_to_error',      'category_key' => 'redirects', 'query' => "SELECT\n\tp1.url AS source,\n\tp1.code AS source_code,\n\tp1.redirect_to AS target,\n\tp2.code AS target_code\nFROM pages p1\nINNER JOIN pages p2 ON p2.url = p1.redirect_to\nWHERE p1.code >= 300 AND p1.code < 400\n  AND p2.code >= 400\nLIMIT 100"],

    // === SITEMAP ===
    ['key' => 'indexable_not_in_sitemap','category_key' => 'sitemap', 'query' => "SELECT\n\turl,\n\tpri AS pagerank,\n\tinlinks\nFROM pages\nWHERE compliant = true AND in_sitemap = false\nORDER BY pagerank DESC\nLIMIT 100"],
    ['key' => 'sitemap_non_indexable',  'category_key' => 'sitemap', 'query' => "SELECT\n\turl,\n\tcode,\n\tnoindex,\n\tcanonical,\n\tblocked\nFROM pages\nWHERE in_sitemap = true AND compliant = false\nLIMIT 100"],
    ['key' => 'sitemap_only',           'category_key' => 'sitemap', 'query' => "SELECT\n\turl\nFROM pages\nWHERE in_sitemap = true AND in_crawl = false\nLIMIT 100"],

    // === PERFORMANCE ===
    ['key' => 'slowest_pages',          'category_key' => 'performance', 'query' => "SELECT\n\turl,\n\tresponse_time,\n\tcode\nFROM pages\nWHERE crawled = true AND response_time > 0\nORDER BY response_time DESC\nLIMIT 20"],
    ['key' => 'ttfb_distribution',      'category_key' => 'performance', 'query' => "SELECT\n\tCASE\n\t\tWHEN response_time < 200 THEN '< 200 ms'\n\t\tWHEN response_time < 500 THEN '200-500 ms'\n\t\tWHEN response_time < 1000 THEN '500-1000 ms'\n\t\tWHEN response_time < 2000 THEN '1-2 s'\n\t\tELSE '> 2 s'\n\tEND AS ttfb_range,\n\tCOUNT(*) AS pages_count\nFROM pages\nWHERE crawled = true AND response_time > 0\nGROUP BY ttfb_range\nORDER BY MIN(response_time)"],

    // === DONNÉES STRUCTURÉES ===
    ['key' => 'schema_distribution',    'category_key' => 'structured_data', 'query' => "SELECT\n\tschema_type,\n\tCOUNT(*) AS pages_count\nFROM page_schemas\nGROUP BY schema_type\nORDER BY pages_count DESC"],
    ['key' => 'indexable_no_schema',    'category_key' => 'structured_data', 'query' => "SELECT\n\tp.url,\n\tp.pri AS pagerank\nFROM pages p\nWHERE p.compliant = true\n  AND p.id NOT IN (SELECT page_id FROM page_schemas)\nORDER BY pagerank DESC\nLIMIT 100"],

    // === CONTENU ===
    ['key' => 'thin_content',           'category_key' => 'content', 'query' => "SELECT\n\turl,\n\tword_count,\n\tpri AS pagerank\nFROM pages\nWHERE compliant = true AND word_count < 250 AND word_count > 0\nORDER BY pagerank DESC\nLIMIT 100"],
    ['key' => 'word_count_by_category', 'category_key' => 'content', 'query' => "SELECT\n\tIF(category = '', 'Uncategorized', category) AS category,\n\tCOUNT(*) AS pages,\n\tROUND(AVG(word_count), 0) AS avg_words,\n\tMIN(word_count) AS min_words,\n\tMAX(word_count) AS max_words\nFROM pages\nWHERE compliant = true\nGROUP BY category\nORDER BY pages DESC"],

    // === VUE GLOBALE ===
    ['key' => 'category_overview',      'category_key' => 'overview', 'query' => "SELECT\n\tIF(category = '', 'Uncategorized', category) AS category,\n\tCOUNT(*) AS total_pages,\n\tSUM(CASE WHEN compliant THEN 1 ELSE 0 END) AS indexable_pages,\n\tROUND(100.0 * SUM(CASE WHEN compliant THEN 1 ELSE 0 END) / COUNT(*), 1) AS pct_indexable,\n\tROUND(AVG(pri), 5) AS avg_pagerank,\n\tROUND(AVG(inlinks), 1) AS avg_inlinks,\n\tROUND(AVG(word_count), 0) AS avg_words\nFROM pages\nWHERE external = false AND in_crawl = true\nGROUP BY category\nORDER BY total_pages DESC"],
];

// Hydrate name/description/category depuis i18n
foreach ($savedQueries as &$q) {
    $q['name']        = __('sql_explorer.query_' . $q['key']);
    $q['description'] = __('sql_explorer.query_' . $q['key'] . '_desc');
    $q['category']    = __('sql_explorer.cat_' . $q['category_key']);
}
unset($q);
?>

<style>
/* === SQL WORKSPACE - Interface IDE Plein Écran === */

/* Override main-content pour pleine largeur */
.main-content:has(.sql-workspace-container) {
    max-width: 100%;
    padding: 0;
}

/* Container qui override le padding du main-content */
.sql-workspace-container {
    height: calc(100vh - 72px);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.sql-explorer-layout {
    display: grid;
    grid-template-columns: 240px 1fr;
    gap: 0;
    flex: 1;
    overflow: hidden;
    background: var(--background);
    transition: grid-template-columns 0.3s ease;
}

.sql-explorer-layout.sidebar-collapsed {
    grid-template-columns: 0 1fr;
}

.sql-sidebar {
    background: #f8f9fb;
    border-right: 1px solid var(--border-color);
    overflow-y: auto;
    overflow-x: hidden;
    font-size: 0.8rem;
    display: flex;
    flex-direction: column;
    transition: transform 0.3s ease, opacity 0.3s ease;
}

.sql-explorer-layout.sidebar-collapsed .sql-sidebar {
    transform: translateX(-100%);
    opacity: 0;
    pointer-events: none;
}

.sql-sidebar h3 {
    margin: 0;
    padding: 0.6rem 0.75rem;
    color: #374151;
    font-size: 0.7rem;
    font-weight: 700;
    background: #e8ecf1;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    gap: 0.4rem;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}

.sql-sidebar h3 .material-symbols-outlined {
    font-size: 16px;
    color: var(--primary-color);
}

/* Bouton toggle sidebar - positionné dans sql-main */
.sidebar-toggle {
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    z-index: 10;
    width: 16px;
    height: 50px;
    background: #eef1f5;
    border: 1px solid var(--border-color);
    border-left: none;
    border-radius: 0 4px 4px 0;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
    color: var(--text-secondary);
    padding: 0;
}

.sidebar-toggle:hover {
    background: var(--primary-color);
    color: white;
    border-color: var(--primary-color);
}

.sidebar-toggle .material-symbols-outlined {
    font-size: 14px;
    transition: transform 0.3s ease;
}

.sql-explorer-layout.sidebar-collapsed .sidebar-toggle .material-symbols-outlined {
    transform: rotate(180deg);
}

.table-item {
    border-bottom: 1px solid rgba(0,0,0,0.06);
}

.table-item:last-child {
    border-bottom: none;
}

.table-header {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.4rem 0.75rem;
    cursor: pointer;
    transition: background-color 0.15s;
    user-select: none;
    margin-bottom: 0;
}

.table-header:hover {
    background: rgba(0,0,0,0.04);
}

.table-header.active {
    background: rgba(0,0,0,0.04);
}

.table-name {
    flex: 1;
    font-weight: 500;
    font-size: 0.8rem;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 0.4rem;
}

.table-name .material-symbols-outlined {
    font-size: 14px;
    color: #9CA3AF;
}

.table-header:hover .table-name .material-symbols-outlined {
    color: var(--primary-color);
}

.table-icon {
    font-size: 14px;
    color: var(--text-secondary);
    transition: transform 0.15s;
}

.table-header.active .table-icon {
    transform: rotate(90deg);
}

.table-columns {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.2s ease;
    background: rgba(0,0,0,0.02);
    margin-top: 0;
}

.table-columns.expanded {
    max-height: 300px;
    overflow-y: auto;
}

.column-item {
    padding: 0.25rem 0.75rem 0.25rem 1.5rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.75rem;
    border-bottom: 1px solid rgba(0,0,0,0.03);
}

.column-item:last-child {
    border-bottom: none;
}

.column-item:hover {
    background: rgba(0,0,0,0.02);
}

.column-name {
    color: var(--text-primary);
    font-family: 'Fira Code', 'Consolas', 'Courier New', monospace;
    font-size: 0.72rem;
}

.column-type {
    color: var(--text-secondary);
    font-size: 0.65rem;
    text-transform: uppercase;
    font-weight: 500;
    opacity: 0.7;
}

.sql-main {
    display: flex;
    flex-direction: column;
    gap: 0;
    overflow: hidden;
    min-height: 0;
    height: 100%;
    background: var(--card-bg);
    position: relative;
}

.sql-editor-container {
    background: var(--card-bg);
    display: flex;
    flex-direction: column;
    border-bottom: 1px solid var(--border-color);
    flex-shrink: 0;
}

/* ============================================================
   AI SQL — Copilot-style popover, no permanent vertical space loss
   ============================================================ */

/* Bouton dans le toolbar de l'éditeur — neutre, l'icône ✨ porte l'identité IA.
   Pas de bordure violette flashy : Exécuter (turquoise) reste l'action visuelle
   principale, "Demander à l'IA" se positionne comme une action secondaire. */
.ai-sql-toolbar-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    height: 32px;
    padding: 0 0.75rem;
    background: var(--bg-secondary, #f4f6f8);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.15s;
    margin-left: 0.5rem;
}
.ai-sql-toolbar-btn:hover:not(:disabled) {
    background: white;
    border-color: #667eea;
    color: var(--text-primary);
}
.ai-sql-toolbar-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
/* Icône en violet pour signaler l'IA, même quand le bouton est neutre */
.ai-sql-toolbar-btn .material-symbols-outlined {
    font-size: 18px;
    color: #667eea;
}
.ai-sql-toolbar-btn:disabled .material-symbols-outlined {
    color: var(--text-secondary);
}
.ai-sql-toolbar-btn .shortcut {
    font-size: 0.7rem;
    color: var(--text-secondary);
    background: white;
    border: 1px solid var(--border-color);
    padding: 1px 5px;
    border-radius: 3px;
    margin-left: 0.25rem;
}

/* Popover flottant — ANCRÉ AU-DESSUS du toolbar (donc au-dessus du bouton
   qui l'a déclenché) pour ne jamais le chevaucher. Utilise `bottom` au lieu
   de `top` pour s'ouvrir vers le haut. */
.sql-editor-container {
    position: relative; /* ancre pour le popover */
}
.ai-sql-popover {
    position: absolute;
    bottom: 56px; /* hauteur du toolbar + petit gap, le popover flotte juste au-dessus */
    left: 50%;
    transform: translateX(-50%);
    width: min(560px, calc(100% - 2rem));
    background: white;
    border: 1px solid var(--border-color);
    border-radius: 10px;
    box-shadow: 0 -4px 24px rgba(0, 0, 0, 0.15);
    z-index: 100;
    padding: 0.85rem 0.9rem;
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
    animation: aiPopIn 0.15s ease-out;
}
/* Petite flèche pointant vers le bouton qui l'a ouvert (en bas du popover) */
.ai-sql-popover::after {
    content: '';
    position: absolute;
    bottom: -7px;
    left: 50%;
    transform: translateX(-50%) rotate(45deg);
    width: 12px;
    height: 12px;
    background: white;
    border-right: 1px solid var(--border-color);
    border-bottom: 1px solid var(--border-color);
}
@keyframes aiPopIn {
    from { opacity: 0; transform: translateX(-50%) translateY(6px); }
    to   { opacity: 1; transform: translateX(-50%) translateY(0); }
}
.ai-sql-popover-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    min-height: 28px; /* aligne le titre et le bouton close sur même axe vertical */
}
.ai-sql-popover-icon {
    color: #667eea;
    font-size: 20px;
    line-height: 1;
}
.ai-sql-popover-title {
    font-weight: 600;
    color: var(--text-primary);
    flex: 1;
    font-size: 0.95rem;
    line-height: 1;
}
.ai-sql-popover-close {
    width: 28px;
    height: 28px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: transparent;
    border: none;
    color: var(--text-secondary);
    cursor: pointer;
    border-radius: 6px;
    transition: all 0.15s;
    padding: 0;
    line-height: 1;
}
.ai-sql-popover-close:hover {
    background: var(--bg-secondary, #f4f6f8);
    color: var(--text-primary);
}
.ai-sql-popover-close .material-symbols-outlined {
    font-size: 18px;
    line-height: 1;
}
.ai-sql-popover-input {
    width: 100%;
    padding: 0.6rem 0.75rem;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 0.9rem;
    font-family: inherit;
    background: white;
    color: var(--text-primary);
    resize: none; /* on bloque la poignée de redim navigateur, design propre */
    height: 72px;
    transition: border-color 0.15s;
    box-sizing: border-box;
    line-height: 1.4;
}
.ai-sql-popover-input:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.15);
}
.ai-sql-popover-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.75rem;
    min-height: 32px; /* aligne hint et bouton sur même axe vertical */
}
.ai-sql-popover-hint {
    font-size: 0.75rem;
    color: var(--text-secondary);
    background: var(--bg-secondary, #f4f6f8);
    padding: 4px 8px;
    border-radius: 4px;
    line-height: 1;
    display: inline-flex;
    align-items: center;
}

/* Bouton "Générer" du popover — accent gradient */
.ai-sql-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    height: 32px;
    padding: 0 0.9rem;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s;
    white-space: nowrap;
}
.ai-sql-btn:hover:not(:disabled) {
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
}
.ai-sql-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}
.ai-sql-btn .material-symbols-outlined {
    font-size: 18px;
}
.ai-sql-btn-spinner {
    width: 14px;
    height: 14px;
    border: 2px solid rgba(255,255,255,0.3);
    border-top-color: white;
    border-radius: 50%;
    animation: aiSqlSpin 0.75s linear infinite;
    display: inline-block;
}
@keyframes aiSqlSpin {
    to { transform: rotate(360deg); }
}

/* Toolbar unifié pour l'éditeur */
.sql-editor-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.4rem 0.75rem;
    background: #f8f9fb;
    border-bottom: 1px solid var(--border-color);
    gap: 0.5rem;
}

.toolbar-left {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex: 1;
    min-width: 0;
}

.toolbar-right {
    display: flex;
    align-items: center;
    gap: 0.4rem;
}

/* Bouton Exécuter compact style Play */
.execute-btn {
    background: var(--primary-color);
    color: white;
    border: none;
    padding: 0.35rem 0.75rem;
    border-radius: 4px;
    font-weight: 500;
    font-size: 0.8rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 0.35rem;
    transition: all 0.15s;
}

.execute-btn:hover {
    background: var(--primary-dark);
    box-shadow: 0 2px 6px rgba(0,0,0,0.15);
}

.execute-btn:active {
    transform: scale(0.98);
}

.execute-btn .material-symbols-outlined {
    font-size: 18px;
}

.execute-btn .shortcut {
    font-size: 0.65rem;
    opacity: 0.85;
    background: rgba(255,255,255,0.2);
    padding: 0.1rem 0.35rem;
    border-radius: 2px;
    margin-left: 0.25rem;
}

/* Bouton "Enregistrer" — ghost (transparent), groupé avec les actions secondaires à droite */
.save-query-btn {
    background: transparent;
    color: #6b7280;
    border: none;
    padding: 0.35rem 0.6rem;
    border-radius: 4px;
    font-weight: 500;
    font-size: 0.8rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 0.35rem;
    transition: all 0.15s;
}
.save-query-btn:hover {
    background: rgba(0,0,0,0.04);
    color: var(--primary-color);
}
.save-query-btn .material-symbols-outlined { font-size: 16px; }

/* Modale Save Query */
.sq-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 10000;
}
.sq-modal-overlay.active { display: flex; }
.sq-modal {
    background: white;
    border-radius: 8px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    width: 480px;
    max-width: 90vw;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.sq-modal-header {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.sq-modal-title {
    font-size: 1rem;
    font-weight: 600;
    color: #111827;
    margin: 0;
}
.sq-modal-close {
    background: transparent;
    border: none;
    color: #6b7280;
    cursor: pointer;
    padding: 4px;
    display: flex;
}
.sq-modal-close:hover { color: #111827; }
.sq-modal-body {
    padding: 1.25rem;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}
.sq-form-field { display: flex; flex-direction: column; gap: 0.25rem; }
.sq-form-field label {
    font-size: 0.8rem;
    font-weight: 500;
    color: #374151;
}
.sq-form-field input,
.sq-form-field textarea,
.sq-form-field select {
    padding: 0.5rem 0.65rem;
    border: 1px solid #d1d5db;
    border-radius: 4px;
    font-size: 0.85rem;
    color: #111827;
    background: white;
    outline: none;
    font-family: inherit;
    box-sizing: border-box;
    width: 100%;
}
.sq-form-field input:focus,
.sq-form-field textarea:focus,
.sq-form-field select:focus {
    border-color: var(--primary-color);
}
.sq-form-field textarea { min-height: 60px; resize: vertical; }
.sq-modal-footer {
    padding: 0.75rem 1.25rem;
    border-top: 1px solid #e5e7eb;
    display: flex;
    gap: 0.5rem;
    justify-content: flex-end;
}
.sq-btn {
    padding: 0.45rem 1rem;
    border-radius: 4px;
    font-weight: 500;
    font-size: 0.85rem;
    cursor: pointer;
    border: none;
}
.sq-btn-cancel {
    background: transparent;
    color: #6b7280;
}
.sq-btn-cancel:hover { color: #111827; }
.sq-btn-primary {
    background: var(--primary-color);
    color: white;
}
.sq-btn-primary:hover { background: var(--primary-dark); }
.sq-modal-error {
    display: none;
    background: #fee2e2;
    color: #991b1b;
    padding: 0.5rem 0.75rem;
    border-radius: 4px;
    font-size: 0.8rem;
}
.sq-modal-error.active { display: block; }

/* Bouton aide compact */
.help-btn {
    background: transparent;
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
    padding: 0.35rem;
    border-radius: 4px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s;
}

.help-btn:hover {
    background: var(--background);
    color: var(--text-primary);
    border-color: var(--text-secondary);
}

.help-btn .material-symbols-outlined {
    font-size: 18px;
}

#sqlEditor {
    border: none;
    font-family: 'Fira Code', 'Consolas', 'Courier New', monospace;
    font-size: 13px;
    min-height: 120px;
}

.CodeMirror {
    height: 180px;
    font-family: 'Fira Code', 'Consolas', 'Courier New', monospace;
    font-size: 13px;
    border: none;
}

.CodeMirror-gutters {
    background: #f8f9fb;
    border-right: 1px solid var(--border-color);
}

/* Styles pour les onglets - plus compacts */
.tabs-container {
    display: flex;
    /* Fond fondu sur le canvas — plus de cassure grise sous les tabs */
    background: #f1f3f6;
    overflow-x: auto;
    overflow-y: hidden;
    flex-shrink: 0;
    border-bottom: 1px solid #e5e7eb;
    scrollbar-width: thin;
}

.tabs-container::-webkit-scrollbar {
    height: 3px;
}

.tabs-container::-webkit-scrollbar-thumb {
    background: rgba(0,0,0,0.15);
    border-radius: 3px;
}

.tabs-container::-webkit-scrollbar-track {
    background: transparent;
}

.tab {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.45rem 0.75rem;
    background: transparent;
    /* Plus de séparation verticale entre les tabs : look "flat" */
    border: none;
    border-top: 2px solid transparent;
    cursor: pointer;
    font-size: 0.78rem;
    white-space: nowrap;
    transition: all 0.15s;
    min-width: 90px;
    max-width: 150px;
    user-select: none;
    color: var(--text-secondary);
}

.tab:hover {
    background: rgba(255,255,255,0.5);
    color: var(--text-primary);
}

.tab.active {
    /* L'onglet actif fusionne avec le fond blanc de l'éditeur, marqué par une
       fine ligne verte en haut (style VS Code / Chrome DevTools) */
    background: var(--card-bg);
    color: var(--text-primary);
    border-top: 2px solid var(--primary-color);
    margin-bottom: -1px; /* recouvre la bordure du container pour fusion */
}

.tab-title {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
}

.tab-title-input {
    flex: 1;
    background: transparent;
    border: 1px solid var(--primary-color);
    border-radius: 2px;
    padding: 0.15rem 0.25rem;
    font-size: 0.78rem;
    color: var(--text-primary);
    outline: none;
}

.tab-close {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    font-size: 11px;
    opacity: 0;
    transition: all 0.15s;
}

.tab:hover .tab-close {
    opacity: 0.6;
}

.tab-close:hover {
    opacity: 1 !important;
    background: rgba(255, 0, 0, 0.1);
    color: #ff4444;
}

.tab-add {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0.45rem 0.5rem;
    background: transparent;
    cursor: pointer;
    font-size: 14px;
    color: var(--text-secondary);
    transition: all 0.15s;
}

.tab-add:hover {
    background: rgba(255,255,255,0.5);
    color: var(--primary-color);
}

.tab-add .material-symbols-outlined {
    font-size: 16px;
}

/* Styles pour la modale d'aide - Template URL Modal */
.help-modal {
    display: none;
    position: fixed;
    z-index: 9999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    overflow: auto;
}

.help-modal.active {
    display: flex;
    align-items: center;
    justify-content: center;
}

.help-modal-content {
    background-color: #2C3E50;
    margin: 2rem;
    padding: 0;
    border-radius: 12px;
    width: 90%;
    max-width: 1000px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 10px 40px rgba(0,0,0,0.3);
    overflow: hidden;
}

.help-modal-header {
    padding: 1.25rem 2rem;
    border-bottom: none;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    background: linear-gradient(135deg, #1a252f 0%, #2C3E50 100%);
    border-radius: 12px 12px 0 0;
}

.help-modal-header h2 {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    color: white;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.help-modal-header h2 .material-symbols-outlined {
    color: var(--primary-color);
    font-size: 22px;
    flex-shrink: 0;
}

.help-modal-close {
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    cursor: pointer;
    padding: 0.5rem;
    color: rgba(255, 255, 255, 0.8);
    transition: all 0.2s;
    border-radius: 8px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
}

.help-modal-close .material-symbols-outlined {
    font-size: 20px;
}

.help-modal-close:hover {
    background: rgba(231, 76, 60, 0.9);
    border-color: rgba(231, 76, 60, 0.9);
    color: white;
}

.help-modal-body {
    padding: 2rem;
    overflow-y: auto;
    flex: 1;
    background: white;
}

.help-modal-body::-webkit-scrollbar {
    width: 12px;
}

.help-modal-body::-webkit-scrollbar-track {
    background: #f1f1f1;
}

.help-modal-body::-webkit-scrollbar-thumb {
    background: #888;
    border-radius: 6px;
}

.help-modal-body::-webkit-scrollbar-thumb:hover {
    background: #555;
}

.help-modal-body h3 {
    color: var(--primary-color);
    margin: 2rem 0 1rem 0;
    font-size: 1.3rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.help-modal-body h3:first-child {
    margin-top: 0;
}

.help-modal-body h4 {
    color: var(--text-primary);
    margin: 1.5rem 0 0.75rem 0;
    font-size: 1.1rem;
}

.help-modal-body p {
    line-height: 1.6;
    color: var(--text-secondary);
    margin: 0.75rem 0;
}

.help-modal-body ul {
    margin: 0.75rem 0;
    padding-left: 1.5rem;
}

.help-modal-body li {
    margin: 0.5rem 0;
    line-height: 1.6;
    color: var(--text-secondary);
}

.help-modal-body code {
    background: #f5f5f5;
    padding: 0.2rem 0.4rem;
    border-radius: 4px;
    font-family: 'Courier New', monospace;
    color: #e74c3c;
}

.help-modal-body pre {
    background: #282c34;
    color: #abb2bf;
    padding: 1rem;
    border-radius: 6px;
    overflow-x: auto;
    margin: 1rem 0;
    font-family: 'Courier New', monospace;
    line-height: 1.5;
}

.help-modal-body pre code {
    background: transparent;
    padding: 0;
    color: inherit;
}

.schema-table {
    width: 100%;
    border-collapse: collapse;
    margin: 1rem 0;
    font-size: 0.9rem;
}

.schema-table th,
.schema-table td {
    padding: 0.75rem;
    text-align: left;
    border-bottom: 1px solid var(--border-color);
}

.schema-table th {
    background: var(--background);
    font-weight: 600;
    color: var(--text-primary);
}

.schema-table td {
    color: var(--text-secondary);
}

.schema-table code {
    background: var(--background);
    color: var(--primary-color);
    font-weight: 500;
}

/* Styles pour le layout avec graphique intégré */
.results-with-chart {
    flex: 1;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.results-chart-split {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
    flex: 1;
    overflow: hidden;
    background: #F9FAFB;
    padding: 0.5rem;
}

.results-chart-split .results-table-wrapper {
    margin: 0;
    border: 1px solid #E5E7EB;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}

/* Panneau graphique - Card avec bordure */
.chart-panel {
    background: white;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    border: 1px solid #E5E7EB;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}

.chart-panel-header {
    padding: 0.6rem 1rem;
    border-bottom: 1px solid #E5E7EB;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: white;
    flex-shrink: 0;
}

.chart-panel-header h4 {
    margin: 0;
    font-size: 0.95rem;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 700;
}

.chart-panel-header h4 .material-symbols-outlined {
    font-size: 20px;
    color: var(--primary-color);
}

.chart-type-switch {
    display: flex;
    gap: 2px;
    background: transparent;
}

.chart-type-btn {
    padding: 0.3rem;
    border: 1px solid var(--border-color);
    background: white;
    color: var(--text-secondary);
    cursor: pointer;
    border-radius: 4px;
    transition: all 0.15s;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
}

.chart-type-btn .material-symbols-outlined {
    font-size: 16px;
}

.chart-type-btn:hover {
    border-color: var(--primary-color);
    color: var(--primary-color);
}

.chart-type-btn.active {
    background: var(--primary-color);
    border-color: var(--primary-color);
    color: white;
}

.chart-container-inline {
    flex: 1;
    padding: 1rem;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    background: white;
}

.chart-container-inline canvas {
    max-width: 100%;
    max-height: 100%;
}

/* Styles pour l'autocomplétion */
.CodeMirror-hints {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 6px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    font-family: 'Courier New', monospace;
    font-size: 0.85rem;
    max-height: 200px;
    overflow-y: auto;
}

.CodeMirror-hint {
    padding: 0.5rem 0.75rem;
    color: var(--text-primary);
    cursor: pointer;
    border-bottom: 1px solid rgba(0,0,0,0.05);
}

.CodeMirror-hint:last-child {
    border-bottom: none;
}

.CodeMirror-hint-active {
    background: var(--primary-color);
    color: white;
}

.CodeMirror-hint:hover {
    background: var(--background);
}

.CodeMirror-hint-active:hover {
    background: var(--primary-color);
}

/* Styles spécifiques par type de suggestion */
.hint-table {
    color: #2196F3;
    font-weight: 600;
}

.hint-column {
    color: #4CAF50;
}

.hint-column-alias {
    color: #FF9800;
    font-style: italic;
}

.hint-column-full {
    color: #9C27B0;
    font-family: 'Courier New', monospace;
}

/* Styles pour les requêtes sauvegardées - compactes */
.saved-queries-section {
    border-top: 1px solid var(--border-color);
    flex: 1;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
}

/* Accordéons des saved queries (prédéfinies + user) */
.sq-accordion-group { display: flex; flex-direction: column; }
.sq-accordion { border-bottom: 1px solid rgba(0,0,0,0.06); }
.sq-accordion-header {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.4rem 0.75rem;
    cursor: pointer;
    background: #f8f9fb;
    transition: background-color 0.15s;
    user-select: none;
}
.sq-accordion-header:hover { background: #eef1f5; }
.sq-accordion-header .sq-chevron {
    font-size: 16px;
    color: #6b7280;
    transition: transform 0.2s;
}
.sq-accordion.open > .sq-accordion-header .sq-chevron { transform: rotate(90deg); }
.sq-accordion-title {
    flex: 1;
    font-size: 0.75rem;
    font-weight: 600;
    color: #374151;
}
.sq-accordion-count {
    font-size: 0.65rem;
    color: #9ca3af;
    background: #e5e7eb;
    padding: 1px 6px;
    border-radius: 8px;
    min-width: 18px;
    text-align: center;
}
.sq-cat-actions {
    display: none;
    gap: 0.15rem;
    margin-left: 0.25rem;
}
.sq-accordion-header:hover .sq-cat-actions { display: flex; }
.sq-cat-action {
    background: transparent;
    border: none;
    color: #9ca3af;
    cursor: pointer;
    padding: 2px;
    border-radius: 3px;
    display: flex;
    align-items: center;
}
.sq-cat-action:hover { color: var(--primary-color); background: rgba(0,0,0,0.05); }
.sq-cat-action.danger:hover { color: #dc2626; }
.sq-cat-action .material-symbols-outlined { font-size: 14px; }
.sq-accordion-body { display: none; }
.sq-accordion.open > .sq-accordion-body { display: block; }
.sq-empty-state {
    padding: 0.75rem;
    text-align: center;
    color: #9ca3af;
    font-size: 0.7rem;
    font-style: italic;
}

/* Actions hover sur les requêtes user (edit/delete) */
.query-item-actions {
    display: none;
    gap: 0.25rem;
    margin-left: auto;
}
.query-item:hover .query-item-actions { display: flex; }
.query-item-action {
    background: transparent;
    border: none;
    color: #9ca3af;
    cursor: pointer;
    padding: 2px;
    border-radius: 3px;
    display: flex;
    align-items: center;
}
.query-item-action:hover { color: var(--primary-color); background: rgba(0,0,0,0.05); }
.query-item-action.danger:hover { color: #dc2626; }
.query-item-action .material-symbols-outlined { font-size: 14px; }

.query-item {
    padding: 0.35rem 0.75rem;
    /* Indentation pour montrer la hiérarchie parent (accordéon) > enfant (requête) */
    padding-left: 2rem;
    cursor: pointer;
    transition: background-color 0.15s;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.query-item .query-icon {
    font-size: 13px;
    /* Très clair au repos : ne doit pas attirer l'œil plus que le texte */
    color: #d1d5db;
    flex-shrink: 0;
}

.query-item:hover .query-icon {
    color: var(--primary-color);
}

.query-item-content {
    flex: 1;
    min-width: 0;
}

.query-item:hover {
    background: rgba(0,0,0,0.03);
}

.query-item:active {
    background: var(--primary-color);
    color: white;
}

.query-name {
    font-size: 0.78rem;
    font-weight: 500;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Descriptions retirées de la liste (trop dense) — visible en tooltip natif
   via l'attribut title="..." sur .query-item */
.query-description { display: none; }

.query-item:hover .query-description {
    color: var(--text-primary);
}

.query-item:active .query-name,
.query-item:active .query-description,
.query-item:active .query-icon {
    color: white;
}

.sql-results-container {
    background: white;
    flex: 1;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    min-height: 0;
    /* Simple ligne fine séparatrice entre éditeur et résultats — pas de bandeau */
    border-top: 1px solid #e5e7eb;
}

/* Toolbar de résultats : fond blanc, bordure très discrète */
.sql-results-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.35rem 0.75rem;
    background: white;
    border-bottom: 1px solid #f1f3f6;
    flex-shrink: 0;
}

.result-info {
    color: var(--text-secondary);
    font-size: 0.78rem;
    display: flex;
    align-items: center;
    gap: 0.4rem;
}

.result-info .material-symbols-outlined {
    font-size: 16px;
}

/* Bouton secondaire (Copier) */
.btn-secondary-action {
    background: transparent;
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
    padding: 0.3rem 0.6rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 500;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 0.3rem;
    transition: all 0.15s;
}

.btn-secondary-action:hover:not(:disabled) {
    background: var(--background);
    color: var(--text-primary);
    border-color: var(--text-secondary);
}

.btn-secondary-action:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

.btn-secondary-action .material-symbols-outlined {
    font-size: 16px;
}

/* Bouton principal (Export CSV) - Style Outline Primary */
.btn-primary-action {
    background: rgba(78, 205, 196, 0.08);
    color: var(--primary-color);
    border: 1.5px solid var(--primary-color);
    padding: 0.35rem 0.75rem;
    border-radius: 5px;
    font-size: 0.78rem;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 0.35rem;
    transition: all 0.15s;
}

.btn-primary-action:hover:not(:disabled) {
    background: var(--primary-color);
    color: white;
    box-shadow: 0 2px 8px rgba(78, 205, 196, 0.35);
}

.btn-primary-action:disabled {
    opacity: 0.4;
    cursor: not-allowed;
    background: transparent;
}

.btn-primary-action .material-symbols-outlined {
    font-size: 16px;
}

.toolbar-actions {
    display: flex;
    gap: 0.4rem;
    align-items: center;
}

/* Alerte de troncature fixe */
.truncation-alert {
    padding: 0.5rem 1rem;
    background: #fff3cd;
    border-top: 2px solid #ffc107;
    color: #856404;
    font-size: 0.8rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-shrink: 0;
}

.truncation-alert .material-symbols-outlined {
    font-size: 18px;
    color: #d39e00;
}

.results-table-wrapper {
    flex: 1;
    overflow: auto;
    background: white;
}

.results-table-wrapper::-webkit-scrollbar {
    width: 12px;
    height: 12px;
}

.results-table-wrapper::-webkit-scrollbar-track {
    background: var(--background);
}

.results-table-wrapper::-webkit-scrollbar-thumb {
    background: var(--border-color);
    border-radius: 6px;
}

.results-table-wrapper::-webkit-scrollbar-thumb:hover {
    background: var(--text-secondary);
}

.results-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.82rem;
}

.results-table thead {
    background: #f8f9fb;
}

.results-table th {
    padding: 0.5rem 0.75rem;
    text-align: left;
    font-weight: 600;
    font-size: 0.75rem;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.3px;
    border-bottom: 1px solid var(--border-color);
    white-space: nowrap;
    background: #f8f9fb;
    position: sticky;
    top: 0;
    z-index: 10;
}

.results-table td {
    padding: 0.45rem 0.75rem;
    border-bottom: 1px solid rgba(0,0,0,0.05);
    color: var(--text-primary);
    max-width: 400px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.results-table tbody tr:hover {
    background: rgba(78, 205, 196, 0.05);
}

.results-table tbody tr:nth-child(even) {
    background: rgba(0,0,0,0.015);
}

.results-table tbody tr:nth-child(even):hover {
    background: rgba(78, 205, 196, 0.05);
}

.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 100%;
    min-height: 150px;
    color: var(--text-secondary);
    gap: 0.5rem;
    background: #fafbfc;
}

.empty-state .material-symbols-outlined {
    font-size: 40px;
    opacity: 0.25;
}

.empty-state p {
    font-size: 0.85rem;
    margin: 0;
}

.error-message {
    background: #FEE;
    color: #C33;
    padding: 1rem;
    border-radius: 6px;
    border-left: 4px solid #C33;
}

.success-message {
    background: #EFE;
    color: #3C3;
    padding: 1rem;
    border-radius: 6px;
    border-left: 4px solid #3C3;
}

.loading {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 2rem;
    color: var(--text-secondary);
    height: 100%;
    background: #fafbfc;
}

.spinner {
    border: 2px solid var(--border-color);
    border-top-color: var(--primary-color);
    border-radius: 50%;
    width: 20px;
    height: 20px;
    animation: spin 0.8s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.spinning {
    animation: spin 1s linear infinite;
}

#resultsContent {
    min-width: 100%;
}
</style>

<div class="sql-workspace-container">
<div class="sql-explorer-layout" id="sqlLayout">
    <!-- Sidebar with tables -->
    <div class="sql-sidebar">
        <h3>
            <span class="material-symbols-outlined">table_view</span>
            <?= __('sql_explorer.tables') ?>
        </h3>
        
        <?php if (!empty($tables)): ?>
            <?php 
            $tableIcons = ['pages' => 'description', 'links' => 'link', 'categories' => 'folder', 'duplicate_clusters' => 'content_copy', 'page_schemas' => 'data_object', 'redirect_chains' => 'redo'];
            foreach ($tables as $tableName => $columns): 
                $icon = $tableIcons[$tableName] ?? 'table_chart';
            ?>
            <div class="table-item">
                <div class="table-header" onclick="toggleTable('<?= $tableName ?>')">
                    <span class="table-name">
                        <span class="material-symbols-outlined"><?= $icon ?></span>
                        <?= htmlspecialchars($tableName) ?>
                    </span>
                    <span class="material-symbols-outlined table-icon">chevron_right</span>
                </div>
                <div class="table-columns" id="columns-<?= htmlspecialchars($tableName) ?>">
                    <?php foreach ($columns as $column): ?>
                    <div class="column-item">
                        <span class="column-name"><?= htmlspecialchars($column['name']) ?></span>
                        <span class="column-type"><?= htmlspecialchars($column['type']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <span class="material-symbols-outlined">database</span>
                <p><?= __('sql_explorer.no_tables') ?></p>
            </div>
        <?php endif; ?>
        
        <!-- Requêtes sauvegardées : 2 sections en accordéon (prédéfinies + user) -->
        <?php
        // Grouper les requêtes prédéfinies par catégorie pour les rendre en accordéons
        $categoryOrder = [
            'indexability', 'internal_linking', 'pagerank', 'seo_tags',
            'redirects', 'sitemap', 'performance', 'structured_data',
            'content', 'overview',
        ];
        $predefinedByCat = [];
        foreach ($savedQueries as $index => $q) {
            $predefinedByCat[$q['category_key']][] = ['index' => $index, 'data' => $q];
        }
        $orderedCats = array_values(array_filter($categoryOrder, fn($c) => isset($predefinedByCat[$c])));
        ?>

        <!-- Section 1 : Requêtes prédéfinies (accordéons par catégorie) -->
        <div class="saved-queries-section">
            <h3>
                <span class="material-symbols-outlined">bookmark</span>
                <?= __('sql_explorer.predefined_queries') ?>
            </h3>
            <div class="sq-accordion-group">
                <?php foreach ($orderedCats as $catKey):
                    $catLabel = __('sql_explorer.cat_' . $catKey);
                    $items = $predefinedByCat[$catKey];
                ?>
                <div class="sq-accordion" data-cat="<?= htmlspecialchars($catKey) ?>">
                    <div class="sq-accordion-header" onclick="toggleSqAccordion(this)">
                        <span class="material-symbols-outlined sq-chevron">chevron_right</span>
                        <span class="sq-accordion-title"><?= htmlspecialchars($catLabel) ?></span>
                        <span class="sq-accordion-count"><?= count($items) ?></span>
                    </div>
                    <div class="sq-accordion-body">
                        <?php foreach ($items as $entry): $q = $entry['data']; ?>
                        <div class="query-item" onclick="loadSavedQuery(<?= $entry['index'] ?>)" title="<?= htmlspecialchars($q['description']) ?>">
                            <span class="material-symbols-outlined query-icon">code</span>
                            <div class="query-item-content">
                                <div class="query-name"><?= htmlspecialchars($q['name']) ?></div>
                                <div class="query-description"><?= htmlspecialchars($q['description']) ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Section 2 : Mes requêtes (hydratée en JS via /api/saved-queries) -->
        <div class="saved-queries-section saved-queries-user">
            <h3>
                <span class="material-symbols-outlined">star</span>
                <?= __('sql_explorer.my_queries') ?>
            </h3>
            <div id="userQueriesContainer" class="sq-accordion-group">
                <div class="sq-empty-state"><?= __('sql_explorer.no_user_queries') ?></div>
            </div>
        </div>
    </div>

    <!-- Main content -->
    <div class="sql-main">
        <!-- Bouton toggle sidebar -->
        <button class="sidebar-toggle" onclick="toggleSidebar()" title="<?= __('sql_explorer.toggle_sidebar') ?>">
            <span class="material-symbols-outlined">chevron_left</span>
        </button>
        <!-- SQL Editor -->
        <div class="sql-editor-container">
            <!-- Onglets en haut -->
            <div class="tabs-container" id="tabsContainer">
                <div class="tab active" data-tab-id="0" onmousedown="handleTabMouseDown(event)" onauxclick="handleTabMiddleClick(0, event)">
                    <span class="tab-title"><?= __('sql_explorer.query_tab') ?> 1</span>
                    <span class="tab-close" onclick="closeTab(0, event)">×</span>
                </div>
                <div class="tab-add" onclick="addNewTab()">
                    <span class="material-symbols-outlined">add</span>
                </div>
            </div>

            <textarea id="sqlEditor"><?= htmlspecialchars($initialQuery) ?></textarea>

            <!-- Floating "Ask AI" popover. Hidden by default, shown above the
                 editor when the user clicks the toolbar button or hits Ctrl+K.
                 Positioned absolutely inside .sql-editor-container so it
                 overlays the editor without pushing it down. -->
            <div id="aiSqlPopover" class="ai-sql-popover" style="display: none;">
                <div class="ai-sql-popover-header">
                    <span class="material-symbols-outlined ai-sql-popover-icon">auto_awesome</span>
                    <span class="ai-sql-popover-title"><?= __('sql_explorer.ai_button_label') ?></span>
                    <button type="button" class="ai-sql-popover-close" onclick="closeAiSqlPopover()" title="<?= __('common.cancel') ?>">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <textarea id="aiSqlInput"
                          class="ai-sql-popover-input"
                          placeholder="<?= htmlspecialchars(__('sql_explorer.ai_placeholder')) ?>"
                          rows="3"></textarea>
                <div class="ai-sql-popover-footer">
                    <span class="ai-sql-popover-hint">Ctrl+Enter</span>
                    <button type="button"
                            id="aiSqlGenerateBtn"
                            class="ai-sql-btn"
                            onclick="generateSqlFromNaturalLanguage()">
                        <span class="material-symbols-outlined ai-sql-btn-icon">arrow_forward</span>
                        <span class="ai-sql-btn-spinner" style="display:none;"></span>
                        <span class="ai-sql-btn-label"><?= __('sql_explorer.ai_generate') ?></span>
                    </button>
                </div>
            </div>
            
            <!-- Toolbar unifié sous l'éditeur -->
            <div class="sql-editor-toolbar">
                <div class="toolbar-left">
                    <button class="execute-btn" onclick="executeQuery()">
                        <span class="material-symbols-outlined">play_arrow</span>
                        <?= __('sql_explorer.execute') ?>
                        <span class="shortcut">Ctrl+Enter</span>
                    </button>
                    <?php if ($aiRoleAllowed): ?>
                    <button class="ai-sql-toolbar-btn"
                            id="aiSqlOpenBtn"
                            onclick="openAiSqlPopover()"
                            <?= $sqlAiConfigured ? '' : 'disabled' ?>
                            title="<?= htmlspecialchars($sqlAiConfigured ? __('sql_explorer.ai_button_label') . ' (Ctrl+K)' : __('sql_explorer.ai_not_configured')) ?>">
                        <span class="material-symbols-outlined">auto_awesome</span>
                        <span><?= __('sql_explorer.ai_button_label') ?></span>
                        <span class="shortcut">Ctrl+K</span>
                    </button>
                    <?php endif; ?>
                </div>
                <div class="toolbar-right">
                    <button class="save-query-btn" onclick="openSaveQueryModal()" title="<?= __('sql_explorer.save_query') ?>">
                        <span class="material-symbols-outlined">save</span>
                        <?= __('sql_explorer.save_query') ?>
                    </button>
                    <button class="help-btn" onclick="showSQLHelp()" title="<?= __('sql_explorer.sql_help') ?>">
                        <span class="material-symbols-outlined">help</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Results -->
        <div class="sql-results-container">
            <!-- Toolbar de résultats -->
            <div class="sql-results-toolbar">
                <div class="result-info" id="resultInfo">
                    <span class="material-symbols-outlined">table_chart</span>
                    <span><?= __('common.results') ?></span>
                </div>
                <div class="toolbar-actions">
                    <button id="copyBtn" class="btn-secondary-action" onclick="copyTableToClipboard()" disabled title="<?= __('sql_explorer.copy_table') ?>">
                        <span class="material-symbols-outlined">content_copy</span>
                        <?= __('common.copy') ?>
                    </button>
                    <button id="exportBtn" class="btn-primary-action" onclick="exportToCSV()" disabled>
                        <span class="material-symbols-outlined">download</span>
                        <?= __('sql_explorer.export_csv') ?>
                    </button>
                </div>
            </div>
            
            <!-- Layout avec graphique (caché par défaut) -->
            <div id="resultsWithChart" class="results-with-chart" style="display: none;">
                <div class="results-chart-split">
                    <div class="results-table-wrapper">
                        <div id="resultsContentChart" class="empty-state">
                            <span class="material-symbols-outlined">play_circle</span>
                            <p><?= __('sql_explorer.execute_query_prompt') ?></p>
                        </div>
                    </div>
                    <div class="chart-panel">
                        <div class="chart-panel-header">
                            <h4>
                                <span class="material-symbols-outlined">donut_small</span>
                                <?= __('sql_explorer.chart') ?>
                            </h4>
                            <div class="chart-type-switch">
                                <button class="chart-type-btn active" data-type="doughnut" onclick="changeChartType('doughnut')">
                                    <span class="material-symbols-outlined">donut_small</span>
                                </button>
                                <button class="chart-type-btn" data-type="bar" onclick="changeChartType('bar')">
                                    <span class="material-symbols-outlined">bar_chart</span>
                                </button>
                                <button class="chart-type-btn" data-type="horizontalBar" onclick="changeChartType('horizontalBar')">
                                    <span class="material-symbols-outlined">align_horizontal_left</span>
                                </button>
                            </div>
                        </div>
                        <div class="chart-container-inline">
                            <canvas id="resultChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Layout classique (par défaut) -->
            <div id="resultsClassic" class="results-table-wrapper">
                <div id="resultsContent" class="empty-state">
                    <span class="material-symbols-outlined">play_circle</span>
                    <p><?= __('sql_explorer.run_query_prompt') ?></p>
                </div>
            </div>
            
            <!-- Alerte de troncature (fixe en bas, hors du scroll) -->
            <div id="truncationAlert" class="truncation-alert" style="display: none;"></div>
        </div>
    </div>
</div>
</div><!-- Fin sql-workspace-container -->

<!-- Modale Save Query : sert pour create et edit -->
<div id="sqSaveModal" class="sq-modal-overlay" onclick="if(event.target === this) closeSaveQueryModal()">
    <div class="sq-modal">
        <div class="sq-modal-header">
            <h3 id="sqModalTitle" class="sq-modal-title"><?= __('sql_explorer.save_query_title') ?></h3>
            <button type="button" class="sq-modal-close" onclick="closeSaveQueryModal()">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="sq-modal-body">
            <div id="sqModalError" class="sq-modal-error"></div>
            <div class="sq-form-field">
                <label for="sqName"><?= __('sql_explorer.field_name') ?></label>
                <input type="text" id="sqName" maxlength="255" placeholder="<?= __('sql_explorer.field_name_placeholder') ?>">
            </div>
            <div class="sq-form-field">
                <label for="sqDesc"><?= __('sql_explorer.field_description') ?></label>
                <textarea id="sqDesc" placeholder="<?= __('sql_explorer.field_description_placeholder') ?>"></textarea>
            </div>
            <div class="sq-form-field">
                <label for="sqCat"><?= __('sql_explorer.field_category') ?></label>
                <select id="sqCat" onchange="onSqCategoryChange()">
                    <option value="">— <?= __('sql_explorer.no_category') ?> —</option>
                    <option value="__new__"><?= __('sql_explorer.create_new_category') ?></option>
                </select>
                <input type="text" id="sqNewCat" maxlength="100" placeholder="<?= __('sql_explorer.new_category_placeholder') ?>" style="display:none; margin-top: 0.35rem;">
            </div>
        </div>
        <div class="sq-modal-footer">
            <button type="button" class="sq-btn sq-btn-cancel" onclick="closeSaveQueryModal()"><?= __('common.cancel') ?></button>
            <button type="button" class="sq-btn sq-btn-primary" onclick="submitSaveQuery()"><?= __('common.save') ?></button>
        </div>
    </div>
</div>

<!-- Modale d'aide -->
<div id="sqlHelpModal" class="help-modal" onclick="if(event.target === this) hideSQLHelp()">
    <div class="help-modal-content">
        <div class="help-modal-header">
            <h2>
                <span class="material-symbols-outlined">help</span>
                <?= __('sql_explorer.help_title') ?>
            </h2>
            <button class="help-modal-close" onclick="hideSQLHelp()">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="help-modal-body">
            <h3><?= __('sql_explorer.help_introduction') ?></h3>
            <p>
                <?= __('sql_explorer.help_introduction_text') ?>
            </p>

            <h3><?= __('sql_explorer.help_schema') ?></h3>
            <p><strong><?= __('sql_explorer.help_architecture') ?></strong> <?= __('sql_explorer.help_architecture_text') ?></p>
            
            <h4><?= __('sql_explorer.help_table_pages') ?></h4>
            <p><?= __('sql_explorer.help_table_pages_desc') ?></p>
            
            <table class="schema-table">
                <thead>
                    <tr>
                        <th><?= __('sql_explorer.help_field') ?></th>
                        <th><?= __('sql_explorer.help_type') ?></th>
                        <th><?= __('sql_explorer.help_description') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td><code>id</code></td><td>CHAR(8)</td><td><?= __('sql_explorer.help_col_id') ?></td></tr>
                    <tr><td><code>url</code></td><td>TEXT</td><td><?= __('sql_explorer.help_col_url') ?></td></tr>
                    <tr><td><code>domain</code></td><td>VARCHAR(255)</td><td><?= __('sql_explorer.help_col_domain') ?></td></tr>
                    <tr><td><code>code</code></td><td>INTEGER</td><td><?= __('sql_explorer.help_col_code') ?></td></tr>
                    <tr><td><code>depth</code></td><td>INTEGER</td><td><?= __('sql_explorer.help_col_depth') ?></td></tr>
                    <tr><td><code>crawled</code></td><td>BOOLEAN</td><td><?= __('sql_explorer.help_col_crawled') ?></td></tr>
                    <tr><td><code>compliant</code></td><td>BOOLEAN</td><td><?= __('sql_explorer.help_col_compliant') ?></td></tr>
                    <tr><td><code>external</code></td><td>BOOLEAN</td><td><?= __('sql_explorer.help_col_external') ?></td></tr>
                    <tr><td><code>blocked</code></td><td>BOOLEAN</td><td><?= __('sql_explorer.help_col_blocked') ?></td></tr>
                    <tr><td><code>noindex</code></td><td>BOOLEAN</td><td><?= __('sql_explorer.help_col_noindex') ?></td></tr>
                    <tr><td><code>nofollow</code></td><td>BOOLEAN</td><td><?= __('sql_explorer.help_col_nofollow') ?></td></tr>
                    <tr><td><code>canonical</code></td><td>BOOLEAN</td><td><?= __('sql_explorer.help_col_canonical') ?></td></tr>
                    <tr><td><code>canonical_value</code></td><td>TEXT</td><td><?= __('sql_explorer.help_col_canonical_value') ?></td></tr>
                    <tr><td><code>redirect_to</code></td><td>TEXT</td><td><?= __('sql_explorer.help_col_redirect_to') ?></td></tr>
                    <tr><td><code>content_type</code></td><td>VARCHAR(100)</td><td><?= __('sql_explorer.help_col_content_type') ?></td></tr>
                    <tr><td><code>response_time</code></td><td>FLOAT</td><td><?= __('sql_explorer.help_col_response_time') ?></td></tr>
                    <tr><td><code>inlinks</code></td><td>INTEGER</td><td><?= __('sql_explorer.help_col_inlinks') ?></td></tr>
                    <tr><td><code>outlinks</code></td><td>INTEGER</td><td><?= __('sql_explorer.help_col_outlinks') ?></td></tr>
                    <tr><td><code>pri</code></td><td>FLOAT</td><td><?= __('sql_explorer.help_col_pri') ?></td></tr>
                    <tr><td><code>title</code></td><td>TEXT</td><td><?= __('sql_explorer.help_col_title') ?></td></tr>
                    <tr><td><code>title_status</code></td><td>VARCHAR(50)</td><td>unique / empty / duplicate</td></tr>
                    <tr><td><code>h1</code></td><td>TEXT</td><td><?= __('sql_explorer.help_col_h1') ?></td></tr>
                    <tr><td><code>h1_status</code></td><td>VARCHAR(50)</td><td>unique / empty / duplicate</td></tr>
                    <tr><td><code>metadesc</code></td><td>TEXT</td><td>Meta description</td></tr>
                    <tr><td><code>metadesc_status</code></td><td>VARCHAR(50)</td><td>unique / empty / duplicate</td></tr>
                    <tr><td><code>h1_multiple</code></td><td>BOOLEAN</td><td><?= __('sql_explorer.help_col_h1_multiple') ?></td></tr>
                    <tr><td><code>headings_missing</code></td><td>BOOLEAN</td><td><?= __('sql_explorer.help_col_headings_missing') ?></td></tr>
                    <tr><td><code>simhash</code></td><td>BIGINT</td><td><?= __('sql_explorer.help_col_simhash') ?></td></tr>
                    <tr><td><code>is_html</code></td><td>BOOLEAN</td><td><?= __('sql_explorer.help_col_is_html') ?></td></tr>
                    <tr><td><code>cat_id</code></td><td>INTEGER</td><td>FK → categories.id</td></tr>
                    <tr><td><code>extracts</code></td><td>JSONB</td><td><?= __('sql_explorer.help_col_extracts') ?></td></tr>
                    <tr><td><code>schemas</code></td><td>TEXT[]</td><td><?= __('sql_explorer.help_col_schemas') ?></td></tr>
                    <tr><td><code>date</code></td><td>TIMESTAMP</td><td><?= __('sql_explorer.help_col_date') ?></td></tr>
                </tbody>
            </table>

            <h4><?= __('sql_explorer.help_table_categories') ?></h4>
            <p><?= __('sql_explorer.help_table_categories_desc') ?></p>

            <table class="schema-table">
                <thead>
                    <tr>
                        <th><?= __('sql_explorer.help_field') ?></th>
                        <th><?= __('sql_explorer.help_type') ?></th>
                        <th><?= __('sql_explorer.help_description') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td><code>id</code></td><td>INTEGER</td><td><?= __('sql_explorer.help_col_cat_id') ?></td></tr>
                    <tr><td><code>cat</code></td><td>VARCHAR(255)</td><td><?= __('sql_explorer.help_col_cat_name') ?></td></tr>
                    <tr><td><code>color</code></td><td>VARCHAR(7)</td><td><?= __('sql_explorer.help_col_cat_color') ?></td></tr>
                </tbody>
            </table>

            <h4><?= __('sql_explorer.help_table_links') ?></h4>
            <p><?= __('sql_explorer.help_table_links_desc') ?></p>

            <table class="schema-table">
                <thead>
                    <tr>
                        <th><?= __('sql_explorer.help_field') ?></th>
                        <th><?= __('sql_explorer.help_type') ?></th>
                        <th><?= __('sql_explorer.help_description') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td><code>src</code></td><td>CHAR(8)</td><td><?= __('sql_explorer.help_col_link_src') ?></td></tr>
                    <tr><td><code>target</code></td><td>CHAR(8)</td><td><?= __('sql_explorer.help_col_link_target') ?></td></tr>
                    <tr><td><code>anchor</code></td><td>TEXT</td><td><?= __('sql_explorer.help_col_link_anchor') ?></td></tr>
                    <tr><td><code>type</code></td><td>VARCHAR(50)</td><td><?= __('sql_explorer.help_col_link_type') ?></td></tr>
                    <tr><td><code>external</code></td><td>BOOLEAN</td><td><?= __('sql_explorer.help_col_link_external') ?></td></tr>
                    <tr><td><code>nofollow</code></td><td>BOOLEAN</td><td><?= __('sql_explorer.help_col_link_nofollow') ?></td></tr>
                </tbody>
            </table>

            <h4><?= __('sql_explorer.help_table_duplicates') ?></h4>
            <p><?= __('sql_explorer.help_table_duplicates_desc') ?></p>

            <table class="schema-table">
                <thead>
                    <tr>
                        <th><?= __('sql_explorer.help_field') ?></th>
                        <th><?= __('sql_explorer.help_type') ?></th>
                        <th><?= __('sql_explorer.help_description') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td><code>id</code></td><td>SERIAL</td><td><?= __('sql_explorer.help_col_dup_id') ?></td></tr>
                    <tr><td><code>similarity</code></td><td>INTEGER</td><td><?= __('sql_explorer.help_col_dup_similarity') ?></td></tr>
                    <tr><td><code>page_count</code></td><td>INTEGER</td><td><?= __('sql_explorer.help_col_dup_page_count') ?></td></tr>
                    <tr><td><code>page_ids</code></td><td>TEXT[]</td><td><?= __('sql_explorer.help_col_dup_page_ids') ?></td></tr>
                </tbody>
            </table>

            <h4><?= __('sql_explorer.help_table_schemas') ?></h4>
            <p><?= __('sql_explorer.help_table_schemas_desc') ?></p>

            <table class="schema-table">
                <thead>
                    <tr>
                        <th><?= __('sql_explorer.help_field') ?></th>
                        <th><?= __('sql_explorer.help_type') ?></th>
                        <th><?= __('sql_explorer.help_description') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td><code>page_id</code></td><td>CHAR(8)</td><td><?= __('sql_explorer.help_col_schema_page_id') ?></td></tr>
                    <tr><td><code>schema_type</code></td><td>VARCHAR(100)</td><td><?= __('sql_explorer.help_col_schema_type') ?></td></tr>
                </tbody>
            </table>

            <h3><?= __('sql_explorer.help_relations') ?></h3>
            <p><?= __('sql_explorer.help_relations_text') ?></p>
            <pre><code>-- Liens internes
links.src → pages.id       (page source du lien)
links.target → pages.id    (page cible du lien)

-- Catégories
pages.cat_id → categories.id (catégorie de la page)

-- Clusters de duplication
duplicate_clusters.page_ids contient des IDs de pages

-- Données structurées
page_schemas.page_id → pages.id (schemas d'une page)

-- Chaînes de redirection
redirect_chains.source_id → pages.id (page source)
redirect_chains.final_id → pages.id (page finale)

-- Jointures utiles
LEFT JOIN categories c ON pages.cat_id = c.id
-- Pour les clusters: WHERE pages.id = ANY(duplicate_clusters.page_ids)
-- Pour les schemas: LEFT JOIN page_schemas ps ON ps.page_id = p.id
-- Pour les redirections: LEFT JOIN pages p ON p.id = redirect_chains.source_id</code></pre>
            <p>
                <strong><?= __('sql_explorer.help_notes') ?></strong>
            </p>
            <ul>
                <li><?= __('sql_explorer.help_note_category_join') ?></li>
                <li><?= __('sql_explorer.help_note_jsonb') ?></li>
                <li><?= __('sql_explorer.help_note_arrays') ?></li>
                <li><?= __('sql_explorer.help_note_page_schemas') ?></li>
            </ul>

            <h3><?= __('sql_explorer.help_examples') ?></h3>

            <h4><?= __('sql_explorer.help_ex_response_codes') ?></h4>
            <pre><code>SELECT 
    code,
    COUNT(*) AS nb_urls
FROM pages 
WHERE crawled = true 
GROUP BY code 
ORDER BY nb_urls DESC;</code></pre>

            <h4><?= __('sql_explorer.help_ex_popular_pages') ?></h4>
            <pre><code>SELECT 
    url,
    inlinks,
    title,
    code
FROM pages 
WHERE crawled = true AND compliant = true 
ORDER BY inlinks DESC 
LIMIT 20;</code></pre>

            <h4><?= __('sql_explorer.help_ex_category_analysis') ?></h4>
            <pre><code>-- Répartition des URLs par catégorie
SELECT 
    COALESCE(c.cat, 'Non catégorisé') AS categorie,
    COUNT(*) AS nb_urls,
    AVG(p.inlinks) AS inlinks_moyen,
    AVG(p.response_time) AS temps_reponse_moyen
FROM pages p
LEFT JOIN categories c ON c.id = p.cat_id
WHERE p.crawled = true AND p.compliant = true
GROUP BY c.cat
ORDER BY nb_urls DESC;

-- Avec la couleur de catégorie
SELECT 
    COALESCE(c.cat, 'Non catégorisé') AS categorie,
    c.color AS couleur,
    COUNT(*) AS nb_urls
FROM pages p
LEFT JOIN categories c ON c.id = p.cat_id
WHERE p.crawled = true
GROUP BY c.cat, c.color
ORDER BY nb_urls DESC;</code></pre>

            <h4><?= __('sql_explorer.help_ex_seo_issues') ?></h4>
            <pre><code>-- URLs sans titre
SELECT url, code FROM pages 
WHERE crawled = true AND (title IS NULL OR title = '') 
LIMIT 50;

-- URLs avec titre dupliqué
SELECT title, COUNT(*) AS nb_pages
FROM pages 
WHERE crawled = true AND compliant = true AND title IS NOT NULL
GROUP BY title 
HAVING COUNT(*) > 1
ORDER BY nb_pages DESC;</code></pre>

            <h4><?= __('sql_explorer.help_ex_internal_links') ?></h4>
            <pre><code>-- Top 20 des ancres les plus utilisées
SELECT 
    anchor,
    COUNT(*) AS nb_liens,
    COUNT(DISTINCT src) AS nb_pages_source
FROM links
WHERE anchor IS NOT NULL AND anchor != ''
GROUP BY anchor
ORDER BY nb_liens DESC
LIMIT 20;

-- Pages avec le plus de liens sortants
SELECT 
    p.url,
    p.title,
    COUNT(l.id) AS nb_liens_sortants
FROM pages p
LEFT JOIN links l ON p.id = l.src
WHERE p.crawled = true
GROUP BY p.id, p.url, p.title
ORDER BY nb_liens_sortants DESC
LIMIT 20;</code></pre>

            <h4><?= __('sql_explorer.help_ex_jsonb') ?></h4>
            <p><?= __('sql_explorer.help_ex_jsonb_text') ?></p>
            <pre><code>-- Exemple 1 : Extraire un champ spécifique (ex: 'price')
SELECT 
    url,
    title,
    extracts->>'price' AS prix,
    extracts->>'stock' AS stock
FROM pages
WHERE extracts->>'price' IS NOT NULL
ORDER BY (extracts->>'price')::NUMERIC DESC
LIMIT 50;

-- Exemple 2 : Lister toutes les clés d'extraction disponibles
SELECT DISTINCT 
    jsonb_object_keys(extracts) AS extraction_name
FROM pages
WHERE extracts IS NOT NULL AND extracts != '{}'::jsonb;

-- Exemple 3 : Filtrer sur une extraction
SELECT 
    url,
    extracts->>'author' AS auteur,
    extracts->>'date' AS date_publication
FROM pages
WHERE extracts->>'author' LIKE '%John%'
LIMIT 50;

-- Exemple 4 : Compter les pages avec extraction
SELECT 
    COUNT(*) AS total_pages,
    COUNT(CASE WHEN extracts->>'price' IS NOT NULL THEN 1 END) AS pages_avec_prix
FROM pages
WHERE crawled = true;</code></pre>

            <h4><?= __('sql_explorer.help_ex_structured_data') ?></h4>
            <p><?= __('sql_explorer.help_ex_structured_data_text') ?></p>
            <pre><code>-- Exemple 1 : Distribution des types de schemas
SELECT 
    schema_type,
    COUNT(*) AS nb_pages
FROM page_schemas
GROUP BY schema_type
ORDER BY nb_pages DESC;

-- Exemple 2 : Pages avec un type spécifique (via colonne schemas)
SELECT 
    url,
    title,
    schemas
FROM pages
WHERE 'Article' = ANY(schemas)
LIMIT 50;

-- Exemple 3 : Pages sans données structurées
SELECT 
    url,
    inlinks
FROM pages
WHERE crawled = true 
    AND compliant = true 
    AND (schemas IS NULL OR array_length(schemas, 1) IS NULL)
ORDER BY inlinks DESC
LIMIT 50;

-- Exemple 4 : Nombre de types par page
SELECT 
    url,
    array_length(schemas, 1) AS nb_schemas,
    schemas
FROM pages
WHERE schemas IS NOT NULL AND array_length(schemas, 1) > 0
ORDER BY nb_schemas DESC
LIMIT 20;</code></pre>

            <h3><?= __('sql_explorer.help_editor_features') ?></h3>
            <ul>
                <li><strong><?= __('sql_explorer.help_feat_autocomplete') ?></strong> : <?= __('sql_explorer.help_feat_autocomplete_desc') ?></li>
                <li><strong><?= __('sql_explorer.help_feat_syntax') ?></strong> : <?= __('sql_explorer.help_feat_syntax_desc') ?></li>
                <li><strong><?= __('sql_explorer.help_feat_shortcuts') ?></strong> :
                    <ul>
                        <li><code>Ctrl+Enter</code> : <?= __('sql_explorer.help_feat_shortcut_execute') ?></li>
                        <li><code>Ctrl+Space</code> : <?= __('sql_explorer.help_feat_shortcut_autocomplete') ?></li>
                        <li><code>Tab</code> : <?= __('sql_explorer.help_feat_shortcut_indent') ?></li>
                    </ul>
                </li>
                <li><strong><?= __('sql_explorer.help_feat_tabs') ?></strong> : <?= __('sql_explorer.help_feat_tabs_desc') ?></li>
                <li><strong><?= __('sql_explorer.help_feat_saved') ?></strong> : <?= __('sql_explorer.help_feat_saved_desc') ?></li>
                <li><strong><?= __('sql_explorer.help_feat_export') ?></strong> : <?= __('sql_explorer.help_feat_export_desc') ?></li>
            </ul>

            <h3><?= __('sql_explorer.help_tips') ?></h3>
            <ul>
                <li><?= __('sql_explorer.help_tip_limit') ?></li>
                <li><?= __('sql_explorer.help_tip_crawled') ?></li>
                <li><?= __('sql_explorer.help_tip_compliant') ?></li>
                <li><?= __('sql_explorer.help_tip_left_join') ?></li>
                <li><?= __('sql_explorer.help_tip_pg_functions') ?></li>
                <li><?= __('sql_explorer.help_tip_jsonb') ?></li>
                <li><?= __('sql_explorer.help_tip_cast') ?></li>
            </ul>

            <h3><?= __('sql_explorer.help_limitations') ?></h3>
            <ul>
                <li><strong><?= __('sql_explorer.help_limit_readonly') ?></strong> : <?= __('sql_explorer.help_limit_readonly_desc') ?></li>
                <li><strong><?= __('sql_explorer.help_limit_no_modify') ?></strong> : <?= __('sql_explorer.help_limit_no_modify_desc') ?></li>
                <li><strong><?= __('sql_explorer.help_limit_display') ?></strong> : <?= __('sql_explorer.help_limit_display_desc') ?></li>
                <li><strong><?= __('sql_explorer.help_limit_timeout') ?></strong> : <?= __('sql_explorer.help_limit_timeout_desc') ?></li>
                <li><strong><?= __('sql_explorer.help_limit_protection') ?></strong> : <?= __('sql_explorer.help_limit_protection_desc') ?></li>
            </ul>
        </div>
    </div>
</div>


<script>
// Préparer les données d'autocomplétion
const sqlHintData = {
    tables: <?= json_encode(array_keys($tables)) ?>,
    defaultTable: <?= json_encode($tables) ?>
};

// Requêtes sauvegardées
const savedQueries = <?= json_encode($savedQueries) ?>;

// Système d'onglets
let tabs = [
    { id: 0, title: '<?= __('sql_explorer.query_tab') ?> 1', query: <?= json_encode($initialQuery) ?>, editor: null }
];
let activeTabId = 0;
let nextTabId = 1;

// Créer un objet avec toutes les colonnes pour l'autocomplétion
const allColumns = {};
<?php foreach ($tables as $tableName => $columns): ?>
allColumns['<?= $tableName ?>'] = [
    <?php foreach ($columns as $column): ?>
    '<?= $column['name'] ?>',
    <?php endforeach; ?>
];
<?php endforeach; ?>

// Fonction d'autocomplétion personnalisée
function customSQLHint(editor, options) {
    const cursor = editor.getCursor();
    const token = editor.getTokenAt(cursor);
    const line = editor.getLine(cursor.line);
    const lineUpToCursor = line.slice(0, cursor.ch);
    
    // Détecter le contexte (après FROM, JOIN, etc.)
    const fromMatch = lineUpToCursor.match(/(?:FROM|JOIN)\s+(\w+)(?:\s+(?:AS\s+)?(\w+))?\s*$/i);
    const selectMatch = lineUpToCursor.match(/SELECT\s+(?:.*,\s*)?(\w*)$/i);
    const whereMatch = lineUpToCursor.match(/WHERE\s+(?:.*\s+(?:AND|OR)\s+)?(\w*)$/i);
    
    let suggestions = [];
    
    // Si on est après FROM ou JOIN, proposer les tables
    if (fromMatch || token.string.match(/^\w*$/) && lineUpToCursor.match(/(?:FROM|JOIN)\s*\w*$/i)) {
        suggestions = Object.keys(allColumns).map(table => ({
            text: table,
            displayText: table + ' (table)',
            className: 'hint-table'
        }));
    }
    // Si on est dans SELECT ou WHERE, proposer les colonnes
    else if (selectMatch || whereMatch || token.string.match(/^\w+$/)) {
        // Trouver toutes les tables mentionnées dans la requête
        const fullQuery = editor.getValue();
        const tableMatches = fullQuery.match(/(?:FROM|JOIN)\s+(\w+)(?:\s+(?:AS\s+)?(\w+))?/gi);
        
        if (tableMatches) {
            tableMatches.forEach(match => {
                const parts = match.match(/(?:FROM|JOIN)\s+(\w+)(?:\s+(?:AS\s+)?(\w+))?/i);
                const tableName = parts[1];
                const alias = parts[2];
                
                if (allColumns[tableName]) {
                    allColumns[tableName].forEach(column => {
                        suggestions.push({
                            text: column,
                            displayText: column + ' (' + tableName + ')',
                            className: 'hint-column'
                        });
                        
                        // Ajouter aussi avec l'alias si présent
                        if (alias) {
                            suggestions.push({
                                text: alias + '.' + column,
                                displayText: alias + '.' + column + ' (' + tableName + ')',
                                className: 'hint-column-alias'
                            });
                        }
                        
                        // Ajouter avec le nom de table complet
                        suggestions.push({
                            text: tableName + '.' + column,
                            displayText: tableName + '.' + column,
                            className: 'hint-column-full'
                        });
                    });
                }
            });
        }
        
        // Ajouter aussi les tables si aucune colonne trouvée
        if (suggestions.length === 0) {
            suggestions = Object.keys(allColumns).map(table => ({
                text: table,
                displayText: table + ' (table)',
                className: 'hint-table'
            }));
        }
    }
    
    // Filtrer selon ce qui est déjà tapé
    const typed = token.string.toLowerCase();
    if (typed) {
        suggestions = suggestions.filter(s => 
            s.text.toLowerCase().includes(typed) || 
            s.displayText.toLowerCase().includes(typed)
        );
    }
    
    // Supprimer les doublons
    const seen = new Set();
    suggestions = suggestions.filter(s => {
        if (seen.has(s.text)) return false;
        seen.add(s.text);
        return true;
    });
    
    return {
        list: suggestions,
        from: {line: cursor.line, ch: token.start},
        to: {line: cursor.line, ch: token.end}
    };
}

// Initialiser CodeMirror
let sqlEditor;
if (typeof CodeMirror !== 'undefined') {
    sqlEditor = CodeMirror.fromTextArea(document.getElementById('sqlEditor'), {
        mode: 'text/x-sql',
        theme: 'default',
        lineNumbers: true,
        lineWrapping: true,
        autofocus: true,
        indentWithTabs: false,
        indentUnit: 2,
        matchBrackets: true,
        autoCloseBrackets: true,
        highlightSelectionMatches: {showToken: /\w/, annotateScrollbar: true},
        hintOptions: {
            hint: customSQLHint,
            completeSingle: false
        },
        extraKeys: {
            "Ctrl-Enter": executeQuery,
            "Cmd-Enter": executeQuery,
            "Ctrl-Space": function(cm) {
                CodeMirror.showHint(cm, customSQLHint, {completeSingle: false});
            },
            "Tab": function(cm) {
                if (cm.somethingSelected()) {
                    cm.indentSelection("add");
                } else {
                    cm.replaceSelection("  ");
                }
            }
        }
    });
    
    // Sauvegarder l'éditeur dans le premier onglet
    tabs[0].editor = sqlEditor;
    
    // Autocomplétion automatique pendant la frappe
    sqlEditor.on("inputRead", function(cm, change) {
        if (!cm.state.completionActive && 
            change.text[0].match(/[a-zA-Z.]/)) {
            setTimeout(() => {
                if (!cm.state.completionActive) {
                    CodeMirror.showHint(cm, customSQLHint, {completeSingle: false});
                }
            }, 100);
        }
    });
    
    // Sauvegarder le contenu quand on change d'onglet
    sqlEditor.on("change", function(cm) {
        const activeTab = tabs.find(tab => tab.id === activeTabId);
        if (activeTab) {
            activeTab.query = cm.getValue();
        }
    });
    
} else {
    // Fallback si CodeMirror n'est pas chargé
    console.warn('CodeMirror non disponible, utilisation du textarea basique');
}

// Gestion du clic molette sur les tabs
function handleTabMouseDown(e) {
    // Empêcher le scroll automatique du navigateur sur clic molette
    if (e.button === 1) {
        e.preventDefault();
    }
}

function handleTabMiddleClick(tabId, e) {
    if (e.button === 1) { // Bouton du milieu
        e.preventDefault();
        e.stopPropagation();
        closeTab(tabId, e);
    }
}

// Toggle sidebar
function toggleSidebar() {
    const layout = document.getElementById('sqlLayout');
    layout.classList.toggle('sidebar-collapsed');
    
    // Redimensionner CodeMirror après l'animation
    setTimeout(() => {
        if (sqlEditor) {
            sqlEditor.refresh();
        }
    }, 350);
}

// Toggle table columns
function toggleTable(tableName) {
    const columnsDiv = document.getElementById('columns-' + tableName);
    const header = columnsDiv.previousElementSibling;
    
    if (columnsDiv.classList.contains('expanded')) {
        columnsDiv.classList.remove('expanded');
        header.classList.remove('active');
    } else {
        // Fermer tous les autres
        document.querySelectorAll('.table-columns').forEach(col => {
            col.classList.remove('expanded');
        });
        document.querySelectorAll('.table-header').forEach(h => {
            h.classList.remove('active');
        });
        
        // Ouvrir celui-ci
        columnsDiv.classList.add('expanded');
        header.classList.add('active');
    }
}

// Fonctions de gestion des onglets
function switchTab(tabId) {
    // Sauvegarder le contenu de l'onglet actuel
    const currentTab = tabs.find(tab => tab.id === activeTabId);
    if (currentTab && sqlEditor) {
        currentTab.query = sqlEditor.getValue();
    }
    
    // Changer l'onglet actif
    activeTabId = tabId;
    const newTab = tabs.find(tab => tab.id === tabId);
    
    if (newTab && sqlEditor) {
        sqlEditor.setValue(newTab.query);
        sqlEditor.focus();
    }
    
    // Mettre à jour l'interface
    updateTabsUI();
}

function addNewTab() {
    const newTab = {
        id: nextTabId,
        title: `<?= __('sql_explorer.query_tab') ?> ${nextTabId + 1}`,
        query: '',
        editor: null
    };
    
    tabs.push(newTab);
    activeTabId = nextTabId;
    nextTabId++;
    
    // Mettre à jour l'interface et charger le contenu
    updateTabsUI();
    if (sqlEditor) {
        sqlEditor.setValue('');
        sqlEditor.focus();
    }
}

function closeTab(tabId, event) {
    event.stopPropagation();
    
    // Ne pas fermer s'il n'y a qu'un onglet
    if (tabs.length <= 1) return;
    
    const tabIndex = tabs.findIndex(tab => tab.id === tabId);
    if (tabIndex === -1) return;
    
    // Si on ferme l'onglet actif, basculer vers un autre
    if (tabId === activeTabId) {
        const newActiveIndex = tabIndex > 0 ? tabIndex - 1 : 0;
        const newActiveTab = tabs[newActiveIndex === tabIndex ? 1 : newActiveIndex];
        activeTabId = newActiveTab.id;
        
        if (sqlEditor) {
            sqlEditor.setValue(newActiveTab.query);
        }
    }
    
    // Supprimer l'onglet
    tabs.splice(tabIndex, 1);
    updateTabsUI();
}

function updateTabsUI() {
    const container = document.getElementById('tabsContainer');
    const addButton = container.querySelector('.tab-add');
    
    // Supprimer tous les onglets existants
    container.querySelectorAll('.tab:not(.tab-add)').forEach(tab => tab.remove());
    
    // Recréer les onglets
    tabs.forEach(tab => {
        const tabElement = document.createElement('div');
        tabElement.className = `tab ${tab.id === activeTabId ? 'active' : ''}`;
        tabElement.setAttribute('data-tab-id', tab.id);
        tabElement.onclick = () => switchTab(tab.id);
        tabElement.ondblclick = (e) => {
            // Ne pas déclencher le renommage si on double-clique sur le bouton de fermeture
            if (!e.target.classList.contains('tab-close')) {
                startRenameTab(tab.id, e);
            }
        };
        // Empêcher le scroll auto au clic molette
        tabElement.onmousedown = handleTabMouseDown;
        // Clic du milieu pour fermer le tab
        tabElement.onauxclick = (e) => handleTabMiddleClick(tab.id, e);
        
        const titleElement = document.createElement('span');
        titleElement.className = 'tab-title';
        titleElement.textContent = tab.title;
        
        const closeElement = document.createElement('span');
        closeElement.className = 'tab-close';
        closeElement.textContent = '×';
        closeElement.onclick = (e) => closeTab(tab.id, e);
        
        tabElement.appendChild(titleElement);
        tabElement.appendChild(closeElement);
        
        container.insertBefore(tabElement, addButton);
    });
}

function startRenameTab(tabId, event) {
    event.stopPropagation();
    
    const tab = tabs.find(t => t.id === tabId);
    if (!tab) return;
    
    const tabElement = document.querySelector(`[data-tab-id="${tabId}"]`);
    const titleElement = tabElement.querySelector('.tab-title');
    
    // Créer l'input de renommage
    const input = document.createElement('input');
    input.className = 'tab-title-input';
    input.value = tab.title;
    input.type = 'text';
    
    // Remplacer le titre par l'input
    titleElement.style.display = 'none';
    tabElement.insertBefore(input, titleElement);
    
    // Focus et sélection du texte
    input.focus();
    input.select();
    
    // Fonction pour valider le renommage
    function finishRename() {
        const newTitle = input.value.trim() || tab.title;
        tab.title = newTitle;
        
        // Restaurer l'affichage normal
        titleElement.textContent = newTitle;
        titleElement.style.display = '';
        input.remove();
    }
    
    // Événements pour valider ou annuler
    input.onblur = finishRename;
    input.onkeydown = function(e) {
        if (e.key === 'Enter') {
            finishRename();
        } else if (e.key === 'Escape') {
            // Annuler sans sauvegarder
            titleElement.style.display = '';
            input.remove();
        }
        e.stopPropagation();
    };
    
    // Empêcher le clic sur l'onglet pendant l'édition
    input.onclick = (e) => e.stopPropagation();
}

// Toggle un accordéon de saved queries (prédéfini ou user)
function toggleSqAccordion(headerEl) {
    const acc = headerEl.closest('.sq-accordion');
    if (acc) acc.classList.toggle('open');
}

// ============================================================================
// USER SAVED QUERIES — gestion complète (load list, render, CRUD via API)
// ============================================================================
let userQueries = [];    // populé via API
let sqEditingId = null;  // id de la query en édition (null = mode create)

async function loadUserQueries() {
    try {
        const resp = await fetch('api/saved-queries', { credentials: 'same-origin' });
        if (!resp.ok) return;
        const data = await resp.json();
        userQueries = (data && data.queries) ? data.queries : [];
        renderUserQueries();
    } catch (e) { /* silencieux */ }
}

function renderUserQueries() {
    const container = document.getElementById('userQueriesContainer');
    if (!container) return;
    if (userQueries.length === 0) {
        container.innerHTML = '<div class="sq-empty-state">' + __('sql_explorer.no_user_queries') + '</div>';
        return;
    }
    // Grouper par catégorie (NULL/'' = "Sans catégorie")
    const noCat = __('sql_explorer.no_category');
    const byCat = {};
    userQueries.forEach(q => {
        const c = q.category && q.category.trim() !== '' ? q.category : noCat;
        if (!byCat[c]) byCat[c] = [];
        byCat[c].push(q);
    });
    const cats = Object.keys(byCat).sort((a, b) => a.localeCompare(b));
    let html = '';
    for (const cat of cats) {
        const items = byCat[cat];
        const isReal = (cat !== noCat); // pas d'actions rename/delete sur le fallback "Sans catégorie"
        html += '<div class="sq-accordion">';
        html +=   '<div class="sq-accordion-header" onclick="toggleSqAccordion(this)">';
        html +=     '<span class="material-symbols-outlined sq-chevron">chevron_right</span>';
        html +=     '<span class="sq-accordion-title">' + escapeHtml(cat) + '</span>';
        html +=     '<span class="sq-accordion-count">' + items.length + '</span>';
        if (isReal) {
            html += '<div class="sq-cat-actions">';
            html +=   '<button type="button" class="sq-cat-action" title="' + __('sql_explorer.rename_category') + '" onclick="event.stopPropagation(); renameUserCategory(\'' + escapeJs(cat) + '\')">';
            html +=     '<span class="material-symbols-outlined">edit</span>';
            html +=   '</button>';
            html +=   '<button type="button" class="sq-cat-action danger" title="' + __('sql_explorer.delete_category') + '" onclick="event.stopPropagation(); deleteUserCategory(\'' + escapeJs(cat) + '\', ' + items.length + ')">';
            html +=     '<span class="material-symbols-outlined">delete</span>';
            html +=   '</button>';
            html += '</div>';
        }
        html +=   '</div>';
        html +=   '<div class="sq-accordion-body">';
        for (const q of items) {
            const desc = q.description || '';
            html += '<div class="query-item" onclick="loadUserQuery(' + q.id + ')" title="' + escapeHtml(desc) + '">';
            html +=   '<span class="material-symbols-outlined query-icon">code</span>';
            html +=   '<div class="query-item-content">';
            html +=     '<div class="query-name">' + escapeHtml(q.name) + '</div>';
            html +=     (desc ? '<div class="query-description">' + escapeHtml(desc) + '</div>' : '');
            html +=   '</div>';
            html +=   '<div class="query-item-actions">';
            html +=     '<button type="button" class="query-item-action" title="' + __('sql_explorer.edit') + '" onclick="event.stopPropagation(); openEditQueryModal(' + q.id + ')">';
            html +=       '<span class="material-symbols-outlined">edit</span>';
            html +=     '</button>';
            html +=     '<button type="button" class="query-item-action danger" title="' + __('sql_explorer.delete') + '" onclick="event.stopPropagation(); deleteUserQuery(' + q.id + ')">';
            html +=       '<span class="material-symbols-outlined">delete</span>';
            html +=     '</button>';
            html +=   '</div>';
            html += '</div>';
        }
        html +=   '</div>';
        html += '</div>';
    }
    container.innerHTML = html;
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

// Pour embedder une string user dans un onclick="..." inline JS sans casser
function escapeJs(s) {
    return String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

async function renameUserCategory(oldName) {
    const newName = prompt(__('sql_explorer.prompt_rename_category'), oldName);
    if (newName === null) return;          // annulé
    const trimmed = newName.trim();
    if (trimmed === '' || trimmed === oldName) return;
    try {
        const resp = await fetch('api/saved-queries/category/rename', {
            method: 'PUT',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ old_name: oldName, new_name: trimmed }),
        });
        if (resp.ok) loadUserQueries();
    } catch (e) { /* silencieux */ }
}

async function deleteUserCategory(name, count) {
    const msg = __('sql_explorer.confirm_delete_category')
        .replace(':name', name)
        .replace(':count', count);
    if (!confirm(msg)) return;
    try {
        const resp = await fetch('api/saved-queries/category', {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name }),
        });
        if (resp.ok) loadUserQueries();
    } catch (e) { /* silencieux */ }
}

function loadUserQuery(id) {
    const q = userQueries.find(x => x.id === id);
    if (!q) return;
    // Réutilise la logique de loadSavedQuery — on crée un nouvel onglet
    const newTab = {
        id: nextTabId,
        title: q.name.substring(0, 20) + (q.name.length > 20 ? '...' : ''),
        query: q.query,
        editor: null,
    };
    tabs.push(newTab);
    activeTabId = nextTabId;
    nextTabId++;
    updateTabsUI();
    if (sqlEditor) {
        sqlEditor.setValue(q.query);
        sqlEditor.focus();
    }
}

// ----------- MODALE SAVE / EDIT -----------
function openSaveQueryModal() {
    sqEditingId = null;
    document.getElementById('sqModalTitle').textContent = __('sql_explorer.save_query_title');
    document.getElementById('sqName').value = '';
    document.getElementById('sqDesc').value = '';
    populateCategorySelect('');
    document.getElementById('sqNewCat').style.display = 'none';
    document.getElementById('sqNewCat').value = '';
    document.getElementById('sqModalError').classList.remove('active');
    document.getElementById('sqSaveModal').classList.add('active');
    setTimeout(() => document.getElementById('sqName').focus(), 50);
}

function openEditQueryModal(id) {
    const q = userQueries.find(x => x.id === id);
    if (!q) return;
    sqEditingId = id;
    document.getElementById('sqModalTitle').textContent = __('sql_explorer.edit_query_title');
    document.getElementById('sqName').value = q.name;
    document.getElementById('sqDesc').value = q.description || '';
    populateCategorySelect(q.category || '');
    document.getElementById('sqNewCat').style.display = 'none';
    document.getElementById('sqNewCat').value = '';
    document.getElementById('sqModalError').classList.remove('active');
    document.getElementById('sqSaveModal').classList.add('active');
    setTimeout(() => document.getElementById('sqName').focus(), 50);
}

function closeSaveQueryModal() {
    document.getElementById('sqSaveModal').classList.remove('active');
}

function populateCategorySelect(selected) {
    const select = document.getElementById('sqCat');
    const existing = Array.from(new Set(userQueries.map(q => q.category).filter(c => c && c.trim() !== '')));
    existing.sort((a, b) => a.localeCompare(b));
    let html = '<option value="">— ' + __('sql_explorer.no_category') + ' —</option>';
    for (const c of existing) {
        html += '<option value="' + escapeHtml(c) + '"' + (c === selected ? ' selected' : '') + '>' + escapeHtml(c) + '</option>';
    }
    html += '<option value="__new__">' + __('sql_explorer.create_new_category') + '</option>';
    select.innerHTML = html;
}

function onSqCategoryChange() {
    const sel = document.getElementById('sqCat');
    const input = document.getElementById('sqNewCat');
    if (sel.value === '__new__') {
        input.style.display = '';
        setTimeout(() => input.focus(), 30);
    } else {
        input.style.display = 'none';
    }
}

async function submitSaveQuery() {
    const name = document.getElementById('sqName').value.trim();
    const desc = document.getElementById('sqDesc').value.trim();
    const catSel = document.getElementById('sqCat').value;
    const newCat = document.getElementById('sqNewCat').value.trim();
    const category = catSel === '__new__' ? newCat : catSel;
    const query = sqlEditor ? sqlEditor.getValue() : '';
    const err = document.getElementById('sqModalError');

    if (!name) {
        err.textContent = __('sql_explorer.error_name_required');
        err.classList.add('active');
        return;
    }
    if (!query.trim()) {
        err.textContent = __('sql_explorer.error_query_required');
        err.classList.add('active');
        return;
    }

    const body = { name, description: desc, category, query };
    const isEdit = sqEditingId !== null;
    const url = isEdit ? ('api/saved-queries/' + sqEditingId) : 'api/saved-queries';
    const method = isEdit ? 'PUT' : 'POST';

    try {
        const resp = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        const data = await resp.json();
        if (!resp.ok || !data.success) {
            err.textContent = (data && data.error) ? data.error : __('common.error');
            err.classList.add('active');
            return;
        }
        closeSaveQueryModal();
        loadUserQueries();
    } catch (e) {
        err.textContent = __('common.error');
        err.classList.add('active');
    }
}

async function deleteUserQuery(id) {
    const q = userQueries.find(x => x.id === id);
    if (!q) return;
    if (!confirm(__('sql_explorer.confirm_delete').replace(':name', q.name))) return;
    try {
        const resp = await fetch('api/saved-queries/' + id, {
            method: 'DELETE',
            credentials: 'same-origin',
        });
        if (resp.ok) loadUserQueries();
    } catch (e) { /* silencieux */ }
}

// Charge la liste user au démarrage
document.addEventListener('DOMContentLoaded', loadUserQueries);

// Charger une requête sauvegardée
function loadSavedQuery(index) {
    console.log('Loading query index:', index, savedQueries[index]);
    const query = savedQueries[index];
    if (query) {
        // Créer un nouvel onglet avec la requête
        const newTab = {
            id: nextTabId,
            title: query.name.substring(0, 20) + (query.name.length > 20 ? '...' : ''),
            query: query.query,
            editor: null
        };
        
        tabs.push(newTab);
        activeTabId = nextTabId;
        nextTabId++;
        
        // Mettre à jour l'interface et charger le contenu
        updateTabsUI();
        if (sqlEditor) {
            sqlEditor.setValue(query.query);
            sqlEditor.focus();
        }
        
        // Petit effet visuel
        const queryItems = document.querySelectorAll('.saved-queries-section .query-item');
        if (queryItems[index]) {
            queryItems[index].style.background = 'var(--primary-color)';
            queryItems[index].style.color = 'white';
            setTimeout(() => {
                queryItems[index].style.background = '';
                queryItems[index].style.color = '';
            }, 200);
        }
    }
}

/**
 * Convertit les doubles quotes en simples quotes pour PostgreSQL
 * - Remplace uniquement les guillemets d'encadrement
 * - Échappe les simples quotes internes (les double pour PG)
 * - Déséchape les doubles quotes internes (\" → ")
 */
function convertDoubleQuotesToSingleQuotes(sql) {
    // Regex pour capturer les chaînes entre doubles quotes
    // Gère les séquences échappées comme \"
    return sql.replace(/"((?:[^"\\]|\\.)*)"/g, function(match, content) {
        // Déséchapper les doubles quotes : \" → "
        let converted = content.replace(/\\"/g, '"');
        // Échapper les simples quotes pour PostgreSQL : ' → ''
        converted = converted.replace(/'/g, "''");
        // Retourner avec des simples quotes
        return "'" + converted + "'";
    });
}

// Exécuter la requête
function executeQuery() {
    let query = sqlEditor ? sqlEditor.getValue() : document.getElementById('sqlEditor').value;
    
    if (!query.trim()) {
        showError(__('sql_explorer.error_empty_query'));
        return;
    }
    
    // Pré-traiter la requête : convertir les doubles quotes en simples quotes
    query = convertDoubleQuotesToSingleQuotes(query);
    // Mémoriser la requête exécutée pour l'export CSV (rejouée côté serveur).
    window.lastExecutedSql = query;

    // Afficher le loader
    document.getElementById('resultsContent').innerHTML = `
        <div class="loading">
            <div class="spinner"></div>
            <span>${__('sql_explorer.executing_query')}</span>
        </div>
    `;
    document.getElementById('resultInfo').innerHTML = `
        <span class="material-symbols-outlined spinning">progress_activity</span>
        <span>${__('sql_explorer.executing')}</span>
    `;
    document.getElementById('truncationAlert').style.display = 'none';
    
    // Envoyer la requête
    fetch('../api/query/execute', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            query: query,
            project: '<?= htmlspecialchars($projectDir) ?>'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.error) {
            showError(data.error);
        } else {
            displayResults(data);
        }
    })
    .catch(error => {
        showError(__('sql_explorer.error_execution') + error.message);
    });
}

// Variable globale pour stocker les données actuelles
let currentResultData = null;

// Afficher les résultats
function displayResults(data) {
    const resultsContent = document.getElementById('resultsContent');
    const resultsContentChart = document.getElementById('resultsContentChart');
    const resultInfo = document.getElementById('resultInfo');
    const exportBtn = document.getElementById('exportBtn');
    const resultsClassic = document.getElementById('resultsClassic');
    const resultsWithChart = document.getElementById('resultsWithChart');
    
    if (data.type === 'select') {
        currentResultData = data; // Stocker TOUTES les données pour l'export
        
        if (data.rows.length === 0) {
            resultsClassic.style.display = 'flex';
            resultsWithChart.style.display = 'none';
            resultsContent.className = 'empty-state';
            resultsContent.innerHTML = `
                <span class="material-symbols-outlined">inbox</span>
                <p>${__('common.no_results')}</p>
            `;
            resultInfo.innerHTML = `
                <span class="material-symbols-outlined">table_chart</span>
                <span>${__('sql_explorer.zero_rows')}</span>
            `;
            exportBtn.disabled = true;
            document.getElementById('copyBtn').disabled = true;
            document.getElementById('truncationAlert').style.display = 'none';
            return;
        }
        
        // Vérifier si on doit afficher le graphique
        // Conditions : exactement 2 colonnes ET la 2ème colonne est numérique ET max 20 lignes
        const shouldShowChart = data.columns.length === 2 && 
                                data.rows.length > 0 && 
                                data.rows.length <= 20 &&
                                !isNaN(parseFloat(data.rows[0][data.columns[1]]));
        
        if (shouldShowChart) {
            // Mode avec graphique
            resultsClassic.style.display = 'none';
            resultsWithChart.style.display = 'flex';
        } else {
            // Mode classique (prend tout l'espace)
            resultsClassic.style.display = 'flex';
            resultsWithChart.style.display = 'none';
        }
        
        // Limiter l'affichage à 500 lignes maximum
        const maxDisplayRows = 500;
        const displayRows = data.rows.slice(0, maxDisplayRows);
        const isLimited = data.rows.length > maxDisplayRows;
        
        // Créer le tableau avec les lignes limitées
        const columns = data.columns;
        let html = '<table class="results-table"><thead><tr>';
        
        columns.forEach(col => {
            html += `<th>${escapeHtml(col)}</th>`;
        });
        
        html += '</tr></thead><tbody>';
        
        displayRows.forEach(row => {
            html += '<tr>';
            columns.forEach(col => {
                const value = row[col];
                html += `<td>${value !== null ? escapeHtml(String(value)) : '<em style="color: var(--text-secondary);">NULL</em>'}</td>`;
            });
            html += '</tr>';
        });
        
        html += '</tbody></table>';
        
        // Afficher dans le bon conteneur selon le mode
        if (shouldShowChart) {
            resultsContentChart.className = '';
            resultsContentChart.innerHTML = html;
            const wrapper = resultsContentChart.closest('.results-table-wrapper');
            if (wrapper) {
                wrapper.scrollTop = 0;
                wrapper.scrollLeft = 0;
            }
            // Générer le graphique
            generateChart('doughnut');
        } else {
            resultsContent.className = '';
            resultsContent.innerHTML = html;
            const wrapper = resultsContent.closest('.results-table-wrapper');
            if (wrapper) {
                wrapper.scrollTop = 0;
                wrapper.scrollLeft = 0;
            }
        }
        
        // Gérer le message d'alerte de troncature (en dehors du scroll)
        const alertContainer = document.getElementById('truncationAlert');
        if (isLimited) {
            alertContainer.innerHTML = `
                <span class="material-symbols-outlined">info</span>
                <strong>${__('sql_explorer.display_limited')}</strong> ${__('sql_explorer.display_limited_detail').replace(':displayed', maxDisplayRows).replace(':total', data.rows.length)}
            `;
            alertContainer.style.display = 'flex';
        } else {
            alertContainer.style.display = 'none';
        }
        
        // Mise à jour du result info avec la nouvelle structure
        resultInfo.innerHTML = `
            <span class="material-symbols-outlined">table_chart</span>
            <span>${data.rows.length} ${data.rows.length > 1 ? __('sql_explorer.rows') : __('sql_explorer.row')}${isLimited ? ` (${maxDisplayRows} ${__('sql_explorer.displayed')})` : ''}</span>
        `;
        exportBtn.disabled = false; // Activer le bouton d'export
        document.getElementById('copyBtn').disabled = false;
    } else {
        // Requête non-SELECT (UPDATE, DELETE, etc.)
        currentResultData = null;
        resultsContent.innerHTML = `
            <div class="success-message">
                <strong>${__('common.success')}</strong> ${data.affected_rows} ${data.affected_rows > 1 ? __('sql_explorer.rows_affected_plural') : __('sql_explorer.row_affected')}
            </div>
        `;
        resultInfo.innerHTML = `
            <span class="material-symbols-outlined">table_chart</span>
            <span>${__('common.results')}</span>
        `;
        exportBtn.disabled = true;
        document.getElementById('copyBtn').disabled = true;
    }
}

// Afficher une erreur
function showError(message) {
    const resultsClassic = document.getElementById('resultsClassic');
    const resultsWithChart = document.getElementById('resultsWithChart');
    resultsClassic.style.display = 'flex';
    resultsWithChart.style.display = 'none';
    
    document.getElementById('resultsContent').innerHTML = `
        <div class="error-message">
            <strong>${__('common.error')} :</strong> ${escapeHtml(message)}
        </div>
    `;
    document.getElementById('resultInfo').innerHTML = `
        <span class="material-symbols-outlined">error</span>
        <span style="color: var(--danger);">${__('common.error')}</span>
    `;
    document.getElementById('exportBtn').disabled = true;
    document.getElementById('copyBtn').disabled = true;
    document.getElementById('truncationAlert').style.display = 'none';
}

// Copier le tableau dans le presse-papier
function copyTableToClipboard() {
    if (!currentResultData || !currentResultData.rows.length) {
        return;
    }
    
    const copyBtn = document.getElementById('copyBtn');
    const originalContent = copyBtn.innerHTML;
    
    try {
        // Créer le contenu tab-separated (pour coller dans Excel/Sheets)
        const columns = currentResultData.columns;
        let content = columns.join('\t') + '\n';
        
        // Limiter au nombre de lignes affichées (500 max)
        const maxRows = Math.min(currentResultData.rows.length, 500);
        for (let i = 0; i < maxRows; i++) {
            const row = currentResultData.rows[i];
            const rowData = columns.map(col => {
                const value = row[col];
                if (value === null) return '';
                return String(value).replace(/\t/g, ' ').replace(/\n/g, ' ');
            });
            content += rowData.join('\t') + '\n';
        }
        
        // Copier dans le presse-papier
        navigator.clipboard.writeText(content).then(() => {
            // Feedback visuel
            copyBtn.innerHTML = '<span class="material-symbols-outlined">check</span> ' + __('common.copied');
            copyBtn.style.color = 'var(--success)';
            copyBtn.style.borderColor = 'var(--success)';
            
            setTimeout(() => {
                copyBtn.innerHTML = originalContent;
                copyBtn.style.color = '';
                copyBtn.style.borderColor = '';
            }, 1500);
        }).catch(err => {
            console.error('Erreur copie:', err);
            alert(__('sql_explorer.error_clipboard'));
        });
    } catch (error) {
        console.error('Erreur:', error);
    }
}

// Exporter vers CSV
function exportToCSV() {
    if (!currentResultData || !currentResultData.rows.length) {
        alert(__('sql_explorer.error_no_data'));
        return;
    }
    const sql = window.lastExecutedSql || '';
    if (!sql.trim()) {
        alert(__('sql_explorer.error_no_data'));
        return;
    }
    if (typeof window.queueExport !== 'function') return;

    // Export asynchrone : le CSV est régénéré côté serveur (résultat COMPLET, pas
    // seulement les lignes affichées) et envoyé sur le blob store. L'icône
    // « téléchargements » du header prévient quand c'est prêt.
    const exportBtn = document.getElementById('exportBtn');
    const originalContent = exportBtn.innerHTML;
    exportBtn.disabled = true;
    exportBtn.innerHTML = '<span class="material-symbols-outlined spinning">progress_activity</span> ' + __('sql_explorer.exporting');

    Promise.resolve(window.queueExport({
        type: 'sql',
        project: <?= json_encode((string)$projectDir) ?>,
        sql: sql
    })).finally(() => {
        setTimeout(() => {
            exportBtn.disabled = false;
            exportBtn.innerHTML = originalContent;
        }, 400);
    });
}

// Échapper le HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Fonctions pour la modale d'aide
function showSQLHelp() {
    document.getElementById('sqlHelpModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function hideSQLHelp() {
    document.getElementById('sqlHelpModal').classList.remove('active');
    document.body.style.overflow = '';
}

// ============================================================
// AI SQL generation : NL question → SELECT statement (Copilot-style popover)
//
// Popover floats over the editor — no permanent vertical space loss.
// Triggers : toolbar button, Ctrl+K (Cmd+K on Mac).
// Closes   : Esc, close icon, click outside, or successful generation.
//
// The generated SQL is NOT executed — it's injected into the active
// CodeMirror tab. The user reviews and clicks Execute as usual.
// Security (whitelist, SELECT-only) is enforced server-side at execute
// time, so the AI cannot bypass anything by emitting malicious SQL.
// ============================================================

function openAiSqlPopover() {
    const openBtn = document.getElementById('aiSqlOpenBtn');
    if (openBtn && openBtn.disabled) return;
    const popover = document.getElementById('aiSqlPopover');
    const input   = document.getElementById('aiSqlInput');
    if (!popover) return;
    popover.style.display = 'flex';
    if (input) {
        setTimeout(() => input.focus(), 30);
    }
}

function closeAiSqlPopover() {
    const popover = document.getElementById('aiSqlPopover');
    if (!popover) return;
    popover.style.display = 'none';
}

async function generateSqlFromNaturalLanguage() {
    const input   = document.getElementById('aiSqlInput');
    const btn     = document.getElementById('aiSqlGenerateBtn');
    const btnIcon = btn ? btn.querySelector('.ai-sql-btn-icon') : null;
    const btnSpin = btn ? btn.querySelector('.ai-sql-btn-spinner') : null;
    if (!input || !btn || btn.disabled) return;

    const question = input.value.trim();
    if (!question) {
        input.focus();
        return;
    }

    btn.disabled   = true;
    input.disabled = true;
    if (btnIcon) btnIcon.style.display = 'none';
    if (btnSpin) btnSpin.style.display = 'inline-block';

    try {
        const res = await fetch('../api/sql/ai-generate', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ question })
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            const msg = data.error || data.message || res.statusText || 'Unknown error';
            if (typeof showGlobalStatus === 'function') {
                showGlobalStatus('IA : ' + msg, 'error');
            } else {
                alert('IA : ' + msg);
            }
            return;
        }

        if (typeof sqlEditor !== 'undefined' && sqlEditor && data.sql) {
            sqlEditor.setValue(data.sql);
            sqlEditor.focus();
            const last = sqlEditor.lineCount() - 1;
            sqlEditor.setCursor({ line: last, ch: sqlEditor.getLine(last).length });
        }
        // Success: clear the prompt and close the popover.
        input.value = '';
        closeAiSqlPopover();
    } catch (e) {
        if (typeof showGlobalStatus === 'function') {
            showGlobalStatus('IA : ' + e.message, 'error');
        }
    } finally {
        btn.disabled = false;
        input.disabled = false;
        if (btnIcon) btnIcon.style.display = '';
        if (btnSpin) btnSpin.style.display = 'none';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    // If we landed here with ?run=1 (typically from a Dr. Brief deeplink),
    // auto-execute the prefilled query once the editor is initialised.
    // We strip the param from the URL afterwards so a refresh doesn't re-run.
    try {
        const params = new URLSearchParams(window.location.search);
        if (params.get('run') === '1' && typeof executeQuery === 'function') {
            // Give CodeMirror one tick to mount, then run.
            setTimeout(() => {
                try { executeQuery(); } catch (e) { console.error('Auto-run failed:', e); }
            }, 100);
            params.delete('run');
            const newUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
            window.history.replaceState({}, '', newUrl);
        }
    } catch (e) { /* non-blocking */ }

    const aiInput  = document.getElementById('aiSqlInput');
    const popover  = document.getElementById('aiSqlPopover');
    const openBtn  = document.getElementById('aiSqlOpenBtn');

    // Ctrl+Enter inside the textarea triggers generation (Enter alone allows
    // multi-line questions — they can be long).
    if (aiInput) {
        aiInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                generateSqlFromNaturalLanguage();
            }
        });
    }

    // Global shortcuts: Ctrl+K / Cmd+K opens the popover, Esc closes it.
    document.addEventListener('keydown', function (e) {
        // Open with Ctrl+K / Cmd+K (skip when typing in another text input
        // that might already use it — but our use here is benign).
        if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
            if (openBtn && openBtn.disabled) return;
            e.preventDefault();
            if (popover && popover.style.display === 'flex') {
                closeAiSqlPopover();
            } else {
                openAiSqlPopover();
            }
            return;
        }
        // Close on Esc when popover is open.
        if (e.key === 'Escape' && popover && popover.style.display === 'flex') {
            closeAiSqlPopover();
        }
    });

    // Click outside the popover closes it (skip clicks on the open button
    // itself — toggling is already handled by the button's onclick).
    document.addEventListener('mousedown', function (e) {
        if (!popover || popover.style.display !== 'flex') return;
        if (popover.contains(e.target)) return;
        if (openBtn && openBtn.contains(e.target)) return;
        closeAiSqlPopover();
    });
});

// Fermer les modales avec Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        hideSQLHelp();
    }
});

// Variables pour le graphique
let currentChart = null;
let currentChartType = 'doughnut';

function changeChartType(type) {
    currentChartType = type;
    
    // Mettre à jour les boutons actifs
    document.querySelectorAll('.chart-type-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    document.querySelector(`[data-type="${type}"]`).classList.add('active');
    
    // Régénérer le graphique
    generateChart(type);
}

function generateChart(type) {
    const canvas = document.getElementById('resultChart');
    const ctx = canvas.getContext('2d');
    
    // Détruire l'ancien graphique
    if (currentChart) {
        currentChart.destroy();
    }
    
    // Préparer les données
    const data = currentResultData;
    const columns = data.columns;
    
    // Trouver la première colonne texte (labels) et la première colonne numérique (valeurs)
    let labelColumn = columns[0];
    let valueColumn = columns.length > 1 ? columns[1] : columns[0];
    
    // Vérifier si la deuxième colonne est numérique
    const firstRow = data.rows[0];
    if (columns.length > 1 && !isNaN(parseFloat(firstRow[valueColumn]))) {
        // OK, on garde valueColumn
    } else {
        // Chercher la première colonne numérique
        for (let col of columns) {
            if (!isNaN(parseFloat(firstRow[col]))) {
                valueColumn = col;
                break;
            }
        }
    }
    
    const labels = data.rows.map(row => String(row[labelColumn]));
    const values = data.rows.map(row => parseFloat(row[valueColumn]) || 0);
    
    // Couleurs pastel (moins agressives, plus esthétiques)
    const colors = [
        '#93C5FD', '#86EFAC', '#FCD34D', '#FCA5A5', '#C4B5FD',
        '#F9A8D4', '#5EEAD4', '#FDBA74', '#67E8F9', '#BEF264',
        '#A5B4FC', '#FDA4AF', '#7DD3FC', '#86EFAC', '#D8B4FE',
        '#F0ABFC', '#5EEAD4', '#FCA5A5', '#A78BFA', '#6EE7B7'
    ];
    
    // Configuration du graphique
    const chartConfig = {
        type: type === 'horizontalBar' ? 'bar' : type,
        data: {
            labels: labels,
            datasets: [{
                label: valueColumn,
                data: values,
                backgroundColor: type === 'doughnut' ? colors : colors[0],
                borderColor: type === 'doughnut' ? colors : colors[0],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            indexAxis: type === 'horizontalBar' ? 'y' : 'x',
            plugins: {
                legend: {
                    display: type === 'doughnut',
                    position: 'right'
                },
                title: {
                    display: true,
                    text: `${labelColumn} vs ${valueColumn}`,
                    font: {
                        size: 16,
                        weight: 'bold'
                    }
                }
            },
            scales: type !== 'doughnut' ? {
                y: {
                    beginAtZero: true
                }
            } : {}
        }
    };
    
    // Créer le graphique
    currentChart = new Chart(ctx, chartConfig);
}
</script>
