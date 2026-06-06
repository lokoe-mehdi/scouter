<?php
/**
 * URL Explorer (PostgreSQL)
 * $crawlId est défini dans dashboard.php
 */

// AI-assisted filter generation availability — same pattern as the SQL Explorer.
$urlExplorerAiConfigured = false;
try {
    $_ueAiKey   = \App\Settings\AppSettings::get('ai.openrouter.api_key');
    $_ueAiModel = \App\Settings\AppSettings::get('ai.openrouter.model_light');
    $urlExplorerAiConfigured = $_ueAiKey !== null && $_ueAiKey !== '' && $_ueAiModel !== null && $_ueAiModel !== '';
} catch (\Throwable $e) {
    $urlExplorerAiConfigured = false;
}
// AI is reserved for admins + editors. Viewers (read-only) must not see any AI
// control at all — the buttons below are wrapped in this check.
$aiRoleAllowed = \App\AI\BudgetService::isAiEligibleRole($_SESSION['role'] ?? null);

// Bulk AI generation writes data — it is restricted to the crawl OWNER (and
// admins). A user with a merely SHARED crawl must not see the bulk button.
$_ueIsAdmin = (($_SESSION['role'] ?? '') === 'admin');
$_ueUserId  = (int)($_SESSION['user_id'] ?? 0);
$ueOwnsCrawl = $_ueIsAdmin;
if (!$ueOwnsCrawl && !empty($crawlId)) {
    try {
        $_ueOwnStmt = $pdo->prepare("SELECT p.user_id FROM crawls c JOIN projects p ON p.id = c.project_id WHERE c.id = :cid");
        $_ueOwnStmt->execute([':cid' => (int)$crawlId]);
        $ueOwnsCrawl = ((int)$_ueOwnStmt->fetchColumn() === $_ueUserId);
    } catch (\Throwable $e) {
        $ueOwnsCrawl = false;
    }
}
$canBulkGenerate = $aiRoleAllowed && $ueOwnsCrawl;

// Récupération des filtres. Au premier chargement (aucun filtre dans l'URL) on
// active par défaut un filtre `external = false` pour ne montrer que les URLs
// internes — l'utilisateur peut le retirer pour voir les externes.
$filtersInUrl = isset($_GET['filters']);
$defaultFilterGroups = [[
    'type'  => 'group',
    'logic' => 'AND',
    'items' => [['field' => 'external', 'operator' => '=', 'value' => 'false']],
]];
$filters = $filtersInUrl ? json_decode($_GET['filters'], true) : $defaultFilterGroups;
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Récupération des catégories disponibles
$stmt = $pdo->prepare("SELECT id, cat FROM crawl_categories WHERE project_id = :project_id ORDER BY cat");
$stmt->execute([':project_id' => $crawlRecord->project_id]);
$availableCategories = $stmt->fetchAll(PDO::FETCH_OBJ);

