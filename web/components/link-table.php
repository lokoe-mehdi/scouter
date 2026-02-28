<?php
/**
 * Composant réutilisable : Table de liens avec pagination AJAX
 * 
 * Paramètres requis dans $linkTableConfig :
 * - title : Titre du composant (string)
 * - id : ID unique du composant (string)
 * - sqlQuery : Requête SQL complète (string)
 * - sqlParams : Paramètres PDO (array)
 * - pdo : Connexion PDO (objet)
 * - projectDir : Répertoire du projet (string)
 * - defaultColumns : Colonnes visibles par défaut (array) - optionnel
 */

if(!isset($linkTableConfig) || !is_array($linkTableConfig)) {
    die('Configuration manquante pour le composant link-table. Utilisez $linkTableConfig = [...]');
}

// Charger le composant scope-modal
require_once __DIR__ . '/scope-modal.php';

// Extraction des paramètres
$componentTitle = $linkTableConfig['title'] ?? 'Résultats';
$componentId = $linkTableConfig['id'] ?? 'table_' . uniqid();
$pdo = $linkTableConfig['pdo'] ?? null;
$projectDir = $linkTableConfig['projectDir'] ?? '';
$defaultColumns = $linkTableConfig['defaultColumns'] ?? ['url', 'code', 'anchor', 'external', 'nofollow', 'type'];
$perPage = $linkTableConfig['perPage'] ?? 100;
$crawlId = $linkTableConfig['crawlId'] ?? null;
$copyUrl = $linkTableConfig['copyUrl'] ?? false;

if(!$pdo) {
    die('pdo est obligatoire dans $linkTableConfig');
}
if(!$crawlId) {
    die('crawlId est obligatoire dans $linkTableConfig');
}

// Récupérer les extracteurs personnalisés depuis JSONB automatiquement
$customExtractColumns = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT jsonb_object_keys(extracts) as key_name 
        FROM pages 
        WHERE crawl_id = :crawl_id AND extracts IS NOT NULL AND extracts != '{}'::jsonb
    ");
    $stmt->execute([':crawl_id' => $crawlId]);
    $customExtractColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    // Ignorer si pas d'extracteurs
}

// Stocker les paramètres pour construction SQL ultérieure
$useSimplifiedMode = isset($linkTableConfig['whereClause']);
$whereClause = $linkTableConfig['whereClause'] ?? 'WHERE 1=1';
$orderBy = $linkTableConfig['orderBy'] ?? 'ORDER BY cs.url';
$sqlParams = $linkTableConfig['sqlParams'] ?? [];
$sqlQuery = $linkTableConfig['sqlQuery'] ?? null;

// Extraire les conditions du WHERE pour le scope
if (!function_exists('extractScopeFromWhereClause')) {
    function extractScopeFromWhereClause($whereClause) {
        if (!$whereClause) return null;
        
        // Enlever le WHERE
        $conditions = preg_replace('/^\s*WHERE\s+/i', '', $whereClause);
        
        // Supprimer les conditions crawl_id
        $conditions = preg_replace('/\b\w*\.?crawl_id\s*=\s*[^\s]+\s*(AND\s+)?/i', '', $conditions);
        $conditions = preg_replace('/\s+AND\s*$/i', '', $conditions);
        $conditions = trim($conditions);
        
        if (empty($conditions) || $conditions === '1=1') {
            return null;
        }
        
        // Séparer les conditions par AND
        $parts = preg_split('/\s+AND\s+/i', $conditions);
        $scopeItems = [];
        
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) continue;
            
            // Nettoyer les alias de table (c., pages., etc.)
            $part = preg_replace('/\b\w+\.(\w+)/', '$1', $part);
            
            if (!empty($part) && $part !== '1=1') {
                $scopeItems[] = $part;
            }
        }
        
        return !empty($scopeItems) ? $scopeItems : null;
    }
}

$scopeItems = extractScopeFromWhereClause($whereClause);

// Construire la requête SQL pour le SQL Explorer
// WHERE sans crawl_id et avec alias simplifiés (garder l. pour links)
$cleanedWhere = preg_replace('/\bcs\./i', 's.', $whereClause);
$cleanedWhere = preg_replace('/\bct\./i', 't.', $cleanedWhere);
$cleanedWhere = preg_replace('/\bcrawl_id\s*=\s*\d+\s+AND\s+/i', '', $cleanedWhere);
$cleanedWhere = preg_replace('/\s+AND\s+crawl_id\s*=\s*\d+/i', '', $cleanedWhere);
$cleanedWhere = preg_replace('/\bWHERE\s+crawl_id\s*=\s*\d+\s*$/i', '', $cleanedWhere);

// ORDER BY avec alias simplifiés (garder l. pour links)
$cleanedOrderBy = preg_replace('/\bcs\./i', 's.', $orderBy);
$cleanedOrderBy = preg_replace('/\bct\./i', 't.', $cleanedOrderBy);

// Requête SQL complète pour les liens
$tableSqlQuery = "SELECT 
    s.url AS source_url,
    t.url AS target_url,
    l.anchor,
    l.type,
    l.external,
    l.nofollow
FROM links l
LEFT JOIN pages s ON l.src = s.id
LEFT JOIN pages t ON l.target = t.id
" . $cleanedWhere . "
" . $cleanedOrderBy;

// Substituer les paramètres par leurs vraies valeurs pour l'affichage
if (!function_exists('substituteParamsInSql')) {
    function substituteParamsInSql($sql, $params) {
        foreach ($params as $key => $value) {
            if (is_string($value)) {
                $formattedValue = "'" . addslashes($value) . "'";
            } elseif (is_bool($value)) {
                $formattedValue = $value ? 'true' : 'false';
            } elseif (is_null($value)) {
                $formattedValue = 'NULL';
            } else {
                $formattedValue = $value;
            }
            $sql = str_replace($key, $formattedValue, $sql);
        }
        return $sql;
    }
}

$tableSqlQuery = substituteParamsInSql($tableSqlQuery, $sqlParams);

// Substituer également dans les scopeItems
if (!empty($scopeItems) && !empty($sqlParams)) {
    $scopeItems = array_map(function($item) use ($sqlParams) {
        return substituteParamsInSql($item, $sqlParams);
    }, $scopeItems);
}

// Colonnes spécifiques aux liens (en premier dans le sélecteur)
$linkSpecificColumns = [
    'anchor' => 'Anchor',
    'external' => 'Externe',
    'nofollow' => 'Follow',
    'type' => 'Type de lien'
];

// Colonnes disponibles (seront dupliquées en source_ et target_)
$availableColumns = [
    'url' => 'URL',
    'depth' => 'Profondeur',
    'code' => 'Code HTTP',
    'category' => 'Catégorie',
    'inlinks' => 'Liens entrants',
    'outlinks' => 'Liens sortants',
    'response_time' => 'TTFB (ms)',
    'schemas' => 'Données structurées',
    'compliant' => 'Indexable',
    'canonical' => 'Canonical',
    'canonical_value' => 'URL Canonical',
    'noindex' => 'Noindex',
    'blocked' => 'Bloqué',
    'redirect_to' => 'Redirige vers',
    'content_type' => 'Type de contenu',
    'pri' => 'PageRank',
    'title_status' => 'Title Status',
    'title' => 'Title',
    'h1_status' => 'H1 Status',
    'h1' => 'H1',
    'metadesc_status' => 'Meta Desc Status',
    'metadesc' => 'Meta Description',
    'h1_multiple' => 'H1 Multiples',
    'headings_missing' => 'Mauvaise structure hn',
    'word_count' => 'Nb mots'
];

// Ajout des colonnes d'extracteurs JSONB aux colonnes disponibles
foreach($customExtractColumns as $columnName) {
    $label = ucwords(str_replace('_', ' ', $columnName));
    $availableColumns['extract_' . $columnName] = 'Extracteur : ' . $label;
}

// Récupération des colonnes sélectionnées (compatibilité avec anciens paramètres)
if($componentId === 'main_explorer' && isset($_GET['columns'])) {
    $selectedColumnsRaw = explode(',', $_GET['columns']);
} else {
    $selectedColumnsRaw = isset($_GET['columns_' . $componentId]) ? explode(',', $_GET['columns_' . $componentId]) : $defaultColumns;
}
if(empty($selectedColumnsRaw)) {
    $selectedColumnsRaw = ['url'];
}

// Remplacer 'extract' par toutes les colonnes extract_* trouvées
if(in_array('extract', $selectedColumnsRaw)) {
    $newSelectedColumns = [];
    foreach($selectedColumnsRaw as $col) {
        if($col === 'extract') {
            // Remplacer par toutes les colonnes extract
            foreach($customExtractColumns as $extCol) {
                $newSelectedColumns[] = 'extract_' . $extCol;
            }
        } else {
            $newSelectedColumns[] = $col;
        }
    }
    $selectedColumnsRaw = $newSelectedColumns;
}

// Réordonner les colonnes sélectionnées selon l'ordre de $availableColumns
$orderedColumns = [];
foreach(array_keys($availableColumns) as $col) {
    if(in_array($col, $selectedColumnsRaw)) {
        $orderedColumns[] = $col;
    }
}
// Ajouter les colonnes link (anchor, type, etc.) dans l'ordre
foreach(array_keys($linkSpecificColumns) as $col) {
    if(in_array($col, $selectedColumnsRaw)) {
        $orderedColumns[] = $col;
    }
}
// Ajouter les colonnes qui ne sont pas dans les listes (colonnes custom)
foreach($selectedColumnsRaw as $col) {
    if(!in_array($col, $orderedColumns)) {
        $orderedColumns[] = $col;
    }
}
$selectedColumnsRaw = $orderedColumns;

// Transformer les colonnes sélectionnées pour avoir source_ et target_
// IMPORTANT: Les colonnes link (anchor, external, nofollow, type) doivent TOUJOURS être au milieu
// Exemple: ['url', 'code', 'anchor'] => ['source_url', 'source_code', 'anchor', 'target_url', 'target_code']
$selectedColumns = [];