// Récupération des types de schemas disponibles
$stmt = $pdo->prepare("SELECT DISTINCT schema_type FROM page_schemas WHERE crawl_id = :crawl_id ORDER BY schema_type");
$stmt->execute([':crawl_id' => $crawlId]);
$availableSchemas = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Récupération des extracteurs custom + détection auto du type (numérique vs texte)
// via sampling SQL. On liste TOUS les noms d'extracteurs présents (même si toutes
// leurs valeurs sont vides) ; le type est numérique uniquement si au moins une
// valeur non-vide existe ET que toutes les valeurs non-vides sont numériques.
$availableExtractors = [];
try {
    $stmt = $pdo->prepare("
        WITH samples AS (
            SELECT extracts FROM pages
            WHERE crawl_id = :crawl_id AND extracts IS NOT NULL
              AND extracts != '{}'::jsonb AND in_crawl = TRUE
            LIMIT 500
        )
        SELECT j.key,
               COUNT(*) FILTER (WHERE j.value IS NOT NULL AND j.value != '') AS non_empty_count,
               COUNT(*) FILTER (
                   WHERE j.value IS NOT NULL AND j.value != ''
                     AND j.value !~ '^-?[0-9]+(\\.[0-9]+)?$'
               ) AS non_numeric_count
        FROM samples s, jsonb_each_text(s.extracts) j
        GROUP BY j.key
        ORDER BY j.key
    ");
    $stmt->execute([':crawl_id' => $crawlId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $isNumeric = ((int)$row['non_empty_count'] > 0) && ((int)$row['non_numeric_count'] === 0);
        $availableExtractors[] = [
            'key'  => $row['key'],
            'type' => $isNumeric ? 'number' : 'text',
        ];
    }
} catch (Exception $e) {
    // Pas d'extracteurs ou colonne extracts absente — silencieux
}

// Map key => type pour accès rapide dans buildFilterConditions (via $GLOBALS)
$GLOBALS['extractorTypes'] = [];
foreach ($availableExtractors as $extr) {
    $GLOBALS['extractorTypes'][$extr['key']] = $extr['type'];
}

// Détection auto des clés générées par le Bulk AI Generator. Même esprit que
// les extracts, mais on exploite le typage natif JSONB (`jsonb_typeof`) — pas
// besoin de sampler les valeurs pour deviner le type, JSONB nous le dit.
// La majorité dominante du type gagne ; si > 5% des values ont un type
// "inattendu" (bug d'IA), on tombe en `text` par sécurité pour l'UI.
// ClickHouse-backed crawls keep generation in page_generation (Map(String,String)),
// not in pages.generation (JSONB) — and the PG pages table is purged once migrated.
// The jsonb_each() probe below only works on PG, so route CH crawls through the
// shared CH discovery (same key+type contract) or generated columns never appear.
$availableGenerations = [];
if (\App\Database\CrawlStore::usesClickHouse((int)$crawlId)) {
    $availableGenerations = \App\Http\Controllers\AIUrlFiltersController::fetchGenerationsCH((int)$crawlId, '[UrlExplorer]');
} else {
    try {
        $stmt = $pdo->prepare("
            WITH samples AS (
                SELECT generation FROM pages
                WHERE crawl_id = :crawl_id AND generation IS NOT NULL
                  AND jsonb_typeof(generation) = 'object'
                LIMIT 500
            )
            SELECT j.key, jsonb_typeof(j.value) AS jtype, COUNT(*) AS n
            FROM samples s, jsonb_each(s.generation) j
            GROUP BY j.key, jsonb_typeof(j.value)
        ");
        $stmt->execute([':crawl_id' => $crawlId]);
        // Group by key, pick dominant type if > 95% of samples.
        $byKey = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $k = $row['key'];
            if (!isset($byKey[$k])) $byKey[$k] = [];
            $byKey[$k][$row['jtype']] = (int)$row['n'];
        }
        foreach ($byKey as $key => $typeCounts) {
            $total = array_sum($typeCounts);
            arsort($typeCounts);
            $dominantType = (string)array_key_first($typeCounts);
            $dominantPct  = $total > 0 ? $typeCounts[$dominantType] / $total : 0;
            // Map JSONB type to our UI type vocabulary (same as extracts : 'number' | 'text').
            if ($dominantType === 'number' && $dominantPct >= 0.95) {
                $uiType = 'number';
            } elseif ($dominantType === 'boolean' && $dominantPct >= 0.95) {
                $uiType = 'boolean';
            } else {
                $uiType = 'text';
            }
            $availableGenerations[] = ['key' => $key, 'type' => $uiType];
        }
        // Stable order.
        usort($availableGenerations, fn($a, $b) => strcmp($a['key'], $b['key']));
    } catch (Exception $e) {
        // Generation column absent or no data — silent.
    }
}

// Map key => type pour utilisation dans url-table.php (filtres + tri).
$GLOBALS['generationTypes'] = [];
foreach ($availableGenerations as $g) {
    $GLOBALS['generationTypes'][$g['key']] = $g['type'];
}

// Construction de la clause WHERE (PostgreSQL)
$whereConditions = ["1=1"];
$params = [];

// Recherche globale
if(!empty($search)) {
    $whereConditions[] = "c.url LIKE :search";
    $params[':search'] = '%'.$search.'%';
}

// Fonction récursive pour construire les conditions SQL à partir des groupes
function buildFilterConditions($items, &$params, &$paramCounter = 0) {
    static $counter = 0;
    if($paramCounter === 0) {
        $paramCounter = &$counter;
    }
    
    $conditions = [];
    
    foreach($items as $item) {
        if(isset($item['type']) && $item['type'] === 'group') {
            // Groupe récursif
            $subConditions = buildFilterConditions($item['items'], $params, $paramCounter);
            if(!empty($subConditions)) {
                $conditions[] = '(' . implode(' ' . $item['logic'] . ' ', $subConditions) . ')';
            }
        } else {
            // Condition simple
            $field = isset($item['field']) ? $item['field'] : '';
            $operator = isset($item['operator']) ? $item['operator'] : '=';
            $value = isset($item['value']) ? $item['value'] : '';
            $condition = '';
            
            switch($field) {
                case 'url':
                    $paramName = ':url_' . $paramCounter++;
                    if($operator === 'contains') {
                        $condition = "c.url LIKE {$paramName}";
                        $params[$paramName] = '%' . $value . '%';
                    } elseif($operator === 'not_contains') {
                        $condition = "c.url NOT LIKE {$paramName}";
                        $params[$paramName] = '%' . $value . '%';
                    } elseif($operator === 'regex') {
                        // PostgreSQL utilise ~ pour les regex
                        $condition = "c.url ~ {$paramName}";
                        $params[$paramName] = $value;
                    } elseif($operator === 'not_regex') {
                        $condition = "c.url !~ {$paramName}";
                        $params[$paramName] = $value;
                    }
                    break;
                    
                case 'category':
                    if(!empty($value) && is_array($value)) {
                        // Filter by the live category NAME (no cat_id). The dropdown
                        // sends category ids; resolve them to names via $categoriesMap.
                        $catMap = $GLOBALS['categoriesMap'] ?? [];
                        $placeholders = [];
                        foreach($value as $v) {
                            $name = isset($catMap[(int)$v]) ? $catMap[(int)$v]['cat'] : (string)$v;
                            $paramName = ':cat_' . $paramCounter++;
                            $placeholders[] = $paramName;
                            $params[$paramName] = $name;
                        }
                        if($operator === 'not_in') {
                            $condition = "(c.category NOT IN (" . implode(',', $placeholders) . ") OR c.category = '')";
                        } else {
                            $condition = 'c.category IN (' . implode(',', $placeholders) . ')';
                        }
                    }
                    break;
                    
                case 'depth':
                case 'inlinks':
                case 'outlinks':
                case 'response_time':
                case 'word_count':
                    $paramName = ':param_' . $paramCounter++;
                    $sqlOperator = '=';
                    if($operator === '>') $sqlOperator = '>';
                    elseif($operator === '<') $sqlOperator = '<';
                    elseif($operator === '>=') $sqlOperator = '>=';
                    elseif($operator === '<=') $sqlOperator = '<=';
                    elseif($operator === '!=') $sqlOperator = '!=';
                    
                    $condition = "c.{$field} {$sqlOperator} {$paramName}";
                    $params[$paramName] = intval($value);
                    break;
                    
                case 'code':
                    // Mode "valeur" - opérateur numérique
                    if(in_array($operator, ['=', '>', '<', '>=', '<=', '!='])) {
                        $paramName = ':code_' . $paramCounter++;
                        $sqlOperator = $operator;
                        $condition = "c.code {$sqlOperator} {$paramName}";
                        $params[$paramName] = intval($value);
                    }
                    // Mode "groupe" - plage de codes
                    else {
                        $groups = is_array($value) ? $value : [$value];
                        $groupConditions = [];
                        foreach($groups as $g) {
                            if($g === '1xx') $groupConditions[] = "(c.code >= 100 AND c.code <= 199)";
                            elseif($g === '2xx') $groupConditions[] = "(c.code >= 200 AND c.code <= 299)";
                            elseif($g === '3xx') $groupConditions[] = "(c.code >= 300 AND c.code <= 399)";
                            elseif($g === '4xx') $groupConditions[] = "(c.code >= 400 AND c.code <= 499)";
                            elseif($g === '5xx') $groupConditions[] = "(c.code >= 500 AND c.code <= 599)";
                            elseif($g === 'other') $groupConditions[] = "(c.code < 100 OR c.code >= 600)";
                        }
                        if(!empty($groupConditions)) {
                            $condition = '(' . implode(' OR ', $groupConditions) . ')';
                        }
                    }
                    break;
                    
                case 'schemas':
                    // Mode "count" - filtrer par nombre de schemas
                    if(in_array($operator, ['=', '>', '<', '>=', '<='])) {
                        $paramName = ':schemas_count_' . $paramCounter++;
                        $sqlOperator = $operator;
                        $condition = "COALESCE(array_length(c.schemas, 1), 0) {$sqlOperator} {$paramName}";
                        $params[$paramName] = intval($value);
                    }
                    // Mode "contains" - filtrer par types spécifiques
                    elseif($operator === 'contains' && is_array($value) && !empty($value)) {
                        $schemaConditions = [];
                        foreach($value as $schemaType) {
                            $paramName = ':schema_' . $paramCounter++;
                            $schemaConditions[] = "{$paramName} = ANY(c.schemas)";
                            $params[$paramName] = $schemaType;
                        }
                        $condition = '(' . implode(' OR ', $schemaConditions) . ')';
                    }
                    elseif($operator === 'not_contains' && is_array($value) && !empty($value)) {
                        $schemaConditions = [];
                        foreach($value as $schemaType) {
                            $paramName = ':schema_' . $paramCounter++;
                            $schemaConditions[] = "NOT ({$paramName} = ANY(c.schemas))";
                            $params[$paramName] = $schemaType;
                        }
                        $condition = '(' . implode(' AND ', $schemaConditions) . ')';
                    }
                    break;
                    
                case 'compliant':
                case 'canonical':
                case 'noindex':
                case 'nofollow':
                case 'blocked':
                case 'h1_multiple':
                case 'headings_missing':
                case 'external':
                case 'in_sitemap':
                case 'is_html':
                case 'crawled':
                    $condition = "c.{$field} = " . ($value === 'true' ? 'true' : 'false');
                    break;

                case 'out_of_scope':
                    // Filtre composé : URLs internes découvertes mais non visitées
                    // (ni externes, ni bloquées, ni crawlées) — alignée sur la même
                    // définition que la vue Accessibility.
                    $expr = '(c.external = false AND c.blocked = false AND c.crawled = false)';
                    $condition = ($value === 'true') ? $expr : "NOT $expr";
                    break;

                case 'pri':
                    // PageRank : valeur flottante
                    $paramName = ':pri_' . $paramCounter++;
                    $sqlOperator = in_array($operator, ['=', '>', '<', '>=', '<=', '!=']) ? $operator : '=';
                    $condition = "c.pri {$sqlOperator} {$paramName}";
                    $params[$paramName] = floatval($value);
                    break;

                case 'content_type':
                case 'redirect_to':
                case 'canonical_value':
                case 'domain':
                    // Filtres texte simples (contains / not_contains / regex / not_regex)
                    $paramName = ':txt_' . $paramCounter++;
                    if($operator === 'contains') {
                        $condition = "c.{$field} ILIKE {$paramName}";
                        $params[$paramName] = '%' . $value . '%';
                    } elseif($operator === 'not_contains') {
                        $condition = "(c.{$field} NOT ILIKE {$paramName} OR c.{$field} IS NULL)";
                        $params[$paramName] = '%' . $value . '%';
                    } elseif($operator === 'regex') {
                        $condition = "c.{$field} ~* {$paramName}";
                        $params[$paramName] = $value;
                    } elseif($operator === 'not_regex') {
                        $condition = "(c.{$field} !~* {$paramName} OR c.{$field} IS NULL)";
                        $params[$paramName] = $value;
                    }
                    break;

                case 'title':
                case 'h1':
                case 'metadesc':
                    $colName = ($field === 'meta_desc') ? 'metadesc' : $field;
                    
                    // Mode "valeur" (texte) - filtrer sur le contenu
                    if(in_array($operator, ['contains', 'not_contains', 'regex', 'not_regex'])) {
                        $paramName = ':seo_' . $paramCounter++;
                        if($operator === 'contains') {
                            $condition = "c.{$colName} ILIKE {$paramName}";
                            $params[$paramName] = '%' . $value . '%';
                        } elseif($operator === 'not_contains') {
                            $condition = "(c.{$colName} NOT ILIKE {$paramName} OR c.{$colName} IS NULL)";
                            $params[$paramName] = '%' . $value . '%';
                        } elseif($operator === 'regex') {
                            $condition = "c.{$colName} ~* {$paramName}";
                            $params[$paramName] = $value;
                        } elseif($operator === 'not_regex') {
                            $condition = "(c.{$colName} !~* {$paramName} OR c.{$colName} IS NULL)";
                            $params[$paramName] = $value;
                        }
                    }
                    // Mode "état" - filtrer sur le statut (unique/empty/duplicate)
                    elseif(is_array($value)) {
                        $statusConditions = [];
                        foreach($value as $v) {
                            if(in_array($v, ['empty', 'duplicate', 'unique'])) {
                                $statusConditions[] = "c.{$colName}_status = '{$v}'";
                            }
                        }
                        if(!empty($statusConditions)) {
                            $condition = '(' . implode(' OR ', $statusConditions) . ')';
                        }
                    } else {
                        if(in_array($value, ['empty', 'duplicate', 'unique'])) {
                            $condition = "c.{$colName}_status = '{$value}'";
                        }
                    }
                    break;

                default:
                    // Filtres dynamiques sur les extracteurs custom (`extract_<key>`).
                    // Le type (number vs text) est déterminé en amont par sampling SQL
                    // et exposé via $GLOBALS['extractorTypes'].
                    if (strpos($field, 'extract_') === 0) {
                        $extKey = substr($field, 8);
                        $jsonAccess = "c.extracts->>'" . addslashes($extKey) . "'";
                        $extType = $GLOBALS['extractorTypes'][$extKey] ?? 'text';

                        if ($extType === 'number') {
                            $sqlOp = in_array($operator, ['=', '>', '<', '>=', '<=', '!=']) ? $operator : '=';
                            $paramName = ':ext_' . $paramCounter++;
                            // Cast en NUMERIC pour le test ; les NULL sont implicitement exclus.
                            $condition = "({$jsonAccess}) ~ '^-?[0-9]+(\\.[0-9]+)?$' AND ({$jsonAccess})::numeric {$sqlOp} {$paramName}";
                            $params[$paramName] = floatval($value);
                        } else {
                            $paramName = ':ext_' . $paramCounter++;
                            if ($operator === 'contains') {
                                $condition = "{$jsonAccess} ILIKE {$paramName}";
                                $params[$paramName] = '%' . $value . '%';
                            } elseif ($operator === 'not_contains') {
                                $condition = "({$jsonAccess} NOT ILIKE {$paramName} OR {$jsonAccess} IS NULL)";
                                $params[$paramName] = '%' . $value . '%';
                            } elseif ($operator === 'regex') {
                                $condition = "{$jsonAccess} ~* {$paramName}";
                                $params[$paramName] = $value;
                            } elseif ($operator === 'not_regex') {
                                $condition = "({$jsonAccess} !~* {$paramName} OR {$jsonAccess} IS NULL)";
                                $params[$paramName] = $value;
                            }
                        }
                    }
                    // Filtres dynamiques sur les générations IA (`generation_<key>`).
                    // Le type vient de $GLOBALS['generationTypes'] (détection auto
                    // via jsonb_typeof). Support text / number / boolean.
                    if (strpos($field, 'generation_') === 0) {
                        $genKey = substr($field, 11);
                        $jsonAccess = "c.generation->>'" . addslashes($genKey) . "'";
                        $genType = $GLOBALS['generationTypes'][$genKey] ?? 'text';

                        if ($genType === 'number') {
                            $sqlOp = in_array($operator, ['=', '>', '<', '>=', '<=', '!=']) ? $operator : '=';
                            $paramName = ':gen_' . $paramCounter++;
                            $condition = "({$jsonAccess}) ~ '^-?[0-9]+(\\.[0-9]+)?$' AND ({$jsonAccess})::numeric {$sqlOp} {$paramName}";
                            $params[$paramName] = floatval($value);
                        } elseif ($genType === 'boolean') {
                            // JSONB stocke `true`/`false` ; ->>texte donne "true"/"false".
                            $paramName = ':gen_' . $paramCounter++;
                            $boolValue = ($value === true || $value === 'true' || $value === 1 || $value === '1') ? 'true' : 'false';
                            $condition = "{$jsonAccess} = {$paramName}";
                            $params[$paramName] = $boolValue;
                        } else {
                            $paramName = ':gen_' . $paramCounter++;
                            if ($operator === 'contains') {
                                $condition = "{$jsonAccess} ILIKE {$paramName}";
                                $params[$paramName] = '%' . $value . '%';
                            } elseif ($operator === 'not_contains') {
                                $condition = "({$jsonAccess} NOT ILIKE {$paramName} OR {$jsonAccess} IS NULL)";
                                $params[$paramName] = '%' . $value . '%';
                            } elseif ($operator === 'regex') {
                                $condition = "{$jsonAccess} ~* {$paramName}";
                                $params[$paramName] = $value;
                            } elseif ($operator === 'not_regex') {
                                $condition = "({$jsonAccess} !~* {$paramName} OR {$jsonAccess} IS NULL)";
                                $params[$paramName] = $value;
                            }
                        }
                    }
                    break;
            }
            
            if(!empty($condition)) {
                $conditions[] = $condition;
            }
        }
    }
    
    return $conditions;
}

// Application des filtres avancés
if(!empty($filters)) {
    $groupConditions = [];
    foreach($filters as $index => $filter) {
        if(isset($filter['type']) && $filter['type'] === 'group') {
            $conditions = buildFilterConditions($filter['items'], $params);
            if(!empty($conditions)) {
                $groupCondition = '(' . implode(' ' . $filter['logic'] . ' ', $conditions) . ')';
                
                // Ajouter avec la logique inter-groupe si ce n'est pas le premier
                if($index > 0 && isset($filter['interGroupLogic'])) {
                    $groupConditions[] = [
                        'condition' => $groupCondition,
                        'logic' => $filter['interGroupLogic']
                    ];
                } else {
                    $groupConditions[] = [
                        'condition' => $groupCondition,
                        'logic' => 'AND'
                    ];
                }
            }
        }
    }
    
    if(!empty($groupConditions)) {
        $finalCondition = $groupConditions[0]['condition'];
        for($i = 1; $i < count($groupConditions); $i++) {
            $finalCondition .= ' ' . $groupConditions[$i]['logic'] . ' ' . $groupConditions[$i]['condition'];
        }
        $whereConditions[] = '(' . $finalCondition . ')';
    }
}

$whereClause = implode(' AND ', $whereConditions);

// Colonnes sélectionnées par défaut pour le composant
// Au premier chargement on applique aussi par défaut un filtre external=false :
// on ajoute donc la colonne `external` aux colonnes affichées par défaut pour
// rester cohérent (sinon l'utilisateur voit le filtre mais pas la donnée filtrée).
$selectedColumns = isset($_GET['columns']) ? explode(',', $_GET['columns']) : ['url', 'depth', 'code', 'category', 'inlinks', 'compliant', 'external', 'title'];

// Auto-activation des colonnes via ?add_cols=col1,col2 — utilisé par la modale
// Bulk AI Generator pour activer immédiatement les `generation_xxx` qu'elle
// vient de créer. Append aux colonnes courantes sans rien remplacer.
if (isset($_GET['add_cols']) && $_GET['add_cols'] !== '') {
    $toAdd = array_filter(array_map('trim', explode(',', $_GET['add_cols'])));
    foreach ($toAdd as $col) {
        // Only allow safe identifiers (a-z, 0-9, _) — guards against
        // accidentally injecting arbitrary names via the URL.
        if (preg_match('/^[a-z][a-z0-9_]{0,80}$/', $col) && !in_array($col, $selectedColumns, true)) {
            $selectedColumns[] = $col;
        }
    }
    // CRUCIAL : url-table.php re-reads $_GET['columns'] internally and would
    // otherwise pick the stale URL value (without our added columns). We
    // reflect the merged list back into $_GET so the table picks our version.
    $_GET['columns'] = implode(',', $selectedColumns);
}

// ?show_ai=1 — focused "what did I just generate?" view, used by the
// "Génération IA terminée" notification. Collapse to URL + every generated
// column so the user sees the AI output immediately, no manual column picking.
// Only when columns aren't explicitly set (an explicit ?columns wins) and at
// least one generated key exists (else keep the normal default columns).
if (isset($_GET['show_ai']) && $_GET['show_ai'] !== '' && !isset($_GET['columns'])) {
    $genCols = array_map(fn($g) => 'generation_' . $g['key'], $availableGenerations);
    if ($genCols) {
        $selectedColumns = array_merge(['url'], $genCols);
        $_GET['columns'] = implode(',', $selectedColumns);
    }
}
?>

<style>
/* Smart Filter Bar Styles */
.smart-filter-bar {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
    margin-bottom: 1rem;
}
.smart-search-wrapper {
    flex: 1;
    min-width: 280px;
    max-width: 400px;
    position: relative;
}
.smart-search-wrapper input {
    width: 100%;
    padding: 0.6rem 1rem 0.6rem 2.5rem;
    border: 2px solid var(--border-color);
    border-radius: 8px;
    font-size: 0.95rem;
    transition: border-color 0.2s, box-shadow 0.2s;
    background: white;
}
.smart-search-wrapper input:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(78, 205, 196, 0.1);
}
.smart-search-wrapper .search-icon {
    position: absolute;
    left: 0.75rem;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-secondary);
    font-size: 20px;
    pointer-events: none;
}
.btn-add-filter {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.55rem 0.9rem;
    background: transparent;
    border: 1.5px dashed var(--border-color);
    border-radius: 8px;
    color: var(--text-secondary);
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
}
.btn-add-filter:hover {
    border-color: var(--primary-color);
    color: var(--primary-color);
    background: rgba(78, 205, 196, 0.05);
}
.btn-add-filter .material-symbols-outlined { font-size: 18px; }

/* ============================================================
   AI URL filters — bouton "Demander à l'IA" + popover Copilot-style
   Reproduit l'UX du SQL Explorer pour la cohérence cross-pages.
   ============================================================ */
.ai-url-toolbar-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    height: 36px;
    padding: 0 0.75rem;
    background: var(--bg-secondary, #f4f6f8);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.15s;
    white-space: nowrap;
    flex-shrink: 0;
}
.ai-url-toolbar-btn:hover:not(:disabled) {
    background: white;
    border-color: #667eea;
}
.ai-url-toolbar-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
.ai-url-toolbar-btn .material-symbols-outlined {
    font-size: 18px;
    color: #667eea;
}
.ai-url-toolbar-btn:disabled .material-symbols-outlined {
    color: var(--text-secondary);
}
.ai-url-toolbar-btn .shortcut {
    font-size: 0.7rem;
    color: var(--text-secondary);
    background: white;
    border: 1px solid var(--border-color);
    padding: 1px 5px;
    border-radius: 3px;
    margin-left: 0.25rem;
}

/* Bulk AI button : pushed to the right of the toolbar (visually
   separates "filter the table" actions from "create new data" action).
   Tints toward purple to differentiate from the lighter blue of the
   filter-side AI button. */
.ai-url-toolbar-btn.bulk-ai-btn {
    margin-left: auto;
    background: #faf5ff;
    border-color: #ddd6fe;
    color: #6d28d9;
}
.ai-url-toolbar-btn.bulk-ai-btn:hover:not(:disabled) {
    background: #f3e8ff;
    border-color: #c4b5fd;
}
.ai-url-toolbar-btn.bulk-ai-btn .material-symbols-outlined { color: #6d28d9; }
.ai-url-toolbar-btn.bulk-ai-btn:disabled { background: #f8fafc; }
.ai-url-toolbar-btn.bulk-ai-btn:disabled .material-symbols-outlined { color: #94a3b8; }
.ai-url-toolbar-btn.bulk-ai-btn .bulk-count {
    font-size: 0.78rem; font-weight: 600;
    background: #6d28d9; color: white;
    border-radius: 999px; padding: 1px 7px;
    margin-left: 0.35rem;
    min-width: 18px; text-align: center;
}
.ai-url-toolbar-btn.bulk-ai-btn:disabled .bulk-count { display: none; }

/* Popover : positionné en fixed, JS l'ancre sous le bouton à l'ouverture */
.ai-url-popover {
    position: fixed;
    width: min(560px, calc(100vw - 2rem));
    background: white;
    border: 1px solid var(--border-color);
    border-radius: 10px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.18);
    z-index: 10000;
    padding: 0.85rem 0.9rem;
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
    animation: aiUrlPopIn 0.15s ease-out;
}
@keyframes aiUrlPopIn {
    from { opacity: 0; transform: translateY(-6px); }
    to   { opacity: 1; transform: translateY(0); }
}
.ai-url-popover-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    min-height: 28px;
}
.ai-url-popover-icon {
    color: #667eea;
    font-size: 20px;
    line-height: 1;
}
.ai-url-popover-title {
    font-weight: 600;
    color: var(--text-primary);
    flex: 1;
    font-size: 0.95rem;
    line-height: 1;
}
.ai-url-popover-close {
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
.ai-url-popover-close:hover {
    background: var(--bg-secondary, #f4f6f8);
    color: var(--text-primary);
}
.ai-url-popover-close .material-symbols-outlined {
    font-size: 18px;
    line-height: 1;
}
.ai-url-popover-input {
    width: 100%;
    padding: 0.6rem 0.75rem;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 0.9rem;
    font-family: inherit;
    background: white;
    color: var(--text-primary);
    resize: none;
    height: 72px;
    transition: border-color 0.15s;
    box-sizing: border-box;
    line-height: 1.4;
}
.ai-url-popover-input:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.15);
}
.ai-url-popover-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.75rem;
    min-height: 32px;
}
.ai-url-popover-hint {
    font-size: 0.75rem;
    color: var(--text-secondary);
    background: var(--bg-secondary, #f4f6f8);
    padding: 4px 8px;
    border-radius: 4px;
    line-height: 1;
    display: inline-flex;
    align-items: center;
}
.ai-url-btn {
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
.ai-url-btn:hover:not(:disabled) {
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
}
.ai-url-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}
.ai-url-btn .material-symbols-outlined { font-size: 18px; }
.ai-url-btn-spinner {
    width: 14px;
    height: 14px;
    border: 2px solid rgba(255,255,255,0.3);
    border-top-color: white;
    border-radius: 50%;
    animation: aiUrlSpin 0.75s linear infinite;
    display: inline-block;
}
@keyframes aiUrlSpin { to { transform: rotate(360deg); } }

/* Chips container */
.filter-chips-container {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
    min-height: 36px;
}

/* Filter Chip Group (conditions liées par OU) */
.chip-group {
    display: inline-flex;
    align-items: center;
    background: rgba(78, 205, 196, 0.08);
    border-radius: 6px;
    padding: 2px;
}
.chip-group .filter-chip:first-child { border-radius: 5px 0 0 5px; }
.chip-group .filter-chip:last-child { border-radius: 0 5px 5px 0; }
.chip-group .filter-chip:only-child { border-radius: 5px; }
.chip-or-connector {
    padding: 0 0.35rem;
    font-size: 0.7rem;
    font-weight: 600;
    color: var(--primary-color);
    text-transform: uppercase;
}

/* Filter Chip */
.filter-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.35rem 0.6rem;
    background: white;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 0.825rem;
    color: var(--text-primary);
    cursor: pointer;
    transition: all 0.15s;
    white-space: nowrap;
}
.filter-chip:hover {
    border-color: var(--primary-color);
    box-shadow: 0 2px 6px rgba(0,0,0,0.08);
}
.filter-chip .chip-field {
    font-weight: 600;
    color: var(--text-secondary);
}
.filter-chip .chip-value {
    color: var(--text-primary);
}
.filter-chip .chip-remove {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 16px;
    height: 16px;
    margin-left: 0.2rem;
    border-radius: 50%;
    background: transparent;
    color: var(--text-secondary);
    font-size: 14px;
    transition: all 0.15s;
}
.filter-chip .chip-remove:hover {
    background: var(--danger);
    color: white;
}

/* AND separator between chip groups */
.chip-and-separator {
    padding: 0 0.5rem;
    font-size: 0.7rem;
    font-weight: 600;
    color: #7F8C8D;
    text-transform: uppercase;
}

/* Btn add OR (appears on chip hover) */
.btn-add-or {
    opacity: 0;
    display: inline-flex;
    align-items: center;
    padding: 0.2rem 0.4rem;
    margin-left: -4px;
    background: var(--primary-color);
    color: white;
    border: none;
    border-radius: 0 5px 5px 0;
    font-size: 0.65rem;
    font-weight: 600;
    cursor: pointer;
    transition: opacity 0.15s;
}
.chip-group:hover .btn-add-or { opacity: 1; }

/* Clear all btn */
.btn-clear-filters {
    display: flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.35rem 0.6rem;
    background: transparent;
    border: none;
    color: var(--text-secondary);
    font-size: 0.8rem;
    cursor: pointer;
    transition: color 0.15s;
}
.btn-clear-filters:hover { color: var(--danger); }
.btn-clear-filters .material-symbols-outlined { font-size: 16px; }

/* Popover for filter config */
.filter-popover-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.15);
    z-index: 999;
    display: none;
}
.filter-popover-overlay.active { display: block; }

.filter-popover {
    position: absolute;
    z-index: 1000;
    background: white;
    border-radius: 10px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.18);
    padding: 1rem;
    min-width: 280px;
    max-width: 360px;
    display: none;
    animation: popoverIn 0.15s ease;
}
.filter-popover.active { display: block; }
@keyframes popoverIn {
    from { opacity: 0; transform: translateY(-8px); }
    to { opacity: 1; transform: translateY(0); }
}
.popover-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.75rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--border-color);
}
.popover-title {
    font-weight: 600;
    font-size: 0.95rem;
    color: var(--text-primary);
}
.popover-close {
    background: none;
    border: none;
    color: var(--text-secondary);
    cursor: pointer;
    padding: 0.2rem;
    display: flex;
}
.popover-close:hover { color: var(--text-primary); }

.popover-search { margin-bottom: 0.5rem; }
.popover-search input {
    width: 100%;
    padding: 0.4rem 0.6rem;
    border: 1px solid var(--border-color);
    border-radius: 4px;
    background: var(--bg-primary);
    color: var(--text-primary);
    font-size: 0.85rem;
    box-sizing: border-box;
}
.popover-search input:focus {
    outline: none;
    border-color: #94a3b8;
}

.popover-field-list {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    max-height: 280px;
    overflow-y: auto;
}
.popover-field-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 0.6rem;
    border-radius: 6px;
    cursor: pointer;
    transition: background 0.15s;
    font-size: 0.9rem;
}
.popover-field-item:hover { background: rgba(78, 205, 196, 0.08); }
.popover-field-item .material-symbols-outlined { font-size: 18px; color: var(--text-secondary); }

.popover-config {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}
.popover-row {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}
.popover-label {
    font-size: 0.8rem;
    font-weight: 500;
    color: var(--text-secondary);
}
.popover-select, .popover-input {
    padding: 0.5rem 0.7rem;
    border: 1.5px solid var(--border-color);
    border-radius: 6px;
    font-size: 0.9rem;
    transition: border-color 0.2s;
}
.popover-select:focus, .popover-input:focus {
    outline: none;
    border-color: var(--primary-color);
}
.popover-actions {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.5rem;
}
.popover-btn {
    flex: 1;
    padding: 0.5rem 0.75rem;
    border-radius: 6px;
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.15s;
    border: none;
}
.popover-btn-primary {
    background: var(--primary-color);
    color: white;
}
.popover-btn-primary:hover { background: var(--primary-dark); }
.popover-btn-secondary {
    background: var(--background);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
}
.popover-btn-secondary:hover { background: #e9ecef; }

/* Custom select dropdown style */
.custom-select-wrapper {
    position: relative;
}
.custom-select-trigger {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.5rem 0.7rem;
    border: 1.5px solid var(--border-color);
    border-radius: 6px;
    background: white;
    cursor: pointer;
    transition: border-color 0.2s;
}
.custom-select-trigger:hover { border-color: var(--primary-color); }
.custom-select-dropdown {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    background: white;
    border-radius: 8px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.15);
    z-index: 100;
    display: none;
    max-height: 200px;
    overflow-y: auto;
}
.custom-select-dropdown.show { display: block; }
.custom-select-option {
    padding: 0.5rem 0.7rem;
    cursor: pointer;
    transition: background 0.15s;
    font-size: 0.9rem;
}
.custom-select-option:hover { background: rgba(78, 205, 196, 0.08); }
.custom-select-option.selected { background: rgba(78, 205, 196, 0.15); font-weight: 500; }

/* Custom Styled Select (comme le tri de la home) */
.styled-select-wrapper {
    position: relative;
}
.styled-select-btn {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    padding: 0.5rem 0.75rem;
    background: white;
    border: 1.5px solid var(--border-color);
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.9rem;
    color: var(--text-primary);
    transition: all 0.2s;
}
.styled-select-btn:hover { border-color: var(--primary-color); }
.styled-select-btn .material-symbols-outlined { font-size: 18px; color: var(--text-secondary); }
.styled-select-menu {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    background: white;
    border-radius: 8px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.15);
    border: 1px solid var(--border-color);
    z-index: 110;
    display: none;
    overflow: hidden;
}
.styled-select-menu.show { display: block; animation: popoverIn 0.15s ease; }
.styled-select-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.55rem 0.75rem;
    cursor: pointer;
    font-size: 0.9rem;
    color: var(--text-primary);
    transition: all 0.15s;
    border-left: 3px solid transparent;
}
.styled-select-item:hover {
    background: rgba(78, 205, 196, 0.08);
    border-left-color: var(--primary-color);
}
.styled-select-item.active {
    background: rgba(78, 205, 196, 0.12);
    border-left-color: var(--primary-color);
    font-weight: 500;
}

/* Custom Checkboxes */
.styled-checkbox-list {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    max-height: 180px;
    overflow-y: auto;
    padding: 0.25rem;
}
.styled-checkbox-item {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.45rem 0.5rem;
    border-radius: 6px;
    cursor: pointer;
    transition: background 0.15s;
    font-size: 0.9rem;
}
.styled-checkbox-item:hover { background: rgba(78, 205, 196, 0.06); }
.styled-checkbox-item input[type="checkbox"] {
    display: none;
}
.styled-checkbox-item .checkbox-box {
    width: 18px;
    height: 18px;
    border: 2px solid var(--border-color);
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s;
    flex-shrink: 0;
}
.styled-checkbox-item .checkbox-box .material-symbols-outlined {
    font-size: 14px;
    color: white;
    opacity: 0;
    transition: opacity 0.15s;
}
.styled-checkbox-item input:checked + .checkbox-box {
    background: var(--primary-color);
    border-color: var(--primary-color);
}
.styled-checkbox-item input:checked + .checkbox-box .material-symbols-outlined {
    opacity: 1;
}
.styled-checkbox-item .checkbox-label {
    flex: 1;
    color: var(--text-primary);
}