// Séparer les colonnes URL des colonnes Link
$urlColumns = [];
$linkColumns = [];

foreach($selectedColumnsRaw as $col) {
    if(isset($availableColumns[$col])) {
        $urlColumns[] = $col;
    } elseif(isset($linkSpecificColumns[$col])) {
        $linkColumns[] = $col;
    }
}

// 1. Ajouter toutes les colonnes source
foreach($urlColumns as $col) {
    $selectedColumns[] = 'source_' . $col;
}

// 2. Ajouter les colonnes link AU MILIEU
foreach($linkColumns as $col) {
    $selectedColumns[] = $col;
}

// 3. Ajouter toutes les colonnes target
foreach($urlColumns as $col) {
    $selectedColumns[] = 'target_' . $col;
}


// Récupération du tri depuis l'URL
$sortColumn = null;
$sortDirection = 'ASC';
if($componentId === 'main_explorer' && isset($_GET['sort'])) {
    $sortColumn = $_GET['sort'];
    $sortDirection = isset($_GET['dir']) && strtoupper($_GET['dir']) === 'DESC' ? 'DESC' : 'ASC';
} elseif(isset($_GET['sort_' . $componentId])) {
    $sortColumn = $_GET['sort_' . $componentId];
    $sortDirection = isset($_GET['dir_' . $componentId]) && strtoupper($_GET['dir_' . $componentId]) === 'DESC' ? 'DESC' : 'ASC';
}

// Mapper les colonnes vers leurs vraies colonnes SQL (pour les liens)
// Colonnes spécifiques aux liens
$columnMapping = [
    'anchor' => 'l.anchor',
    'external' => 'l.external',
    'nofollow' => 'l.nofollow',
    'type' => 'l.type'
];

// Ajouter les colonnes SOURCE avec préfixe source_
$columnMapping['source_url'] = 'cs.url';
$columnMapping['source_depth'] = 'cs.depth';
$columnMapping['source_code'] = 'cs.code';
$columnMapping['source_inlinks'] = 'cs.inlinks';
$columnMapping['source_outlinks'] = 'cs.outlinks';
$columnMapping['source_response_time'] = 'cs.response_time';
$columnMapping['source_schemas'] = 'array_length(cs.schemas, 1)';
$columnMapping['source_compliant'] = 'cs.compliant';
$columnMapping['source_canonical'] = 'cs.canonical';
$columnMapping['source_canonical_value'] = 'cs.canonical_value';
$columnMapping['source_noindex'] = 'cs.noindex';
$columnMapping['source_blocked'] = 'cs.blocked';
$columnMapping['source_redirect_to'] = 'cs.redirect_to';
$columnMapping['source_content_type'] = 'cs.content_type';
$columnMapping['source_pri'] = 'cs.pri';
$columnMapping['source_title'] = 'cs.title';
$columnMapping['source_title_status'] = 'cs.title_status';
$columnMapping['source_h1'] = 'cs.h1';
$columnMapping['source_h1_status'] = 'cs.h1_status';
$columnMapping['source_metadesc'] = 'cs.metadesc';
$columnMapping['source_metadesc_status'] = 'cs.metadesc_status';
$columnMapping['source_h1_multiple'] = 'cs.h1_multiple';
$columnMapping['source_headings_missing'] = 'cs.headings_missing';
$columnMapping['source_word_count'] = 'cs.word_count';
$columnMapping['source_category'] = 'cats.cat';

// Ajouter les colonnes TARGET avec préfixe target_
$columnMapping['target_url'] = 'ct.url';
$columnMapping['target_depth'] = 'ct.depth';
$columnMapping['target_code'] = 'ct.code';
$columnMapping['target_inlinks'] = 'ct.inlinks';
$columnMapping['target_outlinks'] = 'ct.outlinks';
$columnMapping['target_response_time'] = 'ct.response_time';
$columnMapping['target_schemas'] = 'array_length(ct.schemas, 1)';
$columnMapping['target_compliant'] = 'ct.compliant';
$columnMapping['target_canonical'] = 'ct.canonical';
$columnMapping['target_canonical_value'] = 'ct.canonical_value';
$columnMapping['target_noindex'] = 'ct.noindex';
$columnMapping['target_blocked'] = 'ct.blocked';
$columnMapping['target_redirect_to'] = 'ct.redirect_to';
$columnMapping['target_content_type'] = 'ct.content_type';
$columnMapping['target_pri'] = 'ct.pri';
$columnMapping['target_title'] = 'ct.title';
$columnMapping['target_title_status'] = 'ct.title_status';
$columnMapping['target_h1'] = 'ct.h1';
$columnMapping['target_h1_status'] = 'ct.h1_status';
$columnMapping['target_metadesc'] = 'ct.metadesc';
$columnMapping['target_metadesc_status'] = 'ct.metadesc_status';
$columnMapping['target_h1_multiple'] = 'ct.h1_multiple';
$columnMapping['target_headings_missing'] = 'ct.headings_missing';
$columnMapping['target_word_count'] = 'ct.word_count';
$columnMapping['target_category'] = 'catt.cat';

// Ajouter les colonnes extract_* au mapping pour le tri (JSONB)
foreach($customExtractColumns as $col) {
    $colAlias = 'extract_' . preg_replace('/[^a-z0-9_]/i', '_', $col);
    $columnMapping['source_' . $colAlias] = "cs.extracts->>'" . addslashes($col) . "'";
    $columnMapping['target_' . $colAlias] = "ct.extracts->>'" . addslashes($col) . "'";
}

// Si un tri est demandé, remplacer l'ORDER BY par défaut
if($sortColumn && isset($columnMapping[$sortColumn])) {
    $orderBy = 'ORDER BY ' . $columnMapping[$sortColumn] . ' ' . $sortDirection;
}

// ============================================
// APPROCHE OPTIMISÉE : Requêtes séparées sans double jointure
// ============================================

// Récupération du perPage depuis l'URL (compatibilité avec anciens paramètres)
if($componentId === 'main_explorer' && isset($_GET['per_page'])) {
    $perPage = max(10, min(500, (int)$_GET['per_page']));
} elseif(isset($_GET['per_page_' . $componentId])) {
    $perPage = max(10, min(500, (int)$_GET['per_page_' . $componentId]));
}

// Pagination (compatibilité avec anciens paramètres)
if($componentId === 'main_explorer' && isset($_GET['p'])) {
    $page_num = max(1, (int)$_GET['p']);
} else {
    $page_num = isset($_GET['p_' . $componentId]) ? max(1, (int)$_GET['p_' . $componentId]) : 1;
}
$offset = ($page_num - 1) * $perPage;

// Injecter le crawl_id dans le WHERE
$crawlIdInt = intval($crawlId);

// Utiliser le tableau centralisé des catégories (chargé dans dashboard.php)
$categoriesMap = $GLOBALS['categoriesMap'] ?? [];

// ============================================
// GESTION DES FILTRES - OPTIMISATION DES JOINTURES
// - Filtre sur source (cs.) → jointure uniquement sur source
// - Filtre sur target (ct.) → jointure uniquement sur target  
// - Filtre sur les deux → double jointure
// - Aucun filtre page → mode optimisé sans jointure
// ============================================
$hasSourceFilter = preg_match('/\bcs\./', $whereClause);
$hasTargetFilter = preg_match('/\bct\./', $whereClause);

// Détecter si le TRI nécessite une jointure source ou target
$needsSourceJoinForSort = $sortColumn && strpos($sortColumn, 'source_') === 0;
$needsTargetJoinForSort = $sortColumn && strpos($sortColumn, 'target_') === 0;

// Détecter si le TRI nécessite une jointure sur les catégories
$needsSourceCatJoinForSort = $sortColumn === 'source_category';
$needsTargetCatJoinForSort = $sortColumn === 'target_category';

$crawlIdCondition = "l.crawl_id = $crawlIdInt";

// Construire les jointures nécessaires (pour filtre OU tri)
$joinClauses = "";
if ($hasSourceFilter || $needsSourceJoinForSort) {
    $joinClauses .= " LEFT JOIN pages cs ON l.src = cs.id AND cs.crawl_id = $crawlIdInt";
}
if ($hasTargetFilter || $needsTargetJoinForSort) {
    $joinClauses .= " LEFT JOIN pages ct ON l.target = ct.id AND ct.crawl_id = $crawlIdInt";
}
// Jointures sur les catégories si tri par catégorie
if ($needsSourceCatJoinForSort) {
    if (!$hasSourceFilter && !$needsSourceJoinForSort) {
        $joinClauses .= " LEFT JOIN pages cs ON l.src = cs.id AND cs.crawl_id = $crawlIdInt";
    }
    $joinClauses .= " LEFT JOIN categories cats ON cs.cat_id = cats.id";
}
if ($needsTargetCatJoinForSort) {
    if (!$hasTargetFilter && !$needsTargetJoinForSort) {
        $joinClauses .= " LEFT JOIN pages ct ON l.target = ct.id AND ct.crawl_id = $crawlIdInt";
    }
    $joinClauses .= " LEFT JOIN categories catt ON ct.cat_id = catt.id";
}

// Si on a besoin d'une jointure pour le tri, on doit passer en mode avec jointure
$hasSourceFilter = $hasSourceFilter || $needsSourceJoinForSort || $needsSourceCatJoinForSort;
$hasTargetFilter = $hasTargetFilter || $needsTargetJoinForSort || $needsTargetCatJoinForSort;