/* Bouton +ou visible sur chaque chip */
.filter-chip {
    position: relative;
}
.chip-add-or {
    position: absolute;
    right: -10px;
    top: 50%;
    transform: translateY(-50%);
    width: 20px;
    height: 20px;
    background: var(--primary-color);
    color: white;
    border: 2px solid white;
    border-radius: 50%;
    display: none;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    cursor: pointer;
    box-shadow: 0 2px 6px rgba(0,0,0,0.15);
    z-index: 5;
    transition: transform 0.15s;
}
.filter-chip:hover .chip-add-or { display: flex; }
.chip-add-or:hover { transform: translateY(-50%) scale(1.15); }
.chip-add-or .material-symbols-outlined { font-size: 14px; }

/* Tooltip pour expliquer le OU */
.chip-add-or::after {
    content: '<?= __('filter.add_or_condition') ?>';
    position: absolute;
    bottom: calc(100% + 6px);
    left: 50%;
    transform: translateX(-50%);
    background: #2C3E50;
    color: white;
    padding: 0.35rem 0.6rem;
    border-radius: 4px;
    font-size: 0.7rem;
    white-space: nowrap;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.15s;
}
.chip-add-or:hover::after { opacity: 1; }

/* Supprimer l'ancien btn-add-or du groupe */
.btn-add-or { display: none !important; }
</style>

<h1 class="page-title">URL Explorer</h1>

<!-- Smart Filter Bar -->
<div class="smart-filter-bar">
    <!-- Search Input -->
    <div class="smart-search-wrapper">
        <span class="material-symbols-outlined search-icon">search</span>
        <input type="text" id="globalSearch" placeholder="<?= __('url_explorer.search_placeholder') ?>" value="<?= htmlspecialchars($search) ?>">
    </div>
    
    <!-- Filter Chips -->
    <div class="filter-chips-container" id="filterChipsContainer">
        <!-- Les chips seront générées dynamiquement -->
    </div>
    
    <!-- Add Filter Button -->
    <button class="btn-add-filter" onclick="openFieldSelector(event)">
        <span class="material-symbols-outlined">add</span>
        <?= __('url_explorer.filter') ?>
    </button>

    <!-- Filtre IA — colle au bouton "+ Filtre" : 2 façons de filtrer le tableau,
         l'une manuelle, l'autre en langage naturel (Copilot-style). -->
    <?php if ($aiRoleAllowed): ?>
    <button class="ai-url-toolbar-btn"
            id="aiUrlOpenBtn"
            onclick="openAiUrlPopover()"
            <?= $urlExplorerAiConfigured ? '' : 'disabled' ?>
            title="<?= htmlspecialchars($urlExplorerAiConfigured ? __('url_explorer.ai_button_label') . ' (Ctrl+K)' : __('url_explorer.ai_not_configured')) ?>">
        <span class="material-symbols-outlined">auto_awesome</span>
        <span><?= __('url_explorer.ai_button_label') ?></span>
        <span class="shortcut">Ctrl+K</span>
    </button>
    <?php endif; ?>

    <!-- Clear All (visible seulement si filtres actifs) -->
    <button class="btn-clear-filters" id="btnClearAll" style="display: none;" onclick="clearFilters()">
        <span class="material-symbols-outlined">close</span>
        <?= __('url_explorer.clear_all') ?>
    </button>

    <!-- Bulk AI Generator — action LOURDE sur les données (création de
         colonnes générées). Isolée à droite via margin-left:auto pour
         ne pas la confondre avec les boutons de filtrage. Cible TOUTES
         les URLs du tableau filtré (page courante). -->
    <?php if ($canBulkGenerate): ?>
    <button class="ai-url-toolbar-btn bulk-ai-btn"
            id="bulkAiOpenBtn"
            type="button"
            onclick="openBulkAiModal()"
            <?= $urlExplorerAiConfigured ? '' : 'disabled' ?>
            title="<?= htmlspecialchars($urlExplorerAiConfigured ? __('bulk_gen.button_label') : __('url_explorer.ai_not_configured')) ?>">
        <span class="material-symbols-outlined">stacks</span>
        <span><?= __('bulk_gen.button_label') ?></span>
        <span class="bulk-count"></span>
    </button>
    <?php endif; ?>
</div>

<!-- Popover IA "Demander à l'IA" — Copilot style, ancré au-dessus du bouton via JS. -->
<div id="aiUrlPopover" class="ai-url-popover" style="display: none;">
    <div class="ai-url-popover-header">
        <span class="material-symbols-outlined ai-url-popover-icon">auto_awesome</span>
        <span class="ai-url-popover-title"><?= __('url_explorer.ai_button_label') ?></span>
        <button type="button" class="ai-url-popover-close" onclick="closeAiUrlPopover()" title="<?= __('common.cancel') ?>">
            <span class="material-symbols-outlined">close</span>
        </button>
    </div>
    <textarea id="aiUrlInput"
              class="ai-url-popover-input"
              placeholder="<?= htmlspecialchars(__('url_explorer.ai_placeholder')) ?>"
              rows="3"></textarea>
    <div class="ai-url-popover-footer">
        <span class="ai-url-popover-hint">Ctrl+Enter</span>
        <button type="button"
                id="aiUrlGenerateBtn"
                class="ai-url-btn"
                onclick="generateUrlFiltersFromQuestion()">
            <span class="material-symbols-outlined ai-url-btn-icon">arrow_forward</span>
            <span class="ai-url-btn-spinner" style="display:none;"></span>
            <span class="ai-url-btn-label"><?= __('url_explorer.ai_generate') ?></span>
        </button>
    </div>
</div>

<!-- Popover Overlay -->
<div class="filter-popover-overlay" id="popoverOverlay" onclick="closeAllPopovers()"></div>

<!-- Field Selector Popover -->
<div class="filter-popover" id="fieldSelectorPopover">
    <div class="popover-header">
        <span class="popover-title"><?= __('url_explorer.add_filter') ?></span>
        <button class="popover-close" onclick="closeAllPopovers()">
            <span class="material-symbols-outlined">close</span>
        </button>
    </div>
    <div class="popover-search">
        <input type="text" id="fieldPickerSearch" oninput="filterFieldPicker()" onkeydown="if(event.key==='Enter'){event.preventDefault();fieldPickerEnter();}" autocomplete="off" placeholder="<?= __('url_explorer.search_filter_placeholder') ?>">
    </div>
    <div class="popover-field-list">
        <div class="popover-field-item" onclick="selectField('url')">
            <span class="material-symbols-outlined">link</span> URL
        </div>
        <div class="popover-field-item" onclick="selectField('category')">
            <span class="material-symbols-outlined">label</span> <?= __('url_explorer.field_category') ?>
        </div>
        <div class="popover-field-item" onclick="selectField('depth')">
            <span class="material-symbols-outlined">layers</span> <?= __('url_explorer.field_depth') ?>
        </div>
        <div class="popover-field-item" onclick="selectField('code')">
            <span class="material-symbols-outlined">http</span> Code HTTP
        </div>
        <div class="popover-field-item" onclick="selectField('compliant')">
            <span class="material-symbols-outlined">verified</span> <?= __('url_explorer.field_indexable') ?>
        </div>
        <div class="popover-field-item" onclick="selectField('canonical')">
            <span class="material-symbols-outlined">content_copy</span> Canonical
        </div>
        <div class="popover-field-item" onclick="selectField('title')">
            <span class="material-symbols-outlined">title</span> Title
        </div>
        <div class="popover-field-item" onclick="selectField('h1')">
            <span class="material-symbols-outlined">format_h1</span> H1
        </div>
        <div class="popover-field-item" onclick="selectField('metadesc')">
            <span class="material-symbols-outlined">description</span> Meta Description
        </div>
        <div class="popover-field-item" onclick="selectField('inlinks')">
            <span class="material-symbols-outlined">arrow_back</span> <?= __('url_explorer.field_inlinks') ?>
        </div>
        <div class="popover-field-item" onclick="selectField('outlinks')">
            <span class="material-symbols-outlined">arrow_forward</span> <?= __('url_explorer.field_outlinks') ?>
        </div>
        <div class="popover-field-item" onclick="selectField('noindex')">
            <span class="material-symbols-outlined">block</span> Noindex
        </div>
        <div class="popover-field-item" onclick="selectField('nofollow')">
            <span class="material-symbols-outlined">link_off</span> Nofollow
        </div>
        <div class="popover-field-item" onclick="selectField('blocked')">
            <span class="material-symbols-outlined">dangerous</span> <?= __('url_explorer.field_blocked') ?>
        </div>
        <div class="popover-field-item" onclick="selectField('h1_multiple')">
            <span class="material-symbols-outlined">format_h1</span> <?= __('url_explorer.field_h1_multiple') ?>
        </div>
        <div class="popover-field-item" onclick="selectField('headings_missing')">
            <span class="material-symbols-outlined">format_list_numbered</span> <?= __('url_explorer.field_bad_headings') ?>
        </div>
        <div class="popover-field-item" onclick="selectField('schemas')">
            <span class="material-symbols-outlined">data_object</span> <?= __('url_explorer.field_structured_data') ?>
        </div>
        <div class="popover-field-item" onclick="selectField('response_time')">
            <span class="material-symbols-outlined">speed</span> TTFB (ms)
        </div>
        <div class="popover-field-item" onclick="selectField('word_count')">
            <span class="material-symbols-outlined">format_size</span> <?= __('url_explorer.field_word_count') ?>
        </div>
        <div class="popover-field-item" onclick="selectField('pri')">
            <span class="material-symbols-outlined">star</span> <?= __('url_explorer.field_pagerank') ?>
        </div>
        <div class="popover-field-item" onclick="selectField('external')">
            <span class="material-symbols-outlined">public</span> <?= __('url_explorer.field_external') ?>
        </div>
        <div class="popover-field-item" onclick="selectField('crawled')">
            <span class="material-symbols-outlined">task_alt</span> <?= __('url_explorer.field_crawled') ?>
        </div>
        <div class="popover-field-item" onclick="selectField('in_sitemap')">
            <span class="material-symbols-outlined">map</span> <?= __('url_explorer.field_in_sitemap') ?>
        </div>
        <div class="popover-field-item" onclick="selectField('is_html')">
            <span class="material-symbols-outlined">html</span> <?= __('url_explorer.field_is_html') ?>
        </div>
        <div class="popover-field-item" onclick="selectField('out_of_scope')">
            <span class="material-symbols-outlined">explore_off</span> <?= __('url_explorer.field_out_of_scope') ?>
        </div>
        <div class="popover-field-item" onclick="selectField('content_type')">
            <span class="material-symbols-outlined">draft</span> <?= __('url_explorer.field_content_type') ?>
        </div>
        <div class="popover-field-item" onclick="selectField('redirect_to')">
            <span class="material-symbols-outlined">redo</span> <?= __('url_explorer.field_redirect_to') ?>
        </div>
        <div class="popover-field-item" onclick="selectField('canonical_value')">
            <span class="material-symbols-outlined">north_east</span> <?= __('url_explorer.field_canonical_value') ?>
        </div>
        <div class="popover-field-item" onclick="selectField('domain')">
            <span class="material-symbols-outlined">domain</span> <?= __('url_explorer.field_domain') ?>
        </div>
        <?php foreach ($availableExtractors as $extr): ?>
        <div class="popover-field-item" onclick="selectField('extract_<?= htmlspecialchars($extr['key']) ?>')">
            <span class="material-symbols-outlined">code</span> <?= htmlspecialchars($extr['key']) ?>
        </div>
        <?php endforeach; ?>
        <?php foreach ($availableGenerations as $gen): ?>
        <div class="popover-field-item" onclick="selectField('generation_<?= htmlspecialchars($gen['key']) ?>')">
            <span class="material-symbols-outlined">auto_awesome</span> AI : <?= htmlspecialchars($gen['key']) ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Filter Config Popover -->
<div class="filter-popover" id="filterConfigPopover">
    <div class="popover-header">
        <span class="popover-title" id="configPopoverTitle"><?= __('url_explorer.configure_filter') ?></span>
        <button class="popover-close" onclick="closeAllPopovers()">
            <span class="material-symbols-outlined">close</span>
        </button>
    </div>
    <div class="popover-config" id="popoverConfigContent">
        <!-- Contenu dynamique -->
    </div>
</div>

<!-- Utilisation du composant URL Table -->
<?php
$urlTableConfig = [
    'title' => __('url_explorer.urls_found'),
    'id' => 'main_explorer',
    'whereClause' => 'WHERE ' . $whereClause,
    'orderBy' => 'ORDER BY c.depth ASC, c.inlinks DESC',
    'sqlParams' => $params,
    'defaultColumns' => $selectedColumns,
    'pdo' => $pdo,
    'crawlId' => $crawlId,
    'projectDir' => $_GET['project'] ?? '',
    // Expose all filtered page ids so the Bulk AI modal works on the FULL
    // filtered set (every matching URL), not just the displayed page.
    'exposeAllIds' => true,
];

include __DIR__ . '/../components/url-table.php';
?>

<script>
// ============================================
// SMART FILTER BAR - Configuration & State
// ============================================
var availableCategories = <?= json_encode($availableCategories) ?>;
var fieldConfig = {
    url: { label: 'URL', icon: 'link', type: 'text', operators: ['contains', 'not_contains', 'regex', 'not_regex'] },
    category: { label: __('url_explorer.field_category'), icon: 'label', type: 'category', operators: ['in', 'not_in'] },
    depth: { label: __('url_explorer.field_depth'), icon: 'layers', type: 'number', operators: ['=', '>', '<', '>=', '<=', '!='] },
    code: { label: __('url_explorer.field_http_code'), icon: 'http', type: 'http_code', values: ['1xx', '2xx', '3xx', '4xx', '5xx', 'other'], operators: ['=', '>', '<', '>=', '<=', '!='] },
    compliant: { label: __('url_explorer.field_indexable'), icon: 'verified', type: 'boolean' },
    canonical: { label: 'Canonical', icon: 'content_copy', type: 'boolean' },
    title: { label: 'Title', icon: 'title', type: 'seo', values: ['unique', 'empty', 'duplicate'], operators: ['contains', 'not_contains', 'regex', 'not_regex'] },
    h1: { label: 'H1', icon: 'format_h1', type: 'seo', values: ['unique', 'empty', 'duplicate'], operators: ['contains', 'not_contains', 'regex', 'not_regex'] },
    metadesc: { label: 'Meta Desc', icon: 'description', type: 'seo', values: ['unique', 'empty', 'duplicate'], operators: ['contains', 'not_contains', 'regex', 'not_regex'] },
    inlinks: { label: __('url_explorer.field_inlinks'), icon: 'arrow_back', type: 'number', operators: ['=', '>', '<', '>=', '<=', '!='] },
    outlinks: { label: __('url_explorer.field_outlinks'), icon: 'arrow_forward', type: 'number', operators: ['=', '>', '<', '>=', '<=', '!='] },
    noindex: { label: 'Noindex', icon: 'block', type: 'boolean' },
    nofollow: { label: 'Nofollow', icon: 'link_off', type: 'boolean' },
    blocked: { label: __('url_explorer.field_blocked'), icon: 'dangerous', type: 'boolean' },
    h1_multiple: { label: __('url_explorer.field_h1_multiple'), icon: 'format_h1', type: 'boolean' },
    headings_missing: { label: __('url_explorer.field_bad_headings'), icon: 'format_list_numbered', type: 'boolean' },
    schemas: { label: __('url_explorer.field_structured_data'), icon: 'data_object', type: 'schemas', operators: ['=', '>', '<', '>=', '<=', 'contains', 'not_contains'] },
    response_time: { label: 'TTFB (ms)', icon: 'speed', type: 'number', operators: ['>', '<', '>=', '<='] },
    word_count: { label: __('url_explorer.field_word_count'), icon: 'format_size', type: 'number', operators: ['=', '>', '<', '>=', '<=', '!='] },
    pri: { label: __('url_explorer.field_pagerank'), icon: 'star', type: 'number', operators: ['>', '<', '>=', '<=', '=', '!='] },
    external: { label: __('url_explorer.field_external'), icon: 'public', type: 'boolean' },
    crawled: { label: __('url_explorer.field_crawled'), icon: 'task_alt', type: 'boolean' },
    in_sitemap: { label: __('url_explorer.field_in_sitemap'), icon: 'map', type: 'boolean' },
    is_html: { label: __('url_explorer.field_is_html'), icon: 'html', type: 'boolean' },
    out_of_scope: { label: __('url_explorer.field_out_of_scope'), icon: 'explore_off', type: 'boolean' },
    content_type: { label: __('url_explorer.field_content_type'), icon: 'draft', type: 'text', operators: ['contains', 'not_contains', 'regex', 'not_regex'] },
    redirect_to: { label: __('url_explorer.field_redirect_to'), icon: 'redo', type: 'text', operators: ['contains', 'not_contains', 'regex', 'not_regex'] },
    canonical_value: { label: __('url_explorer.field_canonical_value'), icon: 'north_east', type: 'text', operators: ['contains', 'not_contains', 'regex', 'not_regex'] },
    domain: { label: __('url_explorer.field_domain'), icon: 'domain', type: 'text', operators: ['contains', 'not_contains', 'regex', 'not_regex'] }
};

// Étendre fieldConfig avec un filtre dynamique par extracteur custom.
// Le type (number/text) est détecté côté serveur par sampling et passé ici.
var availableExtractors = <?= json_encode($availableExtractors) ?>;
availableExtractors.forEach(extr => {
    const fieldId = 'extract_' + extr.key;
    if (extr.type === 'number') {
        fieldConfig[fieldId] = { label: extr.key, icon: 'code', type: 'number', operators: ['=', '>', '<', '>=', '<=', '!='] };
    } else {
        fieldConfig[fieldId] = { label: extr.key, icon: 'code', type: 'text', operators: ['contains', 'not_contains', 'regex', 'not_regex'] };
    }
});

// Étendre fieldConfig avec un filtre par clé générée par l'IA (Bulk AI Generator).
// Type détecté via jsonb_typeof côté serveur — opérateurs adaptés (number → >,<,
// between ; boolean → = true/false ; text → contains, regex…).
var availableGenerations = <?= json_encode($availableGenerations) ?>;
availableGenerations.forEach(gen => {
    const fieldId = 'generation_' + gen.key;
    if (gen.type === 'number') {
        fieldConfig[fieldId] = { label: 'AI: ' + gen.key, icon: 'auto_awesome', type: 'number', operators: ['=', '>', '<', '>=', '<=', '!='] };
    } else if (gen.type === 'boolean') {
        fieldConfig[fieldId] = { label: 'AI: ' + gen.key, icon: 'auto_awesome', type: 'boolean', operators: ['='] };
    } else {
        fieldConfig[fieldId] = { label: 'AI: ' + gen.key, icon: 'auto_awesome', type: 'text', operators: ['contains', 'not_contains', 'regex', 'not_regex'] };
    }
});

var availableSchemas = <?= json_encode($availableSchemas) ?>;

var operatorLabels = {
    'contains': __('url_explorer.op_contains'), 'not_contains': __('url_explorer.op_not_contains'),
    'regex': __('url_explorer.op_regex'), 'not_regex': __('url_explorer.op_not_regex'),
    '=': '=', '>': '>', '<': '<', '>=': '≥', '<=': '≤', '!=': '≠',
    'in': __('url_explorer.op_is'), 'not_in': __('url_explorer.op_is_not')
};

var seoValueLabels = { 'unique': __('url_explorer.seo_unique'), 'empty': __('url_explorer.seo_empty'), 'duplicate': __('url_explorer.seo_duplicate') };
var httpCodeLabels = { '1xx': '1xx (100-199)', '2xx': '2xx (200-299)', '3xx': '3xx (300-399)', '4xx': '4xx (400-499)', '5xx': '5xx (500-599)', 'other': __('url_explorer.other') };
var boolLabels = { 'true': __('common.yes'), 'false': __('common.no') };

// État des filtres : tableau de groupes, chaque groupe = tableau de conditions liées par OU
// Les groupes entre eux sont liés par ET
var filterGroups = [];
var pendingFilterConfig = null; // Pour stocker le filtre en cours de configuration
var editingChipIndex = null; // {groupIndex, chipIndex} si on édite une chip existante

// Charger les filtres depuis l'URL
var currentFilters = <?= json_encode($filters) ?>;
// Colonnes actuellement affichées dans le tableau — utilisé pour auto-ajouter
// la colonne correspondante quand on ajoute un filtre.
var currentColumns = <?= json_encode($selectedColumns) ?>;
if (currentFilters && currentFilters.length > 0) {
    // Convertir l'ancien format vers le nouveau
    filterGroups = convertOldFiltersToNew(currentFilters);
}

function convertOldFiltersToNew(oldFilters) {
    const groups = [];
    oldFilters.forEach(item => {
        if (item.type === 'group' && item.items) {
            if (item.logic === 'OR') {
                // Groupe OU = un seul groupe avec plusieurs chips
                const chips = item.items.filter(i => i.field).map(i => ({
                    field: i.field, operator: i.operator || '=', value: i.value
                }));
                if (chips.length > 0) groups.push(chips);
            } else {
                // Groupe ET = plusieurs groupes de 1 chip chacun
                item.items.filter(i => i.field).forEach(i => {
                    groups.push([{ field: i.field, operator: i.operator || '=', value: i.value }]);
                });
            }
        } else if (item.field) {
            groups.push([{ field: item.field, operator: item.operator || '=', value: item.value }]);
        }
    });
    return groups;
}

// ============================================
// RENDER CHIPS
// ============================================
function renderChips() {
    const container = document.getElementById('filterChipsContainer');
    container.innerHTML = '';
    
    filterGroups.forEach((group, groupIndex) => {
        if (groupIndex > 0) {
            // Séparateur ET entre groupes
            const andSep = document.createElement('span');
            andSep.className = 'chip-and-separator';
            andSep.textContent = __('url_explorer.and');
            container.appendChild(andSep);
        }
        
        if (group.length === 1) {
            // Chip seule
            container.appendChild(createChipElement(group[0], groupIndex, 0));
        } else {
            // Groupe de chips (OU)
            const chipGroup = document.createElement('div');
            chipGroup.className = 'chip-group';
            group.forEach((chip, chipIndex) => {
                if (chipIndex > 0) {
                    const orConn = document.createElement('span');
                    orConn.className = 'chip-or-connector';
                    orConn.textContent = __('url_explorer.or');
                    chipGroup.appendChild(orConn);
                }
                chipGroup.appendChild(createChipElement(chip, groupIndex, chipIndex));
            });
            // Bouton +OU
            const addOrBtn = document.createElement('button');
            addOrBtn.className = 'btn-add-or';
            addOrBtn.textContent = '+ou';
            addOrBtn.onclick = (e) => { e.stopPropagation(); addOrToGroup(groupIndex, e); };
            chipGroup.appendChild(addOrBtn);
            container.appendChild(chipGroup);
        }
    });
    
    // Toggle bouton clear
    document.getElementById('btnClearAll').style.display = filterGroups.length > 0 ? 'flex' : 'none';
}

function createChipElement(chip, groupIndex, chipIndex) {
    const el = document.createElement('div');
    el.className = 'filter-chip';
    el.onclick = (e) => {
        if (!e.target.classList.contains('chip-remove') && !e.target.classList.contains('chip-add-or')) {
            editChip(groupIndex, chipIndex, e);
        }
    };
    
    const config = fieldConfig[chip.field] || { label: chip.field };
    let displayValue = formatChipValue(chip);
    
    el.innerHTML = `
        <span class="chip-field">${config.label}</span>
        <span class="chip-value">${displayValue}</span>
        <span class="chip-remove material-symbols-outlined" onclick="event.stopPropagation(); removeChip(${groupIndex}, ${chipIndex})">close</span>
        <span class="chip-add-or" onclick="event.stopPropagation(); addOrToChip(${groupIndex}, event)">
            <span class="material-symbols-outlined">add</span>
        </span>
    `;
    return el;
}

function addOrToChip(groupIndex, event) {
    event.stopPropagation();
    closeAllPopovers();
    editingChipIndex = null;
    pendingFilterConfig = { addToGroup: groupIndex };
    
    const popover = document.getElementById('fieldSelectorPopover');
    positionPopover(popover, event.currentTarget);
    popover.classList.add('active');
    document.getElementById('popoverOverlay').classList.add('active');
}

function formatChipValue(chip) {
    const config = fieldConfig[chip.field];
    if (!config) return chip.value;
    
    if (config.type === 'boolean') {
        return boolLabels[chip.value] || chip.value;
    } else if (config.type === 'seo') {
        // Filtre sur la valeur (texte) ou sur l'état
        if (chip.operator && ['contains', 'not_contains', 'regex', 'not_regex'].includes(chip.operator)) {
            const op = operatorLabels[chip.operator] || '';
            return `${op} "${chip.value}"`;
        }
        // Filtre sur l'état
        if (Array.isArray(chip.value)) {
            const labels = chip.value.map(v => seoValueLabels[v] || v);
            return labels.length > 2 ? labels.slice(0,2).join(' / ') + '...' : labels.join(' / ');
        }
        return seoValueLabels[chip.value] || chip.value;
    } else if (config.type === 'http_code') {
        // Filtre par valeur exacte ou par groupe
        if (chip.operator && ['=', '>', '<', '>=', '<=', '!='].includes(chip.operator)) {
            const op = operatorLabels[chip.operator] || '=';
            return `${op} ${chip.value}`;
        }
        // Filtre par groupe
        if (Array.isArray(chip.value)) {
            const labels = chip.value.map(v => v);
            return labels.length > 3 ? labels.slice(0,3).join(' / ') + '...' : labels.join(' / ');
        }
        return chip.value;
    } else if (config.type === 'category') {
        if (Array.isArray(chip.value)) {
            const names = chip.value.map(id => {
                const cat = availableCategories.find(c => c.id == id);
                return cat ? cat.cat : id;
            });
            const prefix = chip.operator === 'not_in' ? '≠ ' : '';
            return prefix + (names.length > 2 ? names.slice(0,2).join(', ') + '...' : names.join(', '));
        }
        return chip.value;
    } else if (config.type === 'text') {
        const op = operatorLabels[chip.operator] || '';
        return `${op} "${chip.value}"`;
    } else if (config.type === 'number') {
        const op = operatorLabels[chip.operator] || '=';
        return `${op} ${chip.value}`;
    }
    return chip.value;
}