if ($hasSourceFilter || $hasTargetFilter) {
    // MODE AVEC JOINTURE(S) : uniquement celles nécessaires
    $fullWhereClause = $whereClause;
    if (stripos($fullWhereClause, 'WHERE') !== false) {
        $fullWhereClause = preg_replace('/WHERE\s+/i', 'WHERE ' . $crawlIdCondition . ' AND ', $fullWhereClause);
    } else {
        $fullWhereClause = 'WHERE ' . $crawlIdCondition;
    }
    
    // COUNT avec jointure(s) minimale(s)
    $countQuery = "SELECT COUNT(*) as total FROM links l $joinClauses $fullWhereClause";
    $sqlCount = $pdo->prepare($countQuery);
    $sqlCount->execute($sqlParams);
    $result = $sqlCount->fetch(PDO::FETCH_OBJ);
    $totalResults = $result ? $result->total : 0;
    $totalPages = ceil($totalResults / $perPage);
    
    // ORDER BY - utiliser le columnMapping pour le tri
    $linksOrderBy = $hasSourceFilter ? 'ORDER BY cs.url' : 'ORDER BY l.src';
    if($sortColumn && isset($columnMapping[$sortColumn])) {
        $linksOrderBy = 'ORDER BY ' . $columnMapping[$sortColumn] . ' ' . $sortDirection;
    }
    
    // Requête liens avec jointure(s) minimale(s)
    $linksQuery = "SELECT l.src, l.target, l.anchor, l.external, l.nofollow, l.type 
                   FROM links l $joinClauses
                   $fullWhereClause 
                   $linksOrderBy 
                   LIMIT $perPage OFFSET $offset";
    $stmt = $pdo->prepare($linksQuery);
    $stmt->execute($sqlParams);
    $linksRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} else {
    // MODE OPTIMISÉ : pas de filtre sur pages, requête simple sans jointure
    $linksWhereClause = "WHERE " . $crawlIdCondition;
    
    if (stripos($whereClause, 'WHERE') !== false) {
        $conditions = preg_replace('/^WHERE\s+/i', '', $whereClause);
        $linksWhereClause .= " AND " . $conditions;
    }
    
    // COUNT simple
    $countQuery = "SELECT COUNT(*) as total FROM links l $linksWhereClause";
    $sqlCount = $pdo->prepare($countQuery);
    $sqlCount->execute($sqlParams);
    $result = $sqlCount->fetch(PDO::FETCH_OBJ);
    $totalResults = $result ? $result->total : 0;
    $totalPages = ceil($totalResults / $perPage);
    
    // ORDER BY
    $linksOrderBy = 'ORDER BY l.src';
    if($sortColumn) {
        $linkSortMap = [
            'anchor' => 'l.anchor',
            'external' => 'l.external',
            'nofollow' => 'l.nofollow',
            'type' => 'l.type'
        ];
        if(isset($linkSortMap[$sortColumn])) {
            $linksOrderBy = 'ORDER BY ' . $linkSortMap[$sortColumn] . ' ' . $sortDirection;
        }
    }
    
    // Requête liens simple
    $linksQuery = "SELECT l.src, l.target, l.anchor, l.external, l.nofollow, l.type 
                   FROM links l 
                   $linksWhereClause 
                   $linksOrderBy 
                   LIMIT $perPage OFFSET $offset";
    $stmt = $pdo->prepare($linksQuery);
    $stmt->execute($sqlParams);
    $linksRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 4. COLLECTER TOUS LES IDS DE PAGES UNIQUES (src + target)
$pageIds = [];
foreach ($linksRaw as $link) {
    if ($link['src']) $pageIds[$link['src']] = true;
    if ($link['target']) $pageIds[$link['target']] = true;
}
$pageIds = array_keys($pageIds);

// 5. RÉCUPÉRER LES PAGES CORRESPONDANTES via IN (une seule requête)
$pagesMap = [];
if (!empty($pageIds)) {
    $placeholders = implode(',', array_fill(0, count($pageIds), '?'));
    $pagesQuery = "SELECT id, url, depth, code, cat_id, inlinks, outlinks, response_time, schemas,
                          compliant, canonical, canonical_value, noindex, blocked, redirect_to, content_type, 
                          pri, title, title_status, h1, h1_status, metadesc, metadesc_status, 
                          h1_multiple, headings_missing, extracts, word_count
                   FROM pages 
                   WHERE crawl_id = ? AND id IN ($placeholders)";
    $stmt = $pdo->prepare($pagesQuery);
    $stmt->execute(array_merge([$crawlIdInt], $pageIds));
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pagesMap[$row['id']] = $row;
    }
}

// 6. CONSTRUIRE LE RÉSULTAT FINAL EN PHP
$urls = [];
foreach ($linksRaw as $link) {
    $srcPage = $pagesMap[$link['src']] ?? null;
    $targetPage = $pagesMap[$link['target']] ?? null;
    
    $row = new stdClass();
    
    // Colonnes du lien
    $row->anchor = $link['anchor'];
    $row->external = $link['external'];
    $row->nofollow = $link['nofollow'];
    $row->type = $link['type'];
    
    // Colonnes SOURCE
    if ($srcPage) {
        $row->source_url = $srcPage['url'];
        $row->source_depth = $srcPage['depth'];
        $row->source_code = $srcPage['code'];
        $row->source_inlinks = $srcPage['inlinks'];
        $row->source_outlinks = $srcPage['outlinks'];
        $row->source_response_time = $srcPage['response_time'];
        $row->source_schemas = $srcPage['schemas'];
        $row->source_compliant = $srcPage['compliant'];
        $row->source_canonical = $srcPage['canonical'];
        $row->source_canonical_value = $srcPage['canonical_value'];
        $row->source_noindex = $srcPage['noindex'];
        $row->source_blocked = $srcPage['blocked'];
        $row->source_redirect_to = $srcPage['redirect_to'];
        $row->source_content_type = $srcPage['content_type'];
        $row->source_pri = $srcPage['pri'];
        $row->source_title = $srcPage['title'];
        $row->source_title_status = $srcPage['title_status'];
        $row->source_h1 = $srcPage['h1'];
        $row->source_h1_status = $srcPage['h1_status'];
        $row->source_metadesc = $srcPage['metadesc'];
        $row->source_metadesc_status = $srcPage['metadesc_status'];
        $row->source_h1_multiple = $srcPage['h1_multiple'];
        $row->source_headings_missing = $srcPage['headings_missing'];
        $row->source_word_count = $srcPage['word_count'];
        
        // Catégorie source
        $srcCatId = $srcPage['cat_id'];
        $row->source_category = isset($categoriesMap[$srcCatId]) ? $categoriesMap[$srcCatId]['cat'] : 'Non catégorisé';
        $row->source_category_color = isset($categoriesMap[$srcCatId]) ? $categoriesMap[$srcCatId]['color'] : null;
        
        // Extracteurs source (JSONB)
        if (!empty($srcPage['extracts'])) {
            $extracts = is_string($srcPage['extracts']) ? json_decode($srcPage['extracts'], true) : $srcPage['extracts'];
            if ($extracts) {
                foreach ($extracts as $key => $value) {
                    $row->{'source_extract_' . $key} = $value;
                }
            }
        }
    } else {
        $row->source_url = null;
        $row->source_depth = null;
        $row->source_code = null;
        $row->source_category = 'N/A';
    }
    
    // Colonnes TARGET
    if ($targetPage) {
        $row->target_url = $targetPage['url'];
        $row->target_depth = $targetPage['depth'];
        $row->target_code = $targetPage['code'];
        $row->target_inlinks = $targetPage['inlinks'];
        $row->target_outlinks = $targetPage['outlinks'];
        $row->target_response_time = $targetPage['response_time'];
        $row->target_schemas = $targetPage['schemas'];
        $row->target_compliant = $targetPage['compliant'];
        $row->target_canonical = $targetPage['canonical'];
        $row->target_canonical_value = $targetPage['canonical_value'];
        $row->target_noindex = $targetPage['noindex'];
        $row->target_blocked = $targetPage['blocked'];
        $row->target_redirect_to = $targetPage['redirect_to'];
        $row->target_content_type = $targetPage['content_type'];
        $row->target_pri = $targetPage['pri'];
        $row->target_title = $targetPage['title'];
        $row->target_title_status = $targetPage['title_status'];
        $row->target_h1 = $targetPage['h1'];
        $row->target_h1_status = $targetPage['h1_status'];
        $row->target_metadesc = $targetPage['metadesc'];
        $row->target_metadesc_status = $targetPage['metadesc_status'];
        $row->target_h1_multiple = $targetPage['h1_multiple'];
        $row->target_headings_missing = $targetPage['headings_missing'];
        $row->target_word_count = $targetPage['word_count'];
        
        // Catégorie target
        $targetCatId = $targetPage['cat_id'];
        $row->target_category = isset($categoriesMap[$targetCatId]) ? $categoriesMap[$targetCatId]['cat'] : 'Non catégorisé';
        $row->target_category_color = isset($categoriesMap[$targetCatId]) ? $categoriesMap[$targetCatId]['color'] : null;
        
        // Extracteurs target (JSONB)
        if (!empty($targetPage['extracts'])) {
            $extracts = is_string($targetPage['extracts']) ? json_decode($targetPage['extracts'], true) : $targetPage['extracts'];
            if ($extracts) {
                foreach ($extracts as $key => $value) {
                    $row->{'target_extract_' . $key} = $value;
                }
            }
        }
    } else {
        $row->target_url = null;
        $row->target_depth = null;
        $row->target_code = null;
        $row->target_category = 'N/A';
    }
    
    $urls[] = $row;
}

// Tri en PHP si nécessaire (pour les colonnes pages qui ne peuvent pas être triées en SQL)
if ($sortColumn && !isset($linkSortMap[$sortColumn])) {
    usort($urls, function($a, $b) use ($sortColumn, $sortDirection) {
        $valA = $a->$sortColumn ?? '';
        $valB = $b->$sortColumn ?? '';
        
        // Tri numérique pour certaines colonnes
        if (is_numeric($valA) && is_numeric($valB)) {
            $cmp = $valA - $valB;
        } else {
            $cmp = strcasecmp((string)$valA, (string)$valB);
        }
        
        return $sortDirection === 'DESC' ? -$cmp : $cmp;
    });
}

// Fonction helper pour obtenir le label d'une colonne
function getColumnLabel($col, $availableColumns, $linkSpecificColumns) {
    if(isset($linkSpecificColumns[$col])) {
        return $linkSpecificColumns[$col];
    }
    if(strpos($col, 'source_') === 0) {
        $baseCol = substr($col, 7); // Remove 'source_'
        return 'Source ' . ($availableColumns[$baseCol] ?? $baseCol);
    }
    if(strpos($col, 'target_') === 0) {
        $baseCol = substr($col, 7); // Remove 'target_'
        return 'Target ' . ($availableColumns[$baseCol] ?? $baseCol);
    }
    return $availableColumns[$col] ?? $col;
}
?>