// ============================================
// POPOVER MANAGEMENT
// ============================================
function openFieldSelector(event) {
    event.stopPropagation();
    closeAllPopovers();
    editingChipIndex = null;
    pendingFilterConfig = { addToGroup: null };

    const popover = document.getElementById('fieldSelectorPopover');
    const btn = event.currentTarget;
    positionPopover(popover, btn);
    popover.classList.add('active');
    document.getElementById('popoverOverlay').classList.add('active');
    resetFieldPickerSearch();
}

// Fuzzy search dans le picker de filtre : matche le texte des .popover-field-item.
function filterFieldPicker() {
    const input = document.getElementById('fieldPickerSearch');
    if (!input) return;
    const q = input.value.trim().toLowerCase();
    document.querySelectorAll('#fieldSelectorPopover .popover-field-item').forEach(item => {
        const txt = item.textContent.toLowerCase();
        item.style.display = (!q || txt.includes(q)) ? '' : 'none';
    });
}

function resetFieldPickerSearch() {
    const input = document.getElementById('fieldPickerSearch');
    if (!input) return;
    input.value = '';
    filterFieldPicker();
    setTimeout(() => input.focus(), 50);
}

function fieldPickerEnter() {
    const firstVisible = document.querySelector('#fieldSelectorPopover .popover-field-item:not([style*="display: none"])');
    if (firstVisible) firstVisible.click();
}

function addOrToGroup(groupIndex, event) {
    event.stopPropagation();
    closeAllPopovers();
    editingChipIndex = null;
    pendingFilterConfig = { addToGroup: groupIndex };
    
    const popover = document.getElementById('fieldSelectorPopover');
    positionPopover(popover, event.currentTarget);
    popover.classList.add('active');
    document.getElementById('popoverOverlay').classList.add('active');
    resetFieldPickerSearch();
}

function selectField(field) {
    closeAllPopovers();
    pendingFilterConfig = { ...pendingFilterConfig, field };
    openConfigPopover(field);
}

function openConfigPopover(field, existingChip = null) {
    const config = fieldConfig[field];
    const popover = document.getElementById('filterConfigPopover');
    const content = document.getElementById('popoverConfigContent');
    document.getElementById('configPopoverTitle').textContent = config.label;
    
    let html = '';
    
    if (config.type === 'text') {
        const op = existingChip?.operator || 'contains';
        const val = existingChip?.value || '';
        const opLabels = {
            'contains': __('url_explorer.op_contains_label'),
            'not_contains': __('url_explorer.op_not_contains_label'),
            'regex': __('url_explorer.op_regex_label'),
            'not_regex': __('url_explorer.op_not_regex_label')
        };
        html = `
            <div class="popover-row">
                <label class="popover-label">${__('url_explorer.label_condition')}</label>
                <div class="styled-select-wrapper">
                    <input type="hidden" id="configOperator" value="${op}">
                    <div class="styled-select-btn" onclick="toggleStyledSelect(this)">
                        <span class="select-value">${opLabels[op]}</span>
                        <span class="material-symbols-outlined">expand_more</span>
                    </div>
                    <div class="styled-select-menu">
                        <div class="styled-select-item ${op === 'contains' ? 'active' : ''}" data-value="contains" onclick="selectStyledOption(this, 'configOperator')">${__('url_explorer.op_contains_label')}</div>
                        <div class="styled-select-item ${op === 'not_contains' ? 'active' : ''}" data-value="not_contains" onclick="selectStyledOption(this, 'configOperator')">${__('url_explorer.op_not_contains_label')}</div>
                        <div class="styled-select-item ${op === 'regex' ? 'active' : ''}" data-value="regex" onclick="selectStyledOption(this, 'configOperator')">${__('url_explorer.op_regex_label')}</div>
                        <div class="styled-select-item ${op === 'not_regex' ? 'active' : ''}" data-value="not_regex" onclick="selectStyledOption(this, 'configOperator')">${__('url_explorer.op_not_regex_label')}</div>
                    </div>
                </div>
            </div>
            <div class="popover-row">
                <label class="popover-label">${__('url_explorer.label_value')}</label>
                <input type="text" class="popover-input" id="configValue" placeholder="Texte ou regex..." value="${val}">
            </div>
        `;
    } else if (config.type === 'number') {
        const op = existingChip?.operator || '=';
        const val = existingChip?.value || '';
        html = `
            <div class="popover-row">
                <label class="popover-label">${__('url_explorer.label_operator')}</label>
                <div class="styled-select-wrapper">
                    <input type="hidden" id="configOperator" value="${op}">
                    <div class="styled-select-btn" onclick="toggleStyledSelect(this)">
                        <span class="select-value">${operatorLabels[op]}</span>
                        <span class="material-symbols-outlined">expand_more</span>
                    </div>
                    <div class="styled-select-menu">
                        ${config.operators.map(o => `<div class="styled-select-item ${op === o ? 'active' : ''}" data-value="${o}" onclick="selectStyledOption(this, 'configOperator')">${operatorLabels[o]}</div>`).join('')}
                    </div>
                </div>
            </div>
            <div class="popover-row">
                <label class="popover-label">${__('url_explorer.label_value')}</label>
                <input type="number" class="popover-input" id="configValue" placeholder="Nombre..." value="${val}">
            </div>
        `;
    } else if (config.type === 'boolean') {
        const val = existingChip?.value || 'true';
        html = `
            <div class="popover-row">
                <label class="popover-label">${__('url_explorer.label_value')}</label>
                <div class="styled-select-wrapper">
                    <input type="hidden" id="configValue" value="${val}">
                    <div class="styled-select-btn" onclick="toggleStyledSelect(this)">
                        <span class="select-value">${val === 'true' ? __('common.yes') : __('common.no')}</span>
                        <span class="material-symbols-outlined">expand_more</span>
                    </div>
                    <div class="styled-select-menu">
                        <div class="styled-select-item ${val === 'true' ? 'active' : ''}" data-value="true" onclick="selectStyledOption(this, 'configValue')">${__('common.yes')}</div>
                        <div class="styled-select-item ${val === 'false' ? 'active' : ''}" data-value="false" onclick="selectStyledOption(this, 'configValue')">${__('common.no')}</div>
                    </div>
                </div>
            </div>
        `;
    } else if (config.type === 'http_code') {
        // Déterminer le mode : groupe ou valeur
        const isValueMode = existingChip?.operator && ['=', '>', '<', '>=', '<=', '!='].includes(existingChip.operator);
        const filterMode = isValueMode ? 'value' : 'group';
        const selectedValues = !isValueMode && Array.isArray(existingChip?.value) ? existingChip.value : (!isValueMode && existingChip?.value ? [existingChip.value] : ['2xx']);
        const op = existingChip?.operator || '=';
        const numVal = isValueMode ? existingChip?.value || '' : '';
        
        html = `
            <div class="popover-row">
                <label class="popover-label">${__('url_explorer.filter_by')}</label>
                <div class="styled-select-wrapper">
                    <input type="hidden" id="configFilterMode" value="${filterMode}">
                    <div class="styled-select-btn" onclick="toggleStyledSelect(this)">
                        <span class="select-value">${filterMode === 'group' ? __('url_explorer.code_group') : __('url_explorer.exact_value')}</span>
                        <span class="material-symbols-outlined">expand_more</span>
                    </div>
                    <div class="styled-select-menu">
                        <div class="styled-select-item ${filterMode === 'group' ? 'active' : ''}" data-value="group" onclick="selectStyledOption(this, 'configFilterMode'); toggleHttpCodeMode('group')">${__('url_explorer.code_group')}</div>
                        <div class="styled-select-item ${filterMode === 'value' ? 'active' : ''}" data-value="value" onclick="selectStyledOption(this, 'configFilterMode'); toggleHttpCodeMode('value')">${__('url_explorer.exact_value')}</div>
                    </div>
                </div>
            </div>
            <div id="httpCodeGroupMode" style="${filterMode === 'group' ? '' : 'display:none'}">
                <div class="popover-row">
                    <label class="popover-label">${__('url_explorer.label_groups')}</label>
                    <div class="styled-checkbox-list" style="max-height: 180px;">
                        ${config.values.map(v => `
                            <label class="styled-checkbox-item">
                                <input type="checkbox" class="httpcode-checkbox" value="${v}" ${selectedValues.includes(v) ? 'checked' : ''}>
                                <span class="checkbox-box"><span class="material-symbols-outlined">check</span></span>
                                <span class="checkbox-label">${httpCodeLabels[v]}</span>
                            </label>
                        `).join('')}
                    </div>
                </div>
            </div>
            <div id="httpCodeValueMode" style="${filterMode === 'value' ? '' : 'display:none'}">
                <div class="popover-row">
                    <label class="popover-label">${__('url_explorer.label_operator')}</label>
                    <div class="styled-select-wrapper">
                        <input type="hidden" id="configOperator" value="${op}">
                        <div class="styled-select-btn" onclick="toggleStyledSelect(this)">
                            <span class="select-value">${operatorLabels[op]}</span>
                            <span class="material-symbols-outlined">expand_more</span>
                        </div>
                        <div class="styled-select-menu">
                            ${config.operators.map(o => `<div class="styled-select-item ${op === o ? 'active' : ''}" data-value="${o}" onclick="selectStyledOption(this, 'configOperator')">${operatorLabels[o]}</div>`).join('')}
                        </div>
                    </div>
                </div>
                <div class="popover-row">
                    <label class="popover-label">${__('url_explorer.label_code')}</label>
                    <input type="number" class="popover-input" id="configValue" placeholder="Ex: 200, 404..." value="${numVal}">
                </div>
            </div>
        `;
    } else if (config.type === 'seo') {
        // Déterminer le mode : état ou valeur
        const isValueMode = existingChip?.operator && ['contains', 'not_contains', 'regex', 'not_regex'].includes(existingChip.operator);
        const filterMode = isValueMode ? 'value' : 'status';
        const selectedValues = !isValueMode && Array.isArray(existingChip?.value) ? existingChip.value : (!isValueMode && existingChip?.value ? [existingChip.value] : ['empty']);
        const op = existingChip?.operator || 'contains';
        const textVal = isValueMode ? existingChip?.value || '' : '';
        const opLabels = { 'contains': __('url_explorer.op_contains_label'), 'not_contains': __('url_explorer.op_not_contains_label'), 'regex': __('url_explorer.op_regex_label'), 'not_regex': __('url_explorer.op_not_regex_label') };

        html = `
            <div class="popover-row">
                <label class="popover-label">${__('url_explorer.filter_by')}</label>
                <div class="styled-select-wrapper">
                    <input type="hidden" id="configFilterMode" value="${filterMode}">
                    <div class="styled-select-btn" onclick="toggleStyledSelect(this)">
                        <span class="select-value">${filterMode === 'status' ? __('url_explorer.state') : __('url_explorer.text_value')}</span>
                        <span class="material-symbols-outlined">expand_more</span>
                    </div>
                    <div class="styled-select-menu">
                        <div class="styled-select-item ${filterMode === 'status' ? 'active' : ''}" data-value="status" onclick="selectStyledOption(this, 'configFilterMode'); toggleSeoMode('status')">${__('url_explorer.state')}</div>
                        <div class="styled-select-item ${filterMode === 'value' ? 'active' : ''}" data-value="value" onclick="selectStyledOption(this, 'configFilterMode'); toggleSeoMode('value')">${__('url_explorer.text_value')}</div>
                    </div>
                </div>
            </div>
            <div id="seoStatusMode" style="${filterMode === 'status' ? '' : 'display:none'}">
                <div class="popover-row">
                    <label class="popover-label">${__('url_explorer.label_states')}</label>
                    <div class="styled-checkbox-list" style="max-height: 140px;">
                        ${config.values.map(v => `
                            <label class="styled-checkbox-item">
                                <input type="checkbox" class="seo-checkbox" value="${v}" ${selectedValues.includes(v) ? 'checked' : ''}>
                                <span class="checkbox-box"><span class="material-symbols-outlined">check</span></span>
                                <span class="checkbox-label">${seoValueLabels[v]}</span>
                            </label>
                        `).join('')}
                    </div>
                </div>
            </div>
            <div id="seoValueMode" style="${filterMode === 'value' ? '' : 'display:none'}">
                <div class="popover-row">
                    <label class="popover-label">${__('url_explorer.label_condition')}</label>
                    <div class="styled-select-wrapper">
                        <input type="hidden" id="configOperator" value="${op}">
                        <div class="styled-select-btn" onclick="toggleStyledSelect(this)">
                            <span class="select-value">${opLabels[op]}</span>
                            <span class="material-symbols-outlined">expand_more</span>
                        </div>
                        <div class="styled-select-menu">
                            ${Object.entries(opLabels).map(([k,v]) => `<div class="styled-select-item ${op === k ? 'active' : ''}" data-value="${k}" onclick="selectStyledOption(this, 'configOperator')">${v}</div>`).join('')}
                        </div>
                    </div>
                </div>
                <div class="popover-row">
                    <label class="popover-label">${__('url_explorer.label_value')}</label>
                    <input type="text" class="popover-input" id="configValue" placeholder="Texte..." value="${textVal}">
                </div>
            </div>
        `;
    } else if (config.type === 'schemas') {
        // Déterminer le mode : count ou contains
        const isCountMode = existingChip?.operator && ['=', '>', '<', '>=', '<='].includes(existingChip.operator);
        const filterMode = isCountMode ? 'count' : 'contains';
        const op = existingChip?.operator || '>';
        const numVal = isCountMode ? existingChip?.value || '0' : '0';
        const containsOp = !isCountMode ? (existingChip?.operator || 'contains') : 'contains';
        const selectedSchemas = !isCountMode && Array.isArray(existingChip?.value) ? existingChip.value : [];
        
        html = `
            <div class="popover-row">
                <label class="popover-label">${__('url_explorer.filter_by')}</label>
                <div class="styled-select-wrapper">
                    <input type="hidden" id="configFilterMode" value="${filterMode}">
                    <div class="styled-select-btn" onclick="toggleStyledSelect(this)">
                        <span class="select-value">${filterMode === 'count' ? __('url_explorer.schema_count') : __('url_explorer.schema_type')}</span>
                        <span class="material-symbols-outlined">expand_more</span>
                    </div>
                    <div class="styled-select-menu">
                        <div class="styled-select-item ${filterMode === 'count' ? 'active' : ''}" data-value="count" onclick="selectStyledOption(this, 'configFilterMode'); toggleSchemasMode('count')">${__('url_explorer.schema_count')}</div>
                        <div class="styled-select-item ${filterMode === 'contains' ? 'active' : ''}" data-value="contains" onclick="selectStyledOption(this, 'configFilterMode'); toggleSchemasMode('contains')">${__('url_explorer.schema_type')}</div>
                    </div>
                </div>
            </div>
            <div id="schemasCountMode" style="${filterMode === 'count' ? '' : 'display:none'}">
                <div class="popover-row">
                    <label class="popover-label">${__('url_explorer.label_operator')}</label>
                    <div class="styled-select-wrapper">
                        <input type="hidden" id="configOperator" value="${op}">
                        <div class="styled-select-btn" onclick="toggleStyledSelect(this)">
                            <span class="select-value">${operatorLabels[op]}</span>
                            <span class="material-symbols-outlined">expand_more</span>
                        </div>
                        <div class="styled-select-menu">
                            ${['=', '>', '<', '>=', '<='].map(o => `<div class="styled-select-item ${op === o ? 'active' : ''}" data-value="${o}" onclick="selectStyledOption(this, 'configOperator')">${operatorLabels[o]}</div>`).join('')}
                        </div>
                    </div>
                </div>
                <div class="popover-row">
                    <label class="popover-label">${__('url_explorer.label_number')}</label>
                    <input type="number" class="popover-input" id="configValue" placeholder="Ex: 0, 1, 5..." value="${numVal}" min="0">
                </div>
            </div>
            <div id="schemasContainsMode" style="${filterMode === 'contains' ? '' : 'display:none'}">
                <div class="popover-row">
                    <label class="popover-label">${__('url_explorer.label_condition')}</label>
                    <div class="styled-select-wrapper">
                        <input type="hidden" id="configContainsOperator" value="${containsOp}">
                        <div class="styled-select-btn" onclick="toggleStyledSelect(this)">
                            <span class="select-value">${containsOp === 'contains' ? __('url_explorer.op_contains_label') : __('url_explorer.op_not_contains_label')}</span>
                            <span class="material-symbols-outlined">expand_more</span>
                        </div>
                        <div class="styled-select-menu">
                            <div class="styled-select-item ${containsOp === 'contains' ? 'active' : ''}" data-value="contains" onclick="selectStyledOption(this, 'configContainsOperator')">${__('url_explorer.op_contains_label')}</div>
                            <div class="styled-select-item ${containsOp === 'not_contains' ? 'active' : ''}" data-value="not_contains" onclick="selectStyledOption(this, 'configContainsOperator')">${__('url_explorer.op_not_contains_label')}</div>
                        </div>
                    </div>
                </div>
                <div class="popover-row">
                    <label class="popover-label">${__('url_explorer.label_schema_types')}</label>
                    <div class="checkbox-actions" style="display:flex;gap:0.75rem;margin-bottom:0.35rem;font-size:0.75rem;">
                        <a href="#" onclick="event.preventDefault();document.querySelectorAll('.schema-checkbox').forEach(c=>c.checked=true);" style="color:var(--primary-color);text-decoration:none;">${__('url_explorer.check_all')}</a>
                        <span style="color:var(--text-secondary);">|</span>
                        <a href="#" onclick="event.preventDefault();document.querySelectorAll('.schema-checkbox').forEach(c=>c.checked=false);" style="color:var(--text-secondary);text-decoration:none;">${__('url_explorer.uncheck_all')}</a>
                    </div>
                    <div class="styled-checkbox-list" style="max-height: 200px;">
                        ${availableSchemas.map(schema => `
                            <label class="styled-checkbox-item">
                                <input type="checkbox" class="schema-checkbox" value="${schema}" ${selectedSchemas.includes(schema) ? 'checked' : ''}>
                                <span class="checkbox-box"><span class="material-symbols-outlined">check</span></span>
                                <span class="checkbox-label">${schema}</span>
                            </label>
                        `).join('')}
                    </div>
                </div>
            </div>
        `;
    } else if (config.type === 'category') {
        const op = existingChip?.operator || 'in';
        const selectedIds = existingChip?.value || [];
        html = `
            <div class="popover-row">
                <label class="popover-label">${__('url_explorer.label_condition')}</label>
                <div class="styled-select-wrapper">
                    <input type="hidden" id="configOperator" value="${op}">
                    <div class="styled-select-btn" onclick="toggleStyledSelect(this)">
                        <span class="select-value">${op === 'in' ? __('url_explorer.is_in') : __('url_explorer.is_not_in')}</span>
                        <span class="material-symbols-outlined">expand_more</span>
                    </div>
                    <div class="styled-select-menu">
                        <div class="styled-select-item ${op === 'in' ? 'active' : ''}" data-value="in" onclick="selectStyledOption(this, 'configOperator')">${__('url_explorer.is_in')}</div>
                        <div class="styled-select-item ${op === 'not_in' ? 'active' : ''}" data-value="not_in" onclick="selectStyledOption(this, 'configOperator')">${__('url_explorer.is_not_in')}</div>
                    </div>
                </div>
            </div>
            <div class="popover-row">
                <label class="popover-label">${__('url_explorer.label_categories')}</label>
                <div class="checkbox-actions" style="display:flex;gap:0.75rem;margin-bottom:0.35rem;font-size:0.75rem;">
                    <a href="#" onclick="event.preventDefault();document.querySelectorAll('.cat-checkbox').forEach(c=>c.checked=true);" style="color:var(--primary-color);text-decoration:none;">${__('url_explorer.check_all')}</a>
                    <span style="color:var(--text-secondary);">|</span>
                    <a href="#" onclick="event.preventDefault();document.querySelectorAll('.cat-checkbox').forEach(c=>c.checked=false);" style="color:var(--text-secondary);text-decoration:none;">${__('url_explorer.uncheck_all')}</a>
                </div>
                <div class="styled-checkbox-list">
                    ${availableCategories.map(cat => `
                        <label class="styled-checkbox-item">
                            <input type="checkbox" class="cat-checkbox" value="${cat.id}" ${selectedIds.includes(cat.id) ? 'checked' : ''}>
                            <span class="checkbox-box"><span class="material-symbols-outlined">check</span></span>
                            <span class="checkbox-label">${cat.cat}</span>
                        </label>
                    `).join('')}
                </div>
            </div>
        `;
    }
    
    html += `
        <div class="popover-actions">
            <button class="popover-btn popover-btn-secondary" onclick="closeAllPopovers()">${__('common.cancel')}</button>
            <button class="popover-btn popover-btn-primary" onclick="confirmFilter()">${__('common.apply')}</button>
        </div>
    `;
    
    content.innerHTML = html;
    
    // Positionner près du bouton + Filtre ou de la chip
    const anchor = document.querySelector('.btn-add-filter');
    positionPopover(popover, anchor);
    popover.classList.add('active');
    document.getElementById('popoverOverlay').classList.add('active');
    
    // Focus sur le premier input et listener Entrée
    setTimeout(() => {
        const firstInput = popover.querySelector('input[type="text"], input[type="number"]');
        if (firstInput) firstInput.focus();
        
        // Listener pour valider avec Entrée
        popover.querySelectorAll('input[type="text"], input[type="number"]').forEach(input => {
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    confirmFilter();
                }
            });
        });
    }, 50);
}