<!-- Formulaire caché pour l'export CSV -->
<form id="exportForm_<?= $componentId ?>" method="POST" action="api/export/links-csv?project=<?= htmlspecialchars($crawlId ?? $projectDir) ?>" target="_blank" style="display: none;">
    <input type="hidden" name="filters" value="">
    <input type="hidden" name="search" value="">
    <input type="hidden" name="columns" id="exportColumns_<?= $componentId ?>" value="">
</form>

<!-- Résultats -->
<div class="table-card" id="tableCard_<?= $componentId ?>">
    <div class="table-header" style="padding: 0rem 0rem 0rem 0rem; display: block !important;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
            <!-- Gauche : Titre -->
            <h3 class="table-title" style="margin: 0;">
                <?= htmlspecialchars($componentTitle) ?> (<?= number_format($totalResults ?? 0) ?> Liens)
            </h3>
            
            <!-- Droite : Scope + Copier + Export CSV -->
            <div style="display: flex; gap: 0.5rem; align-items: center;">
                <span onclick="showTableScope_<?= $componentId ?>()" class="chart-action-btn" title="Voir le scope des données" style="cursor: pointer;">
                    <span class="material-symbols-outlined">database</span>
                </span>
                <button class="btn-table-action btn-copy" onclick="copyTableToClipboard_<?= $componentId ?>(event)">
                    <span class="material-symbols-outlined">content_copy</span>
                    Copier
                </button>
                <button class="btn-table-action btn-export" onclick="exportToCSV_<?= $componentId ?>()">
                    <span class="material-symbols-outlined">download</span>
                    Export CSV
                </button>
            </div>
        </div>
        
        <!-- Ligne du bas : Colonnes à gauche, Pagination à droite -->
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <!-- Gauche : Colonnes -->
            <div style="position: relative;">
                <button class="btn-table-action btn-columns-select" onclick="toggleColumnDropdown_<?= $componentId ?>()">
                    <span class="material-symbols-outlined">view_column</span>
                    Colonnes
                </button>
                <div id="columnDropdown_<?= $componentId ?>" class="column-dropdown-<?= $componentId ?>" style="display: none; position: absolute; left: 0; top: 100%; margin-top: 0.5rem; background: white; border: 1px solid var(--border-color); border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); min-width: 250px; max-height: 450px; z-index: 1000; flex-direction: column;">
                    <!-- Header fixe -->
                    <div style="padding: 1rem 1rem 0.5rem 1rem; border-bottom: 1px solid var(--border-color); background: white; border-radius: 8px 8px 0 0;">
                        <div style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.5rem;">Sélectionner les colonnes</div>
                        <div style="font-size: 0.85rem; color: var(--text-secondary);">
                            <a href="javascript:void(0)" onclick="toggleAllColumns_<?= $componentId ?>(true)" style="color: var(--primary-color); text-decoration: none; cursor: pointer;">tout cocher</a>
                            <span style="margin: 0 0.25rem; color: var(--border-color);">|</span>
                            <a href="javascript:void(0)" onclick="toggleAllColumns_<?= $componentId ?>(false)" style="color: var(--text-secondary); text-decoration: none; cursor: pointer;">tout décocher</a>
                        </div>
                    </div>
                    
                    <!-- Liste scrollable des colonnes -->
                    <div style="flex: 1; overflow-y: auto; padding: 0.5rem 1rem; max-height: 280px;">
                        <!-- Colonnes spécifiques aux liens en premier -->
                        <div style="margin-bottom: 0.75rem; padding-bottom: 0.5rem; border-bottom: 2px solid var(--border-color);">
                            <div style="font-size: 0.75rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.5rem; text-transform: uppercase;">Colonnes Liens</div>
                            <?php foreach($linkSpecificColumns as $key => $label): ?>
                            <label style="display: block; padding: 0.5rem; cursor: pointer; border-radius: 4px; transition: background 0.2s; background: #f8f9fa;" onmouseover="this.style.background='#e9ecef'" onmouseout="this.style.background='#f8f9fa'">
                                <input type="checkbox" class="column-checkbox-<?= $componentId ?>" value="<?= $key ?>" 
                                    <?= in_array($key, $selectedColumnsRaw) ? 'checked' : '' ?>
                                    style="margin-right: 0.5rem; accent-color: var(--primary-color);">
                                <?= $label ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Colonnes URL (source/target) -->
                        <div style="font-size: 0.75rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.5rem; text-transform: uppercase;">Colonnes URL</div>
                        <?php foreach($availableColumns as $key => $label): ?>
                        <label style="display: block; padding: 0.5rem; cursor: pointer; border-radius: 4px; transition: background 0.2s;" onmouseover="this.style.background='var(--background)'" onmouseout="this.style.background='transparent'">
                            <input type="checkbox" class="column-checkbox-<?= $componentId ?>" value="<?= $key ?>" 
                                <?= in_array($key, $selectedColumnsRaw) ? 'checked' : '' ?>
                                <?= $key === 'url' ? 'disabled' : '' ?>
                                style="margin-right: 0.5rem; accent-color: var(--primary-color);">
                            <?= $label ?><?= $key === 'url' ? ' (obligatoire)' : '' ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Footer fixe avec boutons -->
                    <div style="padding: 1rem; border-top: 1px solid var(--border-color); background: white; border-radius: 0 0 8px 8px; display: flex; gap: 0.5rem;">
                        <button class="btn" onclick="applyColumns_<?= $componentId ?>()" style="flex: 1; background: var(--primary-color); color: white; border: none; padding: 0.6rem; font-weight: 500;">Appliquer</button>
                        <button class="btn" onclick="toggleColumnDropdown_<?= $componentId ?>()" style="flex: 1; background: #95a5a6; color: white; border: none; padding: 0.6rem; font-weight: 500;">Annuler</button>
                    </div>
                </div>
            </div>
            
            <!-- Droite : Pagination -->
            <div id="paginationTop_<?= $componentId ?>" style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem; color: var(--text-secondary);">
                <span id="paginationInfo_<?= $componentId ?>">Affichage de <?= number_format(($offset ?? 0) + 1) ?> à <?= number_format(min(($offset ?? 0) + $perPage, $totalResults ?? 0)) ?> sur <?= number_format($totalResults ?? 0) ?> Liens</span>
                <button onclick="changePage_<?= $componentId ?>(<?= max(1, $page_num - 1) ?>)" <?= $page_num <= 1 ? 'disabled' : '' ?> style="padding: 0.4rem; border: 1px solid #dee2e6; background: white; border-radius: 4px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s ease; <?= $page_num <= 1 ? 'opacity: 0.4; cursor: default;' : '' ?>" onmouseover="<?= $page_num > 1 ? 'this.style.background=\"#f8f9fa\"; this.style.borderColor=\"#adb5bd\"' : '' ?>" onmouseout="<?= $page_num > 1 ? 'this.style.background=\"white\"; this.style.borderColor=\"#dee2e6\"' : '' ?>">
                    <span class="material-symbols-outlined" style="font-size: 20px;">chevron_left</span>
                </button>
                <button onclick="changePage_<?= $componentId ?>(<?= min($totalPages, $page_num + 1) ?>)" <?= $page_num >= $totalPages ? 'disabled' : '' ?> style="padding: 0.4rem; border: 1px solid #dee2e6; background: white; border-radius: 4px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s ease; <?= $page_num >= $totalPages ? 'opacity: 0.4; cursor: default;' : '' ?>" onmouseover="<?= $page_num < $totalPages ? 'this.style.background=\"#f8f9fa\"; this.style.borderColor=\"#adb5bd\"' : '' ?>" onmouseout="<?= $page_num < $totalPages ? 'this.style.background=\"white\"; this.style.borderColor=\"#dee2e6\"' : '' ?>">
                    <span class="material-symbols-outlined" style="font-size: 20px;">chevron_right</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Barre de scroll horizontale du haut (se synchronise avec celle du bas) -->
    <div id="topScrollbar_<?= $componentId ?>" style="overflow-x: auto; overflow-y: hidden; margin-bottom: 0.5rem;">
        <div id="topScrollbarContent_<?= $componentId ?>" style="height: 1px;"></div>
    </div>

    <div id="tableContainer_<?= $componentId ?>" style="overflow-x: auto;">
        <table class="data-table" id="urlTable_<?= $componentId ?>">
            <?php
            // Tooltips pour les colonnes
            $columnTooltips = [
                'response_time' => 'Time To First Byte',
                'source_response_time' => 'Time To First Byte',
                'target_response_time' => 'Time To First Byte',
                'pri' => 'PageRank Interne - Score d\'autorité basé sur les liens internes',
                'source_pri' => 'PageRank Interne - Score d\'autorité basé sur les liens internes',
                'target_pri' => 'PageRank Interne - Score d\'autorité basé sur les liens internes',
                'compliant' => 'URL indexable (pas de noindex, canonical ok, non bloquée)',
                'source_compliant' => 'URL indexable (pas de noindex, canonical ok, non bloquée)',
                'target_compliant' => 'URL indexable (pas de noindex, canonical ok, non bloquée)',
            ];
            ?>
            <thead>
                <tr>
                    <?php foreach($selectedColumns as $col): ?>
                        <?php $isLinkColumn = isset($linkSpecificColumns[$col]); ?>
                        <?php $tooltip = $columnTooltips[$col] ?? ''; ?>
                        <th class="col-<?= $col ?>" style="cursor: pointer; user-select: none; position: relative; <?= $isLinkColumn ? 'background: #f8f9fa;' : '' ?>" onclick="sortByColumn_<?= $componentId ?>('<?= $col ?>')" <?= $tooltip ? 'title="' . htmlspecialchars($tooltip) . '"' : '' ?>>
                            <div style="display: flex; align-items: center; gap: 0.3rem;">
                                <span><?= getColumnLabel($col, $availableColumns, $linkSpecificColumns) ?></span>
                                <?php if($sortColumn === $col): ?>
                                    <span class="material-symbols-outlined" style="font-size: 18px; color: var(--primary-color);">
                                        <?= $sortDirection === 'ASC' ? 'arrow_upward' : 'arrow_downward' ?>
                                    </span>
                                <?php else: ?>
                                    <span class="material-symbols-outlined" style="font-size: 18px; color: #bdc3c7; opacity: 0.5;">
                                        unfold_more
                                    </span>
                                <?php endif; ?>
                            </div>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($urls)): ?>
                <tr>
                    <td colspan="<?= count($selectedColumns) ?>" style="text-align: center; padding: 4rem 2rem;">
                        <div style="display: flex; flex-direction: column; align-items: center; gap: 1rem; color: var(--text-secondary);">
                            <span class="material-symbols-outlined" style="font-size: 64px; color: #95a5a6; opacity: 0.5;">search_off</span>
                            <div style="font-size: 1.25rem; font-weight: 600; color: var(--text-primary);">Aucun résultat</div>
                            <div style="font-size: 0.9rem; max-width: 400px; line-height: 1.5;">Aucun lien ne correspond aux critères de recherche actuels.</div>
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach($urls as $link): ?>
                <tr>
                    <?php foreach($selectedColumns as $col): ?>
                        <?php 
                        // Déterminer le type de colonne et afficher en conséquence
                        if(strpos($col, 'source_url') !== false || strpos($col, 'target_url') !== false): ?>
                            <td class="col-<?= $col ?>" style="max-width: 400px; position: relative;">
                                <div style="display: flex; align-items: center; overflow: hidden;">
                                    <?php if($copyUrl && $link->$col): ?>
                                    <span class="copy-path-btn" data-path="<?= htmlspecialchars(parse_url($link->$col, PHP_URL_PATH) ?: '/') ?>" title="Copier le chemin" style="cursor: pointer; color: var(--text-secondary); margin-right: 0.4rem; flex-shrink: 0;" onclick="event.preventDefault(); event.stopPropagation(); navigator.clipboard.writeText(this.dataset.path).then(() => { if(typeof showGlobalStatus === 'function') showGlobalStatus('Chemin copié', 'success'); })">
                                        <span class="material-symbols-outlined" style="font-size: 16px;">content_copy</span>
                                    </span>
                                    <?php endif; ?>
                                    <span class="url-clickable" data-url="<?= htmlspecialchars($link->$col ?? '') ?>" style="cursor: pointer; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; min-width: 0;">
                                        <?= htmlspecialchars($link->$col ?? '') ?>
                                    </span>
                                    <?php if($link->$col): ?>
                                    <a href="<?= htmlspecialchars($link->$col) ?>" target="_blank" rel="noopener noreferrer" title="Ouvrir l'URL dans un nouvel onglet" style="display: inline-flex; align-items: center; color: var(--text-secondary); text-decoration: none; margin-left: 0.5rem; flex-shrink: 0;">
                                        <span class="material-symbols-outlined" style="font-size: 16px;">open_in_new</span>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        <?php elseif(strpos($col, 'source_depth') !== false || strpos($col, 'target_depth') !== false): ?>
                            <td class="col-<?= $col ?>"><span class="badge badge-info"><?= $link->$col ?? 0 ?></span></td>
                        <?php elseif(strpos($col, 'source_code') !== false || strpos($col, 'target_code') !== false): ?>
                            <td class="col-<?= $col ?>">
                                <?php
                                $code = (int)($link->$col ?? 0);
                                $textColor = function_exists('getCodeColor') ? getCodeColor($code) : '#95a5a6';
                                $bgColor = function_exists('getCodeBackgroundColor') ? getCodeBackgroundColor($code, 0.3) : 'rgba(149, 165, 166, 0.3)';
                                // Utiliser getCodeDisplayValue pour afficher "JS Redirect" au lieu de 311
                                $displayValue = function_exists('getCodeDisplayValue') ? getCodeDisplayValue($code) : $code;
                                ?>
                                <span class="badge" style="background: <?= $bgColor ?>; color: <?= $textColor ?>; font-weight: 600;"><?= htmlspecialchars($displayValue) ?></span>
                            </td>
                        <?php elseif(strpos($col, 'source_category') !== false || strpos($col, 'target_category') !== false): ?>
                            <td class="col-<?= $col ?>">
                                <?php
                                $category = $link->$col ?? 'N/A';
                                $bgColor = getCategoryColor($category);
                                ?>
                                <span class="badge" style="background: <?= $bgColor ?>; color: #fff;">
                                    <?= htmlspecialchars($category) ?>
                                </span>
                            </td>
                        <?php elseif($col === 'anchor'): ?>
                            <td class="col-anchor" style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; background: #f8f9fa;" title="<?= htmlspecialchars($link->anchor ?? '') ?>">
                                <?= htmlspecialchars($link->anchor ?? '') ?>
                            </td>
                        <?php elseif($col === 'external'): ?>
                            <td class="col-external" style="text-align: center; background: #f8f9fa;">
                                <?= $link->external ? '<span class="badge badge-warning">Externe</span>' : '<span class="badge badge-info">Interne</span>' ?>
                            </td>
                        <?php elseif($col === 'nofollow'): ?>
                            <td class="col-nofollow" style="text-align: center; background: #f8f9fa;">
                                <span style="position: absolute; left: -9999px;"><?= $link->nofollow ? 'Non' : 'Oui' ?></span>
                                <?= $link->nofollow ? '<span class="material-symbols-outlined" style="color: #e74c3c; font-size: 1.2rem;">link_off</span>' : '<span class="material-symbols-outlined" style="color: #6bd899; font-size: 1.2rem;">link</span>' ?>
                            </td>
                        <?php elseif($col === 'type'): ?>
                            <td class="col-type" style="background: #f8f9fa;"><?= htmlspecialchars($link->type ?? '') ?></td>
                        <?php elseif(strpos($col, 'canonical_value') !== false || strpos($col, 'redirect_to') !== false): ?>
                            <td class="col-<?= $col ?>" style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($link->$col ?? '') ?>">
                                <?= htmlspecialchars($link->$col ?? '') ?>
                            </td>
                        <?php elseif(strpos($col, 'compliant') !== false || strpos($col, 'canonical') !== false || strpos($col, 'noindex') !== false || strpos($col, 'blocked') !== false || strpos($col, 'h1_multiple') !== false || strpos($col, 'headings_missing') !== false): ?>
                            <td class="col-<?= $col ?>" style="text-align: center;">
                                <?= ($link->$col ?? 0) ? '<span class="material-symbols-outlined" style="color: #6bd899; font-size: 1.2rem; opacity: 0.8;">check_circle</span>' : '<span class="material-symbols-outlined" style="color: #95a5a6; font-size: 1.2rem; opacity: 0.7;">cancel</span>' ?>
                            </td>
                        <?php elseif(strpos($col, '_pri') !== false): ?>
                            <td class="col-<?= $col ?>"><?= number_format(($link->$col ?? 0) * 100, 4) ?>%</td>
                        <?php elseif(strpos($col, 'response_time') !== false): ?>
                            <td class="col-<?= $col ?>"><?= round($link->$col ?? 0, 2) ?> ms</td>
                        <?php elseif(strpos($col, '_schemas') !== false): ?>
                            <?php
                            $schemasVal = $link->$col ?? '{}';
                            $schemasCount = 0;
                            if (!empty($schemasVal) && $schemasVal !== '{}') {
                                $schemasStr = trim($schemasVal, '{}');
                                if (!empty($schemasStr)) {
                                    $schemasCount = count(explode(',', $schemasStr));
                                }
                            }
                            ?>
                            <td class="col-<?= $col ?>" style="text-align: center;"><?= $schemasCount ?></td>
                        <?php elseif(strpos($col, 'title_status') !== false || strpos($col, 'h1_status') !== false || strpos($col, 'metadesc_status') !== false): ?>
                            <td class="col-<?= $col ?>" style="text-align: center;">
                                <?php
                                $status = $link->$col ?? '';
                                if($status === 'Unique') {
                                    echo '<span class="badge badge-success">Unique</span>';
                                } elseif($status === 'Duplicate') {
                                    echo '<span class="badge badge-warning">Duplicate</span>';
                                } elseif($status === 'Empty') {
                                    echo '<span class="badge badge-danger">Empty</span>';
                                } else {
                                    echo '<span style="color: #95a5a6;">—</span>';
                                }
                                ?>
                            </td>
                        <?php elseif(strpos($col, '_title') !== false || strpos($col, '_h1') !== false || strpos($col, '_meta_desc') !== false || strpos($col, 'redirect_to') !== false): ?>
                            <td class="col-<?= $col ?>" style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($link->$col ?? '') ?>">
                                <?= htmlspecialchars($link->$col ?? '') ?>
                            </td>
                        <?php elseif(strpos($col, 'word_count') !== false): ?>
                            <?php
                            $wc = $link->$col ?? 0;
                            // Couleurs selon tranches: Pauvre <=250, Moyen 250-500, Riche 500-1200, Premium 1200+
                            if ($wc <= 250) {
                                $wcColor = '#dc3545'; // Rouge - pauvre
                                $wcBg = 'rgba(220, 53, 69, 0.1)';
                            } elseif ($wc <= 500) {
                                $wcColor = '#fd7e14'; // Orange - moyen
                                $wcBg = 'rgba(253, 126, 20, 0.1)';
                            } elseif ($wc <= 1200) {
                                $wcColor = '#20c997'; // Vert clair - riche
                                $wcBg = 'rgba(32, 201, 151, 0.1)';
                            } else {
                                $wcColor = '#28a745'; // Vert foncé - premium
                                $wcBg = 'rgba(40, 167, 69, 0.1)';
                            }
                            ?>
                            <td class="col-<?= $col ?>" style="text-align: right;">
                                <span style="display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 0.85em; font-weight: 500; color: <?= $wcColor ?>; background: <?= $wcBg ?>; border: 1px solid <?= $wcColor ?>33;">
                                    <?= number_format($wc, 0, ',', ' ') ?>
                                </span>
                            </td>
                        <?php elseif(strpos($col, 'cstm_') !== false || strpos($col, 'extract_') !== false): ?>
                            <td class="col-<?= $col ?>" style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($link->$col ?? '') ?>">
                                <?= $link->$col ? htmlspecialchars($link->$col) : '<span style="color: #95A5A6;">—</span>' ?>
                            </td>
                        <?php else: ?>
                            <td class="col-<?= $col ?>"><?= htmlspecialchars($link->$col ?? '') ?></td>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination en bas -->
    <div style="display: flex; justify-content: space-between; align-items: center; padding: 1rem;">
        <!-- Gauche : Sélecteur nombre par page -->
        <div style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; color: var(--text-secondary);">
            <span>Afficher :</span>
            <div style="position: relative;">
                <button id="perPageBtn_<?= $componentId ?>" onclick="togglePerPageDropdown_<?= $componentId ?>()" style="padding: 0.4rem 0.8rem 0.4rem 0.6rem; border: 1px solid #dee2e6; border-radius: 4px; background: white; cursor: pointer; font-size: 0.85rem; display: flex; align-items: center; gap: 0.5rem; transition: all 0.2s ease; min-width: 60px;">
                    <span id="perPageValue_<?= $componentId ?>"><?= $perPage ?></span>
                    <span class="material-symbols-outlined" style="font-size: 16px; transition: transform 0.2s ease;">expand_more</span>
                </button>
                <div id="perPageDropdown_<?= $componentId ?>" class="per-page-dropdown-<?= $componentId ?>" style="display: none; position: absolute; left: 0; bottom: 100%; margin-bottom: 0.25rem; background: white; border: 1px solid #dee2e6; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); z-index: 1000; min-width: 80px;">
                    <div onclick="selectPerPage_<?= $componentId ?>(10)" style="padding: 0.5rem 0.75rem; cursor: pointer; transition: background 0.15s ease; <?= $perPage == 10 ? 'background: #f8f9fa; font-weight: 600;' : '' ?>" onmouseover="this.style.background='#f8f9fa'" onmouseout="this.style.background='<?= $perPage == 10 ? '#f8f9fa' : 'white' ?>'">10</div>
                    <div onclick="selectPerPage_<?= $componentId ?>(50)" style="padding: 0.5rem 0.75rem; cursor: pointer; transition: background 0.15s ease; <?= $perPage == 50 ? 'background: #f8f9fa; font-weight: 600;' : '' ?>" onmouseover="this.style.background='#f8f9fa'" onmouseout="this.style.background='<?= $perPage == 50 ? '#f8f9fa' : 'white' ?>'">50</div>
                    <div onclick="selectPerPage_<?= $componentId ?>(100)" style="padding: 0.5rem 0.75rem; cursor: pointer; transition: background 0.15s ease; <?= $perPage == 100 ? 'background: #f8f9fa; font-weight: 600;' : '' ?>" onmouseover="this.style.background='#f8f9fa'" onmouseout="this.style.background='<?= $perPage == 100 ? '#f8f9fa' : 'white' ?>'">100</div>
                    <div onclick="selectPerPage_<?= $componentId ?>(500)" style="padding: 0.5rem 0.75rem; cursor: pointer; transition: background 0.15s ease; <?= $perPage == 500 ? 'background: #f8f9fa; font-weight: 600;' : '' ?>" onmouseover="this.style.background='#f8f9fa'" onmouseout="this.style.background='<?= $perPage == 500 ? '#f8f9fa' : 'white' ?>'">500</div>
                </div>
            </div>
            <span>par page</span>
        </div>
        
        <!-- Droite : Pagination -->
        <div id="paginationBottom_<?= $componentId ?>" style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem; color: var(--text-secondary);">
            <span id="paginationInfoBottom_<?= $componentId ?>">Affichage de <?= number_format(($offset ?? 0) + 1) ?> à <?= number_format(min(($offset ?? 0) + $perPage, $totalResults ?? 0)) ?> sur <?= number_format($totalResults ?? 0) ?> Liens</span>
            <button onclick="changePage_<?= $componentId ?>(<?= max(1, $page_num - 1) ?>)" <?= $page_num <= 1 ? 'disabled' : '' ?> style="padding: 0.4rem; border: 1px solid #dee2e6; background: white; border-radius: 4px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s ease; <?= $page_num <= 1 ? 'opacity: 0.4; cursor: default;' : '' ?>" onmouseover="<?= $page_num > 1 ? 'this.style.background=\"#f8f9fa\"; this.style.borderColor=\"#adb5bd\"' : '' ?>" onmouseout="<?= $page_num > 1 ? 'this.style.background=\"white\"; this.style.borderColor=\"#dee2e6\"' : '' ?>">
                <span class="material-symbols-outlined" style="font-size: 20px;">chevron_left</span>
            </button>
            <button onclick="changePage_<?= $componentId ?>(<?= min($totalPages, $page_num + 1) ?>)" <?= $page_num >= $totalPages ? 'disabled' : '' ?> style="padding: 0.4rem; border: 1px solid #dee2e6; background: white; border-radius: 4px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s ease; <?= $page_num >= $totalPages ? 'opacity: 0.4; cursor: default;' : '' ?>" onmouseover="<?= $page_num < $totalPages ? 'this.style.background=\"#f8f9fa\"; this.style.borderColor=\"#adb5bd\"' : '' ?>" onmouseout="<?= $page_num < $totalPages ? 'this.style.background=\"white\"; this.style.borderColor=\"#dee2e6\"' : '' ?>">
                <span class="material-symbols-outlined" style="font-size: 20px;">chevron_right</span>
            </button>
        </div>
    </div>
</div>

<style>
/* Animation dropdown colonnes */
@keyframes slideInDown {
    from {
        transform: translateY(-10px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.column-dropdown-<?= $componentId ?>.show {
    animation: slideInDown 0.2s ease-out;
}

/* Style bouton colonnes */
.btn-column-selector:hover {
    background: #e9ecef !important;
    border-color: #adb5bd !important;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.btn-column-selector:active {
    transform: translateY(0);
    box-shadow: 0 1px 2px rgba(0,0,0,0.1);
}

/* Style dropdown perPage */
#perPageBtn_<?= $componentId ?>:hover {
    background: #f8f9fa !important;
    border-color: #adb5bd !important;
}

.per-page-dropdown-<?= $componentId ?>.show {
    animation: slideInUp 0.15s ease-out;
}

/* Style en-têtes de colonnes triables */
#urlTable_<?= $componentId ?> thead th {
    transition: background 0.15s ease;
}

#urlTable_<?= $componentId ?> thead th:hover {
    background: #f8f9fa;
}

@keyframes slideInUp {
    from {
        transform: translateY(5px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}
</style>

<script>
(function() {
    const componentId = '<?= $componentId ?>';
    const totalPages = <?= $totalPages ?>;
    const totalResults = <?= $totalResults ?>;
    const perPage = <?= $perPage ?>;
    let currentPage = <?= $page_num ?>;
    let currentPerPage = <?= $perPage ?>; // Variable mutable pour le perPage actuel
    let currentTotalPages = <?= $totalPages ?>; // Variable mutable pour totalPages
    let isLoading = false; // Anti-spam pagination
    
    // Fonction pour désactiver/activer les boutons de pagination
    function setPaginationLoading(loading) {
        isLoading = loading;
        const buttons = document.querySelectorAll('#paginationTop_' + componentId + ' button, #paginationBottom_' + componentId + ' button');
        buttons.forEach(btn => {
            btn.disabled = loading;
            btn.style.opacity = loading ? '0.5' : '1';
        });
    }

    // Fonction de changement du nombre d'éléments par page en AJAX
    window['changePerPage_' + componentId] = function(newPerPage) {
        const params = new URLSearchParams(window.location.search);
        const perPageParam = (componentId === 'main_explorer') ? 'per_page' : 'per_page_' + componentId;
        params.set(perPageParam, newPerPage);
        
        // Revenir à la page 1 quand on change le perPage
        const pageParam = (componentId === 'main_explorer') ? 'p' : 'p_' + componentId;
        params.set(pageParam, 1);
        
        // Mettre à jour l'URL sans recharger
        const newUrl = window.location.pathname + '?' + params.toString();
        window.history.pushState({}, '', newUrl);
        
        // Charger les données en AJAX
        fetch(newUrl, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newTableCard = doc.querySelector('#tableCard_' + componentId);
            
            if(newTableCard) {
                const currentTableCard = document.getElementById('tableCard_' + componentId);
                currentTableCard.innerHTML = newTableCard.innerHTML;
                
                // Réinitialiser currentPage à 1
                currentPage = 1;
                
                // Mettre à jour le perPage actuel
                currentPerPage = newPerPage;
                
                // Recalculer totalPages avec le nouveau perPage
                currentTotalPages = Math.ceil(totalResults / newPerPage);
                
                // Mettre à jour les boutons de pagination avec les bonnes valeurs
                attachPaginationHandlers();
                
                // Rafraîchir les handlers de la modale
                if(typeof refreshUrlModalHandlers === 'function') {
                    refreshUrlModalHandlers();
                }
            }
        })
        .catch(error => {
            console.error('Erreur lors du changement de perPage:', error);
        });
    };

    // Fonction de changement de page en AJAX
    window['changePage_' + componentId] = function(page) {
        if(page < 1 || page === currentPage || isLoading) return;
        
        currentPage = page;
        const params = new URLSearchParams(window.location.search);
        // Compatibilité avec anciens paramètres pour main_explorer
        const pageParam = (componentId === 'main_explorer') ? 'p' : 'p_' + componentId;
        params.set(pageParam, page);
        
        // Désactiver les boutons pendant le chargement
        setPaginationLoading(true);
        
        // Mettre à jour l'URL sans recharger
        const newUrl = window.location.pathname + '?' + params.toString();
        window.history.pushState({page: page}, '', newUrl);
        
        // Charger les données en AJAX
        fetch(newUrl, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            
            const newTableContainer = doc.querySelector('#tableContainer_' + componentId);
            if(newTableContainer) {
                document.querySelector('#tableContainer_' + componentId).innerHTML = newTableContainer.innerHTML;
            }
            
            // Récupérer le perPage actuel depuis l'URL (au cas où il a été changé)
            const perPageParam = (componentId === 'main_explorer') ? 'per_page' : 'per_page_' + componentId;
            const urlPerPage = parseInt(params.get(perPageParam)) || perPage;
            
            // Mettre à jour les variables globales
            currentPerPage = urlPerPage;
            currentTotalPages = Math.ceil(totalResults / currentPerPage);
            
            const offset = (page - 1) * currentPerPage;
            const start = offset + 1;
            const end = Math.min(offset + currentPerPage, totalResults);
            const paginationText = `Affichage de ${start.toLocaleString('fr-FR')} à ${end.toLocaleString('fr-FR')} sur ${totalResults.toLocaleString('fr-FR')} Liens`;
            
            document.getElementById('paginationInfo_' + componentId).textContent = paginationText;
            document.getElementById('paginationInfoBottom_' + componentId).textContent = paginationText;
            
            // Réactiver les boutons puis mettre à jour leur état (disabled si première/dernière page)
            isLoading = false;
            updatePaginationButtons(page, currentTotalPages);
            
            if(typeof refreshUrlModalHandlers === 'function') {
                refreshUrlModalHandlers();
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            isLoading = false;
            updatePaginationButtons(currentPage, currentTotalPages);
        });
    };

    function updatePaginationButtons(page, currentTotalPages = totalPages) {
        const topPrev = document.querySelector('#paginationTop_' + componentId + ' button:first-of-type');
        const topNext = document.querySelector('#paginationTop_' + componentId + ' button:last-of-type');
        
        if(topPrev) {
            topPrev.disabled = page <= 1;
            topPrev.style.opacity = page <= 1 ? '0.5' : '1';
            topPrev.style.cursor = page <= 1 ? 'default' : 'pointer';
            topPrev.setAttribute('onclick', `changePage_${componentId}(${Math.max(1, page - 1)})`);
        }
        
        if(topNext) {
            topNext.disabled = page >= currentTotalPages;
            topNext.style.opacity = page >= currentTotalPages ? '0.5' : '1';
            topNext.style.cursor = page >= currentTotalPages ? 'default' : 'pointer';
            topNext.setAttribute('onclick', `changePage_${componentId}(${Math.min(currentTotalPages, page + 1)})`);
        }
        
        const bottomPrev = document.querySelector('#paginationBottom_' + componentId + ' button:first-of-type');
        const bottomNext = document.querySelector('#paginationBottom_' + componentId + ' button:last-of-type');
        
        if(bottomPrev) {
            bottomPrev.disabled = page <= 1;
            bottomPrev.style.opacity = page <= 1 ? '0.5' : '1';
            bottomPrev.style.cursor = page <= 1 ? 'default' : 'pointer';
            bottomPrev.setAttribute('onclick', `changePage_${componentId}(${Math.max(1, page - 1)})`);
        }
        
        if(bottomNext) {
            bottomNext.disabled = page >= currentTotalPages;
            bottomNext.style.opacity = page >= currentTotalPages ? '0.5' : '1';
            bottomNext.style.cursor = page >= currentTotalPages ? 'default' : 'pointer';
            bottomNext.setAttribute('onclick', `changePage_${componentId}(${Math.min(currentTotalPages, page + 1)})`);
        }
    }

    // Attacher les événements aux boutons de pagination après chargement AJAX
    function attachPaginationHandlers() {
        const topPrev = document.querySelector('#paginationTop_' + componentId + ' button:first-of-type');
        const topNext = document.querySelector('#paginationTop_' + componentId + ' button:last-of-type');
        const bottomPrev = document.querySelector('#paginationBottom_' + componentId + ' button:first-of-type');
        const bottomNext = document.querySelector('#paginationBottom_' + componentId + ' button:last-of-type');
        
        // Supprimer les anciens onclick et attacher de nouveaux événements
        [topPrev, topNext, bottomPrev, bottomNext].forEach(btn => {
            if(btn) {
                btn.removeAttribute('onclick');
                // Supprimer les anciens listeners en clonant le bouton
                const newBtn = btn.cloneNode(true);
                btn.parentNode.replaceChild(newBtn, btn);
            }
        });
        
        // Réattacher les événements
        const newTopPrev = document.querySelector('#paginationTop_' + componentId + ' button:first-of-type');
        const newTopNext = document.querySelector('#paginationTop_' + componentId + ' button:last-of-type');
        const newBottomPrev = document.querySelector('#paginationBottom_' + componentId + ' button:first-of-type');
        const newBottomNext = document.querySelector('#paginationBottom_' + componentId + ' button:last-of-type');
        
        if(newTopPrev) {
            newTopPrev.addEventListener('click', () => {
                if(currentPage > 1) window['changePage_' + componentId](currentPage - 1);
            });
        }
        
        if(newTopNext) {
            newTopNext.addEventListener('click', () => {
                if(currentPage < currentTotalPages) window['changePage_' + componentId](currentPage + 1);
            });
        }
        
        if(newBottomPrev) {
            newBottomPrev.addEventListener('click', () => {
                if(currentPage > 1) window['changePage_' + componentId](currentPage - 1);
            });
        }
        
        if(newBottomNext) {
            newBottomNext.addEventListener('click', () => {
                if(currentPage < currentTotalPages) window['changePage_' + componentId](currentPage + 1);
            });
        }
        
        // Mettre à jour l'état des boutons
        updatePaginationButtons(currentPage, currentTotalPages);
    }

    // Copier le tableau
    window['copyTableToClipboard_' + componentId] = function(event) {
        const table = document.getElementById('urlTable_' + componentId);
        let text = '';
        
        // Fonction pour extraire le texte propre d'une cellule (sans icônes)
        function getCleanText(cell) {
            // Cloner la cellule pour ne pas modifier l'original
            const clone = cell.cloneNode(true);
            
            // Supprimer tous les éléments Material Symbols (icônes)
            const icons = clone.querySelectorAll('.material-symbols-outlined');
            icons.forEach(icon => icon.remove());
            
            // Pour les colonnes booléennes (compliant, canonical, etc.), on veut juste "Oui" ou "Non"
            // Si la cellule contient uniquement une icône (qu'on vient de supprimer), détecter la couleur
            if(cell.querySelector('.material-symbols-outlined')) {
                const icon = cell.querySelector('.material-symbols-outlined');
                const color = icon.style.color || window.getComputedStyle(icon).color;
                // Vert = Oui, Rouge = Non
                if(color.includes('46, 204, 113') || color.includes('#2ECC71') || color.includes('rgb(46, 204, 113)')) {
                    return 'Oui';
                } else if(color.includes('231, 76, 60') || color.includes('#E74C3C') || color.includes('rgb(231, 76, 60)')) {
                    return 'Non';
                }
            }
            
            // Récupérer le texte restant et nettoyer
            let cleanText = clone.textContent.trim();
            
            // Remplacer les multiples espaces par un seul
            cleanText = cleanText.replace(/\s+/g, ' ');
            
            // Remplacer le tiret "—" par une chaîne vide (valeurs vides)
            if(cleanText === '—') {
                return '';
            }
            
            return cleanText;
        }
        
        const headers = table.querySelectorAll('thead th');
        const headerTexts = Array.from(headers).map(th => getCleanText(th));
        text += headerTexts.join('\t') + '\n';
        
        const rows = table.querySelectorAll('tbody tr');
        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            const cellTexts = Array.from(cells).map(td => getCleanText(td));
            text += cellTexts.join('\t') + '\n';
        });
        
        navigator.clipboard.writeText(text).then(() => {
            showGlobalStatus('✓ Texte copié', 'success');
        }).catch(err => {
            console.error('Erreur:', err);
            showGlobalStatus('Erreur lors de la copie', 'error');
        });
    };


    // Toggle dropdown colonnes
    window['toggleColumnDropdown_' + componentId] = function() {
        const dropdown = document.getElementById('columnDropdown_' + componentId);
        if(dropdown.style.display === 'none' || dropdown.style.display === '') {
            dropdown.style.display = 'flex';
            dropdown.classList.add('show');
        } else {
            dropdown.style.display = 'none';
            dropdown.classList.remove('show');
        }
    };

    // Tout cocher / Tout décocher
    window['toggleAllColumns_' + componentId] = function(check) {
        const checkboxes = document.querySelectorAll('.column-checkbox-' + componentId);
        checkboxes.forEach(checkbox => {
            if(!checkbox.disabled) {  // Ne pas toucher à la checkbox URL (obligatoire)
                checkbox.checked = check;
            }
        });
    };

    // Fermer dropdown colonnes si clic ailleurs
    document.addEventListener('click', function(e) {
        const dropdown = document.getElementById('columnDropdown_' + componentId);
        const button = e.target.closest('button[onclick="toggleColumnDropdown_' + componentId + '()"]');
        
        if(!button && dropdown && !dropdown.contains(e.target)) {
            dropdown.style.display = 'none';
        }
    });

    // Toggle dropdown perPage
    window['togglePerPageDropdown_' + componentId] = function() {
        const dropdown = document.getElementById('perPageDropdown_' + componentId);
        const button = document.getElementById('perPageBtn_' + componentId);
        const icon = button.querySelector('.material-symbols-outlined');
        
        if(dropdown.style.display === 'none' || dropdown.style.display === '') {
            dropdown.style.display = 'block';
            dropdown.classList.add('show');
            icon.style.transform = 'rotate(180deg)';
        } else {
            dropdown.style.display = 'none';
            dropdown.classList.remove('show');
            icon.style.transform = 'rotate(0deg)';
        }
    };

    // Sélectionner perPage
    window['selectPerPage_' + componentId] = function(value) {
        window['changePerPage_' + componentId](value);
    };

    // Fermer dropdown perPage si clic ailleurs
    document.addEventListener('click', function(e) {
        const dropdown = document.getElementById('perPageDropdown_' + componentId);
        const button = document.getElementById('perPageBtn_' + componentId);
        
        if(!button.contains(e.target) && dropdown && !dropdown.contains(e.target)) {
            dropdown.style.display = 'none';
            dropdown.classList.remove('show');
            const icon = button.querySelector('.material-symbols-outlined');
            icon.style.transform = 'rotate(0deg)';
        }
    });

    // Tri par colonne en AJAX
    window['sortByColumn_' + componentId] = function(column) {
        const params = new URLSearchParams(window.location.search);
        
        // Paramètres de tri selon le composant
        const sortParam = (componentId === 'main_explorer') ? 'sort' : 'sort_' + componentId;
        const dirParam = (componentId === 'main_explorer') ? 'dir' : 'dir_' + componentId;
        
        // Récupérer le tri actuel
        const currentSort = params.get(sortParam);
        const currentDir = params.get(dirParam) || 'ASC';
        
        // Si on clique sur la même colonne, inverser la direction
        if(currentSort === column) {
            params.set(dirParam, currentDir === 'ASC' ? 'DESC' : 'ASC');
        } else {
            // Nouvelle colonne : tri ASC par défaut
            params.set(sortParam, column);
            params.set(dirParam, 'ASC');
        }
        
        // Revenir à la page 1 quand on change le tri
        const pageParam = (componentId === 'main_explorer') ? 'p' : 'p_' + componentId;
        params.set(pageParam, 1);
        
        // Mettre à jour l'URL sans recharger
        const newUrl = window.location.pathname + '?' + params.toString();
        window.history.pushState({}, '', newUrl);
        
        // Charger les données en AJAX
        fetch(newUrl, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newTableCard = doc.querySelector('#tableCard_' + componentId);
            
            if(newTableCard) {
                const currentTableCard = document.getElementById('tableCard_' + componentId);
                currentTableCard.innerHTML = newTableCard.innerHTML;
                
                // Rafraîchir les handlers de la modale
                if(typeof refreshUrlModalHandlers === 'function') {
                    refreshUrlModalHandlers();
                }
            }
        })
        .catch(error => {
            console.error('Erreur lors du tri:', error);
        });
    };

    // Appliquer colonnes en AJAX
    window['applyColumns_' + componentId] = function() {
        const checkboxes = document.querySelectorAll('.column-checkbox-' + componentId + ':checked');
        const columns = Array.from(checkboxes).map(cb => cb.value);
        
        const params = new URLSearchParams(window.location.search);
        // Compatibilité avec anciens paramètres pour main_explorer
        const columnsParam = (componentId === 'main_explorer') ? 'columns' : 'columns_' + componentId;
        params.set(columnsParam, columns.join(','));
        
        // Mettre à jour l'URL sans recharger
        const newUrl = window.location.pathname + '?' + params.toString();
        window.history.pushState({}, '', newUrl);
        
        // Charger les données en AJAX
        fetch(newUrl, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.text())
        .then(html => {
            // Parser la réponse et extraire le contenu du tableau
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newTableCard = doc.querySelector('#tableCard_' + componentId);
            
            if(newTableCard) {
                const currentTableCard = document.getElementById('tableCard_' + componentId);
                
                // Garder le dropdown ouvert si nécessaire
                const wasOpen = document.getElementById('columnDropdown_' + componentId)?.style.display === 'flex';
                
                // Remplacer le contenu
                currentTableCard.innerHTML = newTableCard.innerHTML;
                
                // Fermer le dropdown après remplacement
                const newDropdown = document.getElementById('columnDropdown_' + componentId);
                if(newDropdown) {
                    newDropdown.style.display = 'none';
                }
                
                // Réactiver les event handlers de la modale
                if(typeof refreshUrlModalHandlers === 'function') {
                    refreshUrlModalHandlers();
                }
                
                // Réinitialiser la synchronisation des scrollbars après le rechargement AJAX
                if(typeof window['initScrollbarSync_' + componentId] === 'function') {
                    window['initScrollbarSync_' + componentId]();
                }
                
                // Afficher un message de succès
                showGlobalStatus('✓ Colonnes mises à jour', 'success');
            }
        })
        .catch(error => {
            console.error('Erreur lors du chargement:', error);
            alert('Erreur lors de la mise à jour des colonnes');
        });
    };

    // Export CSV
    window['exportToCSV_' + componentId] = function() {
        const selectedCols = [];
        document.querySelectorAll('.column-checkbox-' + componentId + ':checked').forEach(cb => {
            selectedCols.push(cb.value);
        });
        
        // Récupérer les filtres et recherche depuis l'URL
        const params = new URLSearchParams(window.location.search);
        const filters = params.get('filters') || '';
        const search = params.get('search') || '';
        
        document.getElementById('exportForm_' + componentId).querySelector('[name="filters"]').value = filters;
        document.getElementById('exportForm_' + componentId).querySelector('[name="search"]').value = search;
        document.getElementById('exportColumns_' + componentId).value = JSON.stringify(selectedCols);
        document.getElementById('exportForm_' + componentId).submit();
    };

    // Stocker les références aux handlers pour pouvoir les retirer
    window['scrollHandlers_' + componentId] = null;

    // Fonction pour initialiser/réinitialiser la synchronisation des scrollbars
    window['initScrollbarSync_' + componentId] = function() {
        const topScrollbar = document.getElementById('topScrollbar_' + componentId);
        const tableContainer = document.getElementById('tableContainer_' + componentId);
        const topScrollbarContent = document.getElementById('topScrollbarContent_' + componentId);
        const table = document.getElementById('urlTable_' + componentId);

        if (!topScrollbar || !tableContainer || !topScrollbarContent || !table) {
            return;
        }

        // Synchroniser la largeur du contenu de la barre de scroll du haut
        topScrollbarContent.style.width = table.offsetWidth + 'px';
        
        // Synchroniser après un court délai (pour s'assurer que le DOM est complètement rendu)
        setTimeout(function() {
            topScrollbarContent.style.width = table.offsetWidth + 'px';
        }, 100);

        // Retirer les anciens handlers s'ils existent
        if (window['scrollHandlers_' + componentId]) {
            const oldHandlers = window['scrollHandlers_' + componentId];
            const oldTop = document.getElementById('topScrollbar_' + componentId);
            const oldTable = document.getElementById('tableContainer_' + componentId);
            if (oldTop && oldHandlers.topHandler) {
                oldTop.removeEventListener('scroll', oldHandlers.topHandler);
            }
            if (oldTable && oldHandlers.tableHandler) {
                oldTable.removeEventListener('scroll', oldHandlers.tableHandler);
            }
        }

        // Créer les nouveaux handlers
        const topHandler = function() {
            const tc = document.getElementById('tableContainer_' + componentId);
            if (tc) tc.scrollLeft = this.scrollLeft;
        };

        const tableHandler = function() {
            const ts = document.getElementById('topScrollbar_' + componentId);
            if (ts) ts.scrollLeft = this.scrollLeft;
        };

        // Ajouter les event listeners
        topScrollbar.addEventListener('scroll', topHandler);
        tableContainer.addEventListener('scroll', tableHandler);

        // Stocker les références
        window['scrollHandlers_' + componentId] = {
            topHandler: topHandler,
            tableHandler: tableHandler
        };
    };

    // Initialiser au chargement
    window['initScrollbarSync_' + componentId]();

    // Synchroniser lors du redimensionnement de la fenêtre
    window.addEventListener('resize', function() {
        const topScrollbarContent = document.getElementById('topScrollbarContent_' + componentId);
        const table = document.getElementById('urlTable_' + componentId);
        if (table && topScrollbarContent) {
            topScrollbarContent.style.width = table.offsetWidth + 'px';
        }
    });

    // Handler pour copier le chemin - utiliser la délégation d'événements sur document
    window['attachCopyHandlers_' + componentId] = function() {
        // Utiliser la délégation d'événements sur le document pour gérer les clics même après AJAX
        document.addEventListener('click', function(e) {
            const copyBtn = e.target.closest('.copy-path-btn');
            if (copyBtn) {
                // Vérifier que le bouton est dans notre tableau
                const tableCard = document.getElementById('tableCard_' + componentId);
                if (tableCard && tableCard.contains(copyBtn)) {
                    e.preventDefault();
                    e.stopPropagation();
                    const path = copyBtn.dataset.path;
                    if (path) {
                        navigator.clipboard.writeText(path).then(() => {
                            // Afficher notification
                            if (typeof showGlobalStatus === 'function') {
                                showGlobalStatus('Chemin copié : ' + path, 'success');
                            } else {
                                // Fallback notification
                                const notif = document.createElement('div');
                                notif.style.cssText = 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#2ecc71;color:#fff;padding:10px 20px;border-radius:6px;z-index:10000;font-size:14px;';
                                notif.textContent = 'Chemin copié : ' + path;
                                document.body.appendChild(notif);
                                setTimeout(() => notif.remove(), 2000);
                            }
                        }).catch(err => {
                            console.error('Erreur copie:', err);
                            if (typeof showGlobalStatus === 'function') {
                                showGlobalStatus('Erreur lors de la copie', 'error');
                            }
                        });
                    }
                }
            }
        });
    };
    
    // Initialiser une seule fois
    if (!window['copyHandlersAttached_' + componentId]) {
        window['attachCopyHandlers_' + componentId]();
        window['copyHandlersAttached_' + componentId] = true;
    }

    // Fonction pour afficher la modale de scope (utilise le composant partagé)
    window['showTableScope_' + componentId] = function() {
        if (typeof openScopeModal === 'function') {
            openScopeModal({
                title: <?= json_encode($componentTitle, JSON_UNESCAPED_UNICODE) ?>,
                scopeItems: <?= json_encode($scopeItems, JSON_UNESCAPED_UNICODE) ?>,
                sqlQuery: <?= json_encode($tableSqlQuery, JSON_UNESCAPED_UNICODE) ?>
            });
        }
    };
})();
</script>