// Fonctions pour les selects stylisés
function toggleStyledSelect(btn) {
    event.stopPropagation();
    const menu = btn.nextElementSibling;
    const wasOpen = menu.classList.contains('show');
    
    // Fermer tous les autres menus
    document.querySelectorAll('.styled-select-menu.show').forEach(m => m.classList.remove('show'));
    
    if (!wasOpen) {
        menu.classList.add('show');
    }
}

function selectStyledOption(item, inputId) {
    const value = item.dataset.value;
    const wrapper = item.closest('.styled-select-wrapper');
    const input = document.getElementById(inputId);
    const btn = wrapper.querySelector('.styled-select-btn .select-value');
    
    input.value = value;
    btn.textContent = item.textContent;
    
    // Update active state
    wrapper.querySelectorAll('.styled-select-item').forEach(i => i.classList.remove('active'));
    item.classList.add('active');
    
    // Close menu
    item.closest('.styled-select-menu').classList.remove('show');
}

// Fermer les selects au clic ailleurs
htmxPageListener(document, 'click', function(e) {
    if (!e.target.closest('.styled-select-wrapper')) {
        document.querySelectorAll('.styled-select-menu.show').forEach(m => m.classList.remove('show'));
    }
});

function editChip(groupIndex, chipIndex, event) {
    event.stopPropagation();
    closeAllPopovers();
    
    const chip = filterGroups[groupIndex][chipIndex];
    editingChipIndex = { groupIndex, chipIndex };
    pendingFilterConfig = { field: chip.field };
    
    openConfigPopover(chip.field, chip);
}

function toggleSeoMode(mode) {
    document.getElementById('seoStatusMode').style.display = mode === 'status' ? '' : 'none';
    document.getElementById('seoValueMode').style.display = mode === 'value' ? '' : 'none';
}

function toggleHttpCodeMode(mode) {
    document.getElementById('httpCodeGroupMode').style.display = mode === 'group' ? '' : 'none';
    document.getElementById('httpCodeValueMode').style.display = mode === 'value' ? '' : 'none';
}

function toggleSchemasMode(mode) {
    document.getElementById('schemasCountMode').style.display = mode === 'count' ? '' : 'none';
    document.getElementById('schemasContainsMode').style.display = mode === 'contains' ? '' : 'none';
}

function confirmFilter() {
    const field = pendingFilterConfig.field;
    const config = fieldConfig[field];
    
    let operator = '=';
    let value = '';
    
    if (config.type === 'text' || config.type === 'number') {
        operator = document.getElementById('configOperator').value;
        value = document.getElementById('configValue').value;
        if (!value) { closeAllPopovers(); return; }
    } else if (config.type === 'boolean') {
        value = document.getElementById('configValue').value;
    } else if (config.type === 'http_code') {
        const filterMode = document.getElementById('configFilterMode').value;
        if (filterMode === 'value') {
            operator = document.getElementById('configOperator').value;
            value = document.getElementById('configValue').value;
            if (!value) { closeAllPopovers(); return; }
        } else {
            operator = 'group';
            const checkboxes = document.querySelectorAll('.httpcode-checkbox:checked');
            value = Array.from(checkboxes).map(cb => cb.value);
            if (value.length === 0) { closeAllPopovers(); return; }
            if (value.length === 1) value = value[0];
        }
    } else if (config.type === 'seo') {
        const filterMode = document.getElementById('configFilterMode').value;
        if (filterMode === 'value') {
            operator = document.getElementById('configOperator').value;
            value = document.getElementById('configValue').value;
            if (!value) { closeAllPopovers(); return; }
        } else {
            operator = 'status';
            const checkboxes = document.querySelectorAll('.seo-checkbox:checked');
            value = Array.from(checkboxes).map(cb => cb.value);
            if (value.length === 0) { closeAllPopovers(); return; }
            if (value.length === 1) value = value[0];
        }
    } else if (config.type === 'schemas') {
        const filterMode = document.getElementById('configFilterMode').value;
        if (filterMode === 'count') {
            operator = document.getElementById('configOperator').value;
            value = document.getElementById('configValue').value;
            if (value === '') { closeAllPopovers(); return; }
        } else {
            operator = document.getElementById('configContainsOperator').value;
            const checkboxes = document.querySelectorAll('.schema-checkbox:checked');
            value = Array.from(checkboxes).map(cb => cb.value);
            if (value.length === 0) { closeAllPopovers(); return; }
        }
    } else if (config.type === 'category') {
        operator = document.getElementById('configOperator').value;
        const checkboxes = document.querySelectorAll('.cat-checkbox:checked');
        value = Array.from(checkboxes).map(cb => parseInt(cb.value));
        if (value.length === 0) { closeAllPopovers(); return; }
    }
    
    const newChip = { field, operator, value };
    
    if (editingChipIndex) {
        // Édition d'une chip existante
        filterGroups[editingChipIndex.groupIndex][editingChipIndex.chipIndex] = newChip;
    } else if (pendingFilterConfig.addToGroup !== null) {
        // Ajout OU à un groupe existant
        filterGroups[pendingFilterConfig.addToGroup].push(newChip);
    } else {
        // Nouveau groupe (ET)
        filterGroups.push([newChip]);
    }
    
    closeAllPopovers();
    // Auto-ajoute la colonne correspondant au filtre (sauf en mode édition)
    applyFilters(editingChipIndex ? null : field);
}

function removeChip(groupIndex, chipIndex) {
    if (filterGroups[groupIndex].length === 1) {
        filterGroups.splice(groupIndex, 1);
    } else {
        filterGroups[groupIndex].splice(chipIndex, 1);
    }
    applyFilters();
}

function positionPopover(popover, anchor) {
    const rect = anchor.getBoundingClientRect();
    popover.style.top = (rect.bottom + window.scrollY + 8) + 'px';
    popover.style.left = Math.max(10, rect.left + window.scrollX - 50) + 'px';
}

function closeAllPopovers() {
    document.querySelectorAll('.filter-popover').forEach(p => p.classList.remove('active'));
    document.getElementById('popoverOverlay').classList.remove('active');
    editingChipIndex = null;
}

// ============================================
// COLLECT & APPLY FILTERS
// ============================================
function collectFiltersForURL() {
    // Convertir filterGroups vers l'ancien format pour compatibilité backend
    const filters = [];
    filterGroups.forEach((group, idx) => {
        if (group.length === 1) {
            // Groupe de 1 = condition simple dans un groupe ET
            filters.push({
                type: 'group',
                logic: 'AND',
                items: [{ type: 'condition', ...group[0] }],
                interGroupLogic: idx > 0 ? 'AND' : undefined
            });
        } else {
            // Groupe de plusieurs = conditions OU
            filters.push({
                type: 'group',
                logic: 'OR',
                items: group.map(c => ({ type: 'condition', ...c })),
                interGroupLogic: idx > 0 ? 'AND' : undefined
            });
        }
    });
    return filters;
}

function applyFilters(newColumn = null) {
    const filters = collectFiltersForURL();

    const params = new URLSearchParams(window.location.search);
    params.set('page', 'url-explorer');
    params.delete('p');
    // On écrit toujours le paramètre, même vide (`[]`) : ça signale au backend
    // que l'utilisateur a choisi "aucun filtre" et l'empêche de re-appliquer
    // le default (external = false) au reload.
    params.set('filters', JSON.stringify(filters));

    // Si un nouveau filtre vient d'être ajouté, on ajoute aussi la colonne
    // correspondante (si elle n'est pas déjà affichée) pour que l'utilisateur
    // voie la donnée filtrée dans le tableau.
    if (newColumn && !currentColumns.includes(newColumn)) {
        const updated = [...currentColumns, newColumn];
        params.set('columns', updated.join(','));
    }

    window.location.search = params.toString();
}

function clearFilters() {
    filterGroups = [];
    const params = new URLSearchParams(window.location.search);
    params.set('page', 'url-explorer');
    // Idem : on écrit `filters=[]` plutôt que de supprimer la clé, pour ne pas
    // ré-injecter le default côté PHP au prochain chargement.
    params.set('filters', '[]');
    params.delete('search');
    params.delete('p');
    window.location.search = params.toString();
}

// ============================================
// SEARCH
// ============================================
var searchTimeout;
document.getElementById('globalSearch').addEventListener('input', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        const params = new URLSearchParams(window.location.search);
        params.set('page', 'url-explorer');
        params.delete('p');
        if (this.value) {
            params.set('search', this.value);
        } else {
            params.delete('search');
        }
        window.location.search = params.toString();
    }, 500);
});

// Validation avec Entrée
document.getElementById('globalSearch').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        clearTimeout(searchTimeout);
        const params = new URLSearchParams(window.location.search);
        params.set('page', 'url-explorer');
        params.delete('p');
        if (this.value) {
            params.set('search', this.value);
        } else {
            params.delete('search');
        }
        window.location.search = params.toString();
    }
});

// ============================================
// AI-assisted filters : NL question → chips + auto-added columns
//
// Same Copilot-style popover as the SQL Explorer. The AI returns a list
// of `{field, operator, value}` objects, we push them as new AND groups
// into filterGroups, compute which columns need to be added so the user
// can actually SEE the data they just filtered on, then reload via
// applyFilters which already handles URL state.
// ============================================
function openAiUrlPopover() {
    const openBtn = document.getElementById('aiUrlOpenBtn');
    if (openBtn && openBtn.disabled) return;
    const popover = document.getElementById('aiUrlPopover');
    const input   = document.getElementById('aiUrlInput');
    if (!popover || !openBtn) return;

    // Anchor the popover just BELOW the button that opened it.
    const r = openBtn.getBoundingClientRect();
    const margin = 8;
    popover.style.display = 'flex';
    // Default to right-of-button left edge; clamp inside the viewport.
    const w = popover.offsetWidth || 560;
    let left = r.left;
    if (left + w + margin > window.innerWidth) {
        left = window.innerWidth - w - margin;
    }
    if (left < margin) left = margin;
    popover.style.left = left + 'px';
    popover.style.top  = (r.bottom + margin) + 'px';

    if (input) setTimeout(() => input.focus(), 30);
}

function closeAiUrlPopover() {
    const popover = document.getElementById('aiUrlPopover');
    if (popover) popover.style.display = 'none';
}

async function generateUrlFiltersFromQuestion() {
    const input   = document.getElementById('aiUrlInput');
    const btn     = document.getElementById('aiUrlGenerateBtn');
    const btnIcon = btn ? btn.querySelector('.ai-url-btn-icon') : null;
    const btnSpin = btn ? btn.querySelector('.ai-url-btn-spinner') : null;
    if (!input || !btn || btn.disabled) return;

    const question = input.value.trim();
    if (!question) { input.focus(); return; }

    btn.disabled   = true;
    input.disabled = true;
    if (btnIcon) btnIcon.style.display = 'none';
    if (btnSpin) btnSpin.style.display = 'inline-block';

    try {
        // Use crawl_id (integer, always in scope on this page) rather than
        // $projectDir whose local availability is fragile in url-explorer.php.
        const res = await fetch('../api/url-explorer/ai-filters', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                crawl_id: <?= (int)$crawlId ?>,
                question: question
            })
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            const msg = data.error || data.message || res.statusText || 'Unknown error';
            if (typeof showGlobalStatus === 'function') showGlobalStatus('IA : ' + msg, 'error');
            else alert('IA : ' + msg);
            return;
        }

        // The server returns `groups`: an array of arrays of chips.
        // Outer = AND (each becomes a filterGroup), inner = OR (chips inside it).
        const newGroups = Array.isArray(data.groups) ? data.groups : [];
        if (newGroups.length === 0) {
            if (typeof showGlobalStatus === 'function') {
                showGlobalStatus('<?= addslashes(__('url_explorer.ai_no_filters')) ?>', 'warning');
            }
            return;
        }

        // 1. Append each group to filterGroups as-is — the JS state already
        //    treats groups as AND'd, chips inside a group as OR'd.
        newGroups.forEach(grp => {
            if (!Array.isArray(grp) || grp.length === 0) return;
            const chips = grp.map(f => ({
                field: f.field,
                operator: f.operator || '=',
                value: f.value
            }));
            filterGroups.push(chips);
        });

        // 2. Compute new column list = current columns ∪ {every field used
        //    in the proposed groups}. For SEO fields (title/h1/metadesc),
        //    when the chip is a STATE filter (no operator, array value like
        //    ["empty","duplicate"]), the user really wants to see the
        //    *_status column to understand WHY a row matched — so we add it
        //    alongside the raw title/h1/metadesc text column.
        const stateFilterFields = ['title', 'h1', 'metadesc'];
        const isStateFilter = (chip) => {
            // STATE = no text operator + value is an array of state strings.
            const textOps = ['contains', 'not_contains', 'regex', 'not_regex'];
            return !textOps.includes(chip.operator) && Array.isArray(chip.value);
        };

        const newCols = [...currentColumns];
        newGroups.forEach(grp => {
            grp.forEach(f => {
                if (!f.field) return;
                if (!newCols.includes(f.field)) newCols.push(f.field);
                if (stateFilterFields.includes(f.field) && isStateFilter(f)) {
                    const statusCol = f.field + '_status';
                    if (!newCols.includes(statusCol)) newCols.push(statusCol);
                }
            });
        });

        // 3. Persist via URL — same idiom as applyFilters() but with N columns.
        const params = new URLSearchParams(window.location.search);
        params.set('page', 'url-explorer');
        params.delete('p');
        params.set('filters', JSON.stringify(collectFiltersForURL()));
        if (newCols.length !== currentColumns.length) {
            params.set('columns', newCols.join(','));
        }
        // Close the popover before reload so it doesn't flash on next render.
        input.value = '';
        closeAiUrlPopover();
        window.location.search = params.toString();
    } catch (e) {
        if (typeof showGlobalStatus === 'function') showGlobalStatus('IA : ' + e.message, 'error');
    } finally {
        btn.disabled = false;
        input.disabled = false;
        if (btnIcon) btnIcon.style.display = '';
        if (btnSpin) btnSpin.style.display = 'none';
    }
}

// ============================================
// INIT
// ============================================
htmxOnReady(function() {
    renderChips();

    // AI popover wiring : Ctrl+Enter to submit, Ctrl+K to toggle, Esc to close,
    // click outside to dismiss.
    const aiInput  = document.getElementById('aiUrlInput');
    const popover  = document.getElementById('aiUrlPopover');
    const openBtn  = document.getElementById('aiUrlOpenBtn');

    if (aiInput) {
        aiInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                generateUrlFiltersFromQuestion();
            }
        });
    }
    htmxPageListener(document, 'keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
            if (openBtn && openBtn.disabled) return;
            e.preventDefault();
            if (popover && popover.style.display === 'flex') {
                closeAiUrlPopover();
            } else {
                openAiUrlPopover();
            }
            return;
        }
        if (e.key === 'Escape' && popover && popover.style.display === 'flex') {
            closeAiUrlPopover();
        }
    });
    htmxPageListener(document, 'mousedown', function (e) {
        if (!popover || popover.style.display !== 'flex') return;
        if (popover.contains(e.target)) return;
        if (openBtn && openBtn.contains(e.target)) return;
        closeAiUrlPopover();
    });
});

// Fermer popovers avec Escape
htmxPageListener(document, 'keydown', function(e) {
    if (e.key === 'Escape') closeAllPopovers();
});

// ===== Bulk AI Generator — cible toutes les URLs du tableau filtré =====
//
// Pas de checkbox-selection : on prend simplement tous les `<tr data-page-id>`
// visibles dans la page courante (qui sont déjà le résultat des filtres
// appliqués côté serveur). Un badge de count à côté du libellé du bouton
// indique combien d'URLs seraient traitées au clic.

window.urlExplorerAiOk = <?= $urlExplorerAiConfigured ? 'true' : 'false' ?>;

function updateBulkBtn() {
    const btn = document.getElementById('bulkAiOpenBtn');
    if (!btn) return;
    const countEl = btn.querySelector('.bulk-count');
    const aiOk = window.urlExplorerAiOk;
    const n = document.querySelectorAll('tr[data-page-id]').length;
    btn.disabled = !aiOk || n === 0;
    if (countEl) countEl.textContent = n > 0 ? n : '';
    if (!aiOk) {
        btn.title = <?= json_encode(__('url_explorer.ai_not_configured')) ?>;
    } else if (n === 0) {
        btn.title = <?= json_encode(__('bulk_gen.err_no_visible')) ?>;
    } else {
        btn.title = (<?= json_encode(__('bulk_gen.selected_count')) ?>).replace('{n}', n);
    }
}

// Re-count whenever the table is re-rendered (sort, paginate, filter).
htmxOnReady(() => {
    updateBulkBtn();
    const tableWrap = document.querySelector('.table-scroll-area') ||
                      document.querySelector('table');
    if (tableWrap) {
        new MutationObserver(updateBulkBtn).observe(tableWrap, { childList: true, subtree: true });
    }
});

function openBulkAiModal() {
    // The WHOLE filtered set (every matching URL, all pages) — resolved
    // server-side from the exact same filter as the table. Falls back to the
    // displayed rows only if that list isn't available.
    let pageIds = Array.isArray(window.__bulkAllFilteredPageIds) ? window.__bulkAllFilteredPageIds.slice() : null;
    if (!pageIds) {
        pageIds = [];
        document.querySelectorAll('tr[data-page-id]').forEach(r => { if (r.dataset.pageId) pageIds.push(r.dataset.pageId); });
    }
    if (pageIds.length === 0) {
        alert(<?= json_encode(__('bulk_gen.err_no_visible')) ?>);
        return;
    }
    if (!window.scouterBulkAi) {
        alert('Bulk AI modal not loaded');
        return;
    }
    window.scouterBulkAi.open({
        crawlId:    <?= (int)$crawlId ?>,
        crawlPath:  <?= json_encode($_GET['project'] ?? $_GET['dir'] ?? '') ?>,
        pageIds:    pageIds,
        totalShown: pageIds.length,
    });
}
</script>

<?php include __DIR__ . '/../components/bulk-ai-modal.php'; ?>
