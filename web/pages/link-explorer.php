<?php
/**
 * Link Explorer (PostgreSQL)
 * $crawlId est défini dans dashboard.php
 */

// Récupération des filtres
$filters = isset($_GET['filters']) ? json_decode($_GET['filters'], true) : [];
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Récupération des catégories disponibles
$stmt = $pdo->prepare("SELECT id, cat FROM categories WHERE crawl_id = :crawl_id ORDER BY cat");
$stmt->execute([':crawl_id' => $crawlId]);
$availableCategories = $stmt->fetchAll(PDO::FETCH_OBJ);

// Récupération des types de schemas disponibles
$stmt = $pdo->prepare("SELECT DISTINCT schema_type FROM page_schemas WHERE crawl_id = :crawl_id ORDER BY schema_type");
$stmt->execute([':crawl_id' => $crawlId]);
$availableSchemas = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Construction de la clause WHERE
$whereConditions = ["1=1"];
$params = [];

// Recherche globale
if(!empty($search)) {
    $whereConditions[] = "(cs.url LIKE :search OR ct.url LIKE :search)";
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
            $target = isset($item['target']) ? $item['target'] : 'source'; // source ou target
            $condition = '';
            
            // Déterminer le préfixe de table selon target
            $tablePrefix = ($target === 'target') ? 'ct' : 'cs';
            $extractPrefix = ($target === 'target') ? 'et' : 'es';
            $catPrefix = ($target === 'target') ? 'catt' : 'cats';
            
            switch($field) {
                case 'anchor':
                    $paramName = ':anchor_' . $paramCounter++;
                    if($operator === 'contains') {
                        $condition = "l.anchor LIKE {$paramName}";
                        $params[$paramName] = '%' . $value . '%';
                    } elseif($operator === 'not_contains') {
                        $condition = "l.anchor NOT LIKE {$paramName}";
                        $params[$paramName] = '%' . $value . '%';
                    } elseif($operator === 'regex') {
                        $condition = "l.anchor ~ {$paramName}";
                        $params[$paramName] = $value;
                    } elseif($operator === 'not_regex') {
                        $condition = "l.anchor !~ {$paramName}";
                        $params[$paramName] = $value;
                    }
                    break;
                    
                case 'external':
                    // internal = false, external = true
                    $condition = "l.external = " . ($value === 'external' ? 'true' : 'false');
                    break;
                    
                case 'self_link':
                    // Self-link: source = target (même page)
                    $condition = "l.src = l.target";
                    break;
                    
                case 'link_nofollow':
                    // dofollow = false, nofollow = true
                    $condition = "l.nofollow = " . ($value === 'nofollow' ? 'true' : 'false');
                    break;
                    
                case 'type':
                    // Support multi-valeurs
                    if(is_array($value)) {
                        $placeholders = [];
                        foreach($value as $v) {
                            $paramName = ':type_' . $paramCounter++;
                            $placeholders[] = $paramName;
                            $params[$paramName] = $v;
                        }
                        $condition = "l.type IN (" . implode(',', $placeholders) . ")";
                    } else {
                        $paramName = ':type_' . $paramCounter++;
                        $condition = "l.type = {$paramName}";
                        $params[$paramName] = $value;
                    }
                    break;
                    
                case 'url':
                    $paramName = ':url_' . $paramCounter++;
                    if($operator === 'contains') {
                        $condition = "{$tablePrefix}.url LIKE {$paramName}";
                        $params[$paramName] = '%' . $value . '%';
                    } elseif($operator === 'not_contains') {
                        $condition = "{$tablePrefix}.url NOT LIKE {$paramName}";
                        $params[$paramName] = '%' . $value . '%';
                    } elseif($operator === 'regex') {
                        $condition = "{$tablePrefix}.url ~ {$paramName}";
                        $params[$paramName] = $value;
                    } elseif($operator === 'not_regex') {
                        $condition = "{$tablePrefix}.url !~ {$paramName}";
                        $params[$paramName] = $value;
                    }
                    break;
                    
                case 'category':
                    if(!empty($value) && is_array($value)) {
                        $catIds = array_map('intval', $value);
                        $placeholders = [];
                        foreach($catIds as $catId) {
                            $paramName = ':cat_' . $paramCounter++;
                            $placeholders[] = $paramName;
                            $params[$paramName] = $catId;
                        }
                        if($operator === 'not_in') {
                            $condition = "({$tablePrefix}.cat_id NOT IN (" . implode(',', $placeholders) . ") OR {$tablePrefix}.cat_id IS NULL)";
                        } else {
                            $condition = "{$tablePrefix}.cat_id IN (" . implode(',', $placeholders) . ")";
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
                    
                    $condition = "{$tablePrefix}.{$field} {$sqlOperator} {$paramName}";
                    $params[$paramName] = intval($value);
                    break;
                    
                case 'code':
                    // Mode "valeur" - opérateur numérique
                    if(in_array($operator, ['=', '>', '<', '>=', '<=', '!='])) {
                        $paramName = ':code_' . $paramCounter++;
                        $sqlOperator = $operator;
                        $condition = "{$tablePrefix}.code {$sqlOperator} {$paramName}";
                        $params[$paramName] = intval($value);
                    }
                    // Mode "groupe" - plage de codes
                    else {
                        $groups = is_array($value) ? $value : [$value];
                        $groupConditions = [];
                        foreach($groups as $g) {
                            if($g === '1xx') $groupConditions[] = "({$tablePrefix}.code >= 100 AND {$tablePrefix}.code <= 199)";
                            elseif($g === '2xx') $groupConditions[] = "({$tablePrefix}.code >= 200 AND {$tablePrefix}.code <= 299)";
                            elseif($g === '3xx') $groupConditions[] = "({$tablePrefix}.code >= 300 AND {$tablePrefix}.code <= 399)";
                            elseif($g === '4xx') $groupConditions[] = "({$tablePrefix}.code >= 400 AND {$tablePrefix}.code <= 499)";
                            elseif($g === '5xx') $groupConditions[] = "({$tablePrefix}.code >= 500 AND {$tablePrefix}.code <= 599)";
                            elseif($g === 'other') $groupConditions[] = "({$tablePrefix}.code < 100 OR {$tablePrefix}.code >= 600)";
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
                        $condition = "COALESCE(array_length({$tablePrefix}.schemas, 1), 0) {$sqlOperator} {$paramName}";
                        $params[$paramName] = intval($value);
                    }
                    // Mode "contains" - filtrer par types spécifiques
                    elseif($operator === 'contains' && is_array($value) && !empty($value)) {
                        $schemaConditions = [];
                        foreach($value as $schemaType) {
                            $paramName = ':schema_' . $paramCounter++;
                            $schemaConditions[] = "{$paramName} = ANY({$tablePrefix}.schemas)";
                            $params[$paramName] = $schemaType;
                        }
                        $condition = '(' . implode(' OR ', $schemaConditions) . ')';
                    }
                    elseif($operator === 'not_contains' && is_array($value) && !empty($value)) {
                        $schemaConditions = [];
                        foreach($value as $schemaType) {
                            $paramName = ':schema_' . $paramCounter++;
                            $schemaConditions[] = "NOT ({$paramName} = ANY({$tablePrefix}.schemas))";
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
                    $condition = "{$tablePrefix}.{$field} = " . ($value === 'true' ? 'true' : 'false');
                    break;
                    
                case 'title':
                case 'h1':
                case 'metadesc':
                    $colName = ($field === 'meta_desc') ? 'metadesc' : $field;
                    
                    // Mode "valeur" (texte) - filtrer sur le contenu
                    if(in_array($operator, ['contains', 'not_contains', 'regex', 'not_regex'])) {
                        $paramName = ':seo_' . $paramCounter++;
                        if($operator === 'contains') {
                            $condition = "{$tablePrefix}.{$colName} ILIKE {$paramName}";
                            $params[$paramName] = '%' . $value . '%';
                        } elseif($operator === 'not_contains') {
                            $condition = "({$tablePrefix}.{$colName} NOT ILIKE {$paramName} OR {$tablePrefix}.{$colName} IS NULL)";
                            $params[$paramName] = '%' . $value . '%';
                        } elseif($operator === 'regex') {
                            $condition = "{$tablePrefix}.{$colName} ~* {$paramName}";
                            $params[$paramName] = $value;
                        } elseif($operator === 'not_regex') {
                            $condition = "({$tablePrefix}.{$colName} !~* {$paramName} OR {$tablePrefix}.{$colName} IS NULL)";
                            $params[$paramName] = $value;
                        }
                    }
                    // Mode "état" - filtrer sur le statut (unique/empty/duplicate)
                    elseif(is_array($value)) {
                        $statusConditions = [];
                        foreach($value as $v) {
                            if(in_array($v, ['empty', 'duplicate', 'unique'])) {
                                $statusConditions[] = "{$tablePrefix}.{$colName}_status = '{$v}'";
                            }
                        }
                        if(!empty($statusConditions)) {
                            $condition = '(' . implode(' OR ', $statusConditions) . ')';
                        }
                    } else {
                        if(in_array($value, ['empty', 'duplicate', 'unique'])) {
                            $condition = "{$tablePrefix}.{$colName}_status = '{$value}'";
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
$selectedColumns = isset($_GET['columns']) ? explode(',', $_GET['columns']) : ['url', 'code','external', 'nofollow', 'type'];
?>

<style>
/* Smart Filter Bar Styles - Link Explorer */
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
    position: relative;
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
.filter-chip .chip-target {
    font-size: 0.65rem;
    font-weight: 700;
    padding: 0.1rem 0.3rem;
    border-radius: 3px;
    text-transform: uppercase;
}
.filter-chip .chip-target.source { background: #e3f2fd; color: #1976d2; }
.filter-chip .chip-target.target { background: #fff3e0; color: #e65100; }
.filter-chip .chip-target.link { background: #f3e5f5; color: #7b1fa2; }
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

/* AND separator */
.chip-and-separator {
    padding: 0 0.5rem;
    font-size: 0.7rem;
    font-weight: 600;
    color: #7F8C8D;
    text-transform: uppercase;
}

/* Bouton +ou sur chaque chip */
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
.chip-add-or::after {
    content: 'Ajouter une condition OU';
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

/* Popover */
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
    min-width: 300px;
    max-width: 380px;
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

/* Field list with sections */
.popover-section-title {
    font-size: 0.7rem;
    font-weight: 700;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 0.5rem 0.6rem 0.25rem;
    margin-top: 0.25rem;
}
.popover-field-list {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    max-height: 320px;
    overflow-y: auto;
}
.popover-field-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.45rem 0.6rem;
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

/* Styled Select */
.styled-select-wrapper { position: relative; }
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

/* Styled Checkboxes */
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
.styled-checkbox-item input[type="checkbox"] { display: none; }
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
.styled-checkbox-item input:checked + .checkbox-box .material-symbols-outlined { opacity: 1; }
.styled-checkbox-item .checkbox-label {
    flex: 1;
    color: var(--text-primary);
}

/* Target selector in popover */
.target-selector {
    display: flex;
    gap: 0.5rem;
}
.target-btn {
    flex: 1;
    padding: 0.5rem;
    border: 2px solid var(--border-color);
    border-radius: 6px;
    background: white;
    cursor: pointer;
    font-size: 0.85rem;
    font-weight: 500;
    transition: all 0.15s;
    text-align: center;
}
.target-btn:hover { border-color: var(--primary-color); }
.target-btn.active.source { background: #e3f2fd; border-color: #1976d2; color: #1976d2; }
.target-btn.active.target { background: #fff3e0; border-color: #e65100; color: #e65100; }
</style>

<h1 class="page-title">Link Explorer</h1>

<!-- Smart Filter Bar -->
<div class="smart-filter-bar">
    <!-- Search Input -->
    <div class="smart-search-wrapper">
        <span class="material-symbols-outlined search-icon">search</span>
        <input type="text" id="globalSearch" placeholder="Rechercher un lien..." value="<?= htmlspecialchars($search) ?>">
    </div>
    
    <!-- Filter Chips -->
    <div class="filter-chips-container" id="filterChipsContainer"></div>
    
    <!-- Add Filter Button -->
    <button class="btn-add-filter" onclick="openFieldSelector(event)">
        <span class="material-symbols-outlined">add</span>
        Filtre
    </button>
    
    <!-- Clear All -->
    <button class="btn-clear-filters" id="btnClearAll" style="display: none;" onclick="clearFilters()">
        <span class="material-symbols-outlined">close</span>
        Tout effacer
    </button>
</div>

<!-- Popover Overlay -->
<div class="filter-popover-overlay" id="popoverOverlay" onclick="closeAllPopovers()"></div>

<!-- Field Selector Popover -->
<div class="filter-popover" id="fieldSelectorPopover">
    <div class="popover-header">
        <span class="popover-title">Ajouter un filtre</span>
        <button class="popover-close" onclick="closeAllPopovers()">
            <span class="material-symbols-outlined">close</span>
        </button>
    </div>
    <div class="popover-field-list">
        <div class="popover-section-title">Propriétés du lien</div>
        <div class="popover-field-item" onclick="selectField('anchor', 'link')">
            <span class="material-symbols-outlined">text_fields</span> Ancre du lien
        </div>
        <div class="popover-field-item" onclick="selectField('type', 'link')">
            <span class="material-symbols-outlined">category</span> Type de lien
        </div>
        <div class="popover-field-item" onclick="selectField('external', 'link')">
            <span class="material-symbols-outlined">open_in_new</span> Interne/Externe
        </div>
        <div class="popover-field-item" onclick="selectField('link_nofollow', 'link')">
            <span class="material-symbols-outlined">link_off</span> Dofollow/Nofollow
        </div>
        <div class="popover-field-item" onclick="addSelfLinkFilter()">
            <span class="material-symbols-outlined">sync</span> Self-link (autolien)
        </div>
        
        <div class="popover-section-title">Page source ou cible</div>
        <div class="popover-field-item" onclick="selectField('url', 'page')">
            <span class="material-symbols-outlined">link</span> URL
        </div>
        <div class="popover-field-item" onclick="selectField('category', 'page')">
            <span class="material-symbols-outlined">label</span> Catégorie
        </div>
        <div class="popover-field-item" onclick="selectField('depth', 'page')">
            <span class="material-symbols-outlined">layers</span> Profondeur
        </div>
        <div class="popover-field-item" onclick="selectField('code', 'page')">
            <span class="material-symbols-outlined">http</span> Code HTTP
        </div>
        <div class="popover-field-item" onclick="selectField('compliant', 'page')">
            <span class="material-symbols-outlined">verified</span> Indexable
        </div>
        <div class="popover-field-item" onclick="selectField('canonical', 'page')">
            <span class="material-symbols-outlined">content_copy</span> Canonical
        </div>
        <div class="popover-field-item" onclick="selectField('title', 'page')">
            <span class="material-symbols-outlined">title</span> Title
        </div>
        <div class="popover-field-item" onclick="selectField('h1', 'page')">
            <span class="material-symbols-outlined">format_h1</span> H1
        </div>
        <div class="popover-field-item" onclick="selectField('metadesc', 'page')">
            <span class="material-symbols-outlined">description</span> Meta Description
        </div>
        <div class="popover-field-item" onclick="selectField('inlinks', 'page')">
            <span class="material-symbols-outlined">arrow_back</span> Liens entrants
        </div>
        <div class="popover-field-item" onclick="selectField('outlinks', 'page')">
            <span class="material-symbols-outlined">arrow_forward</span> Liens sortants
        </div>
        <div class="popover-field-item" onclick="selectField('noindex', 'page')">
            <span class="material-symbols-outlined">block</span> Noindex
        </div>
        <div class="popover-field-item" onclick="selectField('nofollow', 'page')">
            <span class="material-symbols-outlined">link_off</span> Nofollow (page)
        </div>
        <div class="popover-field-item" onclick="selectField('blocked', 'page')">
            <span class="material-symbols-outlined">dangerous</span> Bloqué robots.txt
        </div>
        <div class="popover-field-item" onclick="selectField('h1_multiple', 'page')">
            <span class="material-symbols-outlined">format_h1</span> H1 multiples
        </div>
        <div class="popover-field-item" onclick="selectField('headings_missing', 'page')">
            <span class="material-symbols-outlined">format_list_numbered</span> Mauvaise structure hn
        </div>
        <div class="popover-field-item" onclick="selectField('schemas', 'page')">
            <span class="material-symbols-outlined">data_object</span> Données structurées
        </div>
        <div class="popover-field-item" onclick="selectField('response_time', 'page')">
            <span class="material-symbols-outlined">speed</span> TTFB (ms)
        </div>
        <div class="popover-field-item" onclick="selectField('word_count', 'page')">
            <span class="material-symbols-outlined">format_size</span> Nb mots
        </div>
    </div>
</div>

<!-- Filter Config Popover -->
<div class="filter-popover" id="filterConfigPopover">
    <div class="popover-header">
        <span class="popover-title" id="configPopoverTitle">Configurer le filtre</span>
        <button class="popover-close" onclick="closeAllPopovers()">
            <span class="material-symbols-outlined">close</span>
        </button>
    </div>
    <div class="popover-config" id="popoverConfigContent"></div>
</div>

<!-- Utilisation du composant URL Table -->
<?php
$linkTableConfig = [
    'title' => 'Liens trouvés',
    'id' => 'main_explorer',
    'whereClause' => 'WHERE ' . $whereClause,
    'orderBy' => 'ORDER BY cs.url',
    'sqlParams' => $params,
    'defaultColumns' => $selectedColumns,
    'pdo' => $pdo,
    'crawlId' => $crawlId,
    'projectDir' => $_GET['project'] ?? ''
];

include __DIR__ . '/../components/link-table.php';
?>

<script>
// ============================================
// SMART FILTER BAR - Link Explorer
// ============================================
const availableCategories = <?= json_encode($availableCategories) ?>;

// Configuration des champs
const fieldConfig = {
    // Champs liés au lien (pas de source/target)
    anchor: { label: 'Ancre', icon: 'text_fields', type: 'text', scope: 'link', operators: ['contains', 'not_contains', 'regex', 'not_regex'] },
    external: { label: 'Interne/Externe', icon: 'open_in_new', type: 'internal_external', scope: 'link' },
    link_nofollow: { label: 'Dofollow/Nofollow', icon: 'link_off', type: 'dofollow_nofollow', scope: 'link' },
    type: { label: 'Type de lien', icon: 'category', type: 'link_type', scope: 'link', values: ['ahref', 'canonical', 'redirect'] },
    self_link: { label: 'Self-link', icon: 'sync', type: 'instant', scope: 'link' },
    // Champs liés à la page (source ou target)
    url: { label: 'URL', icon: 'link', type: 'text', scope: 'page', operators: ['contains', 'not_contains', 'regex', 'not_regex'] },
    category: { label: 'Catégorie', icon: 'label', type: 'category', scope: 'page', operators: ['in', 'not_in'] },
    depth: { label: 'Profondeur', icon: 'layers', type: 'number', scope: 'page', operators: ['=', '>', '<', '>=', '<=', '!='] },
    code: { label: 'Code HTTP', icon: 'http', type: 'http_code', scope: 'page', values: ['1xx', '2xx', '3xx', '4xx', '5xx', 'other'], operators: ['=', '>', '<', '>=', '<=', '!='] },
    compliant: { label: 'Indexable', icon: 'verified', type: 'boolean', scope: 'page' },
    canonical: { label: 'Canonical', icon: 'content_copy', type: 'boolean', scope: 'page' },
    title: { label: 'Title', icon: 'title', type: 'seo', scope: 'page', values: ['unique', 'empty', 'duplicate'], operators: ['contains', 'not_contains', 'regex', 'not_regex'] },
    h1: { label: 'H1', icon: 'format_h1', type: 'seo', scope: 'page', values: ['unique', 'empty', 'duplicate'], operators: ['contains', 'not_contains', 'regex', 'not_regex'] },
    metadesc: { label: 'Meta Desc', icon: 'description', type: 'seo', scope: 'page', values: ['unique', 'empty', 'duplicate'], operators: ['contains', 'not_contains', 'regex', 'not_regex'] },
    inlinks: { label: 'Liens entrants', icon: 'arrow_back', type: 'number', scope: 'page', operators: ['=', '>', '<', '>=', '<=', '!='] },
    outlinks: { label: 'Liens sortants', icon: 'arrow_forward', type: 'number', scope: 'page', operators: ['=', '>', '<', '>=', '<=', '!='] },
    noindex: { label: 'Noindex', icon: 'block', type: 'boolean', scope: 'page' },
    nofollow: { label: 'Nofollow', icon: 'link_off', type: 'boolean', scope: 'page' },
    blocked: { label: 'Bloqué', icon: 'dangerous', type: 'boolean', scope: 'page' },
    h1_multiple: { label: 'H1 multiples', icon: 'format_h1', type: 'boolean', scope: 'page' },
    headings_missing: { label: 'Mauvaise structure hn', icon: 'format_list_numbered', type: 'boolean', scope: 'page' },
    schemas: { label: 'Données structurées', icon: 'data_object', type: 'schemas', scope: 'page', operators: ['=', '>', '<', '>=', '<=', 'contains', 'not_contains'] },
    response_time: { label: 'TTFB (ms)', icon: 'speed', type: 'number', scope: 'page', operators: ['>', '<', '>=', '<='] },
    word_count: { label: 'Nb mots', icon: 'format_size', type: 'number', scope: 'page', operators: ['=', '>', '<', '>=', '<=', '!='] }
};

const availableSchemas = <?= json_encode($availableSchemas) ?>;

const operatorLabels = {
    'contains': 'contient', 'not_contains': 'ne contient pas',
    'regex': 'correspond à la regex', 'not_regex': 'ne correspond pas à la regex',
    '=': '=', '>': '>', '<': '<', '>=': '≥', '<=': '≤', '!=': '≠',
    'in': 'est', 'not_in': "n'est pas"
};
const seoValueLabels = { 'unique': 'Unique', 'empty': 'Vide', 'duplicate': 'Dupliqué' };
const httpCodeLabels = { '1xx': '1xx (100-199)', '2xx': '2xx (200-299)', '3xx': '3xx (300-399)', '4xx': '4xx (400-499)', '5xx': '5xx (500-599)', 'other': 'Autre' };
const boolLabels = { 'true': 'Oui', 'false': 'Non' };
const internalExternalLabels = { 'external': 'Externe', 'internal': 'Interne' };
const dofollowNofollowLabels = { 'nofollow': 'Nofollow', 'dofollow': 'Dofollow' };
const linkTypeLabels = { 'ahref': 'Ahref', 'canonical': 'Canonical', 'redirect': 'Redirection' };
const targetLabels = { 'source': 'Source', 'target': 'Cible', 'link': 'Lien' };

let filterGroups = [];
let pendingFilterConfig = null;
let editingChipIndex = null;

// Charger les filtres depuis l'URL
const currentFilters = <?= json_encode($filters) ?>;
if (currentFilters && currentFilters.length > 0) {
    filterGroups = convertOldFiltersToNew(currentFilters);
}

function convertOldFiltersToNew(oldFilters) {
    const groups = [];
    oldFilters.forEach(item => {
        if (item.type === 'group' && item.items) {
            if (item.logic === 'OR') {
                const chips = item.items.filter(i => i.field).map(i => ({
                    field: i.field, operator: i.operator || '=', value: i.value, target: i.target || 'source'
                }));
                if (chips.length > 0) groups.push(chips);
            } else {
                item.items.filter(i => i.field).forEach(i => {
                    groups.push([{ field: i.field, operator: i.operator || '=', value: i.value, target: i.target || 'source' }]);
                });
            }
        } else if (item.field) {
            groups.push([{ field: item.field, operator: item.operator || '=', value: item.value, target: item.target || 'source' }]);
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
            const andSep = document.createElement('span');
            andSep.className = 'chip-and-separator';
            andSep.textContent = 'et';
            container.appendChild(andSep);
        }
        
        if (group.length === 1) {
            container.appendChild(createChipElement(group[0], groupIndex, 0));
        } else {
            const chipGroup = document.createElement('div');
            chipGroup.className = 'chip-group';
            group.forEach((chip, chipIndex) => {
                if (chipIndex > 0) {
                    const orConn = document.createElement('span');
                    orConn.className = 'chip-or-connector';
                    orConn.textContent = 'ou';
                    chipGroup.appendChild(orConn);
                }
                chipGroup.appendChild(createChipElement(chip, groupIndex, chipIndex));
            });
            container.appendChild(chipGroup);
        }
    });
    
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
    const targetClass = config.scope === 'link' ? 'link' : chip.target;
    const targetLabel = config.scope === 'link' ? 'Lien' : (chip.target === 'target' ? 'Cible' : 'Source');
    let displayValue = formatChipValue(chip);
    
    // Pour les filtres instantanés (self-link), ne pas afficher la valeur
    const valueHtml = displayValue ? `<span class="chip-value">${displayValue}</span>` : '';
    
    el.innerHTML = `
        <span class="chip-target ${targetClass}">${targetLabel}</span>
        <span class="chip-field">${config.label}</span>
        ${valueHtml}
        <span class="chip-remove material-symbols-outlined" onclick="event.stopPropagation(); removeChip(${groupIndex}, ${chipIndex})">close</span>
        <span class="chip-add-or" onclick="event.stopPropagation(); addOrToChip(${groupIndex}, event)">
            <span class="material-symbols-outlined">add</span>
        </span>
    `;
    return el;
}

function formatChipValue(chip) {
    const config = fieldConfig[chip.field];
    if (!config) return chip.value;
    
    if (config.type === 'boolean') {
        return boolLabels[chip.value] || chip.value;
    } else if (config.type === 'internal_external') {
        return internalExternalLabels[chip.value] || chip.value;
    } else if (config.type === 'dofollow_nofollow') {
        return dofollowNofollowLabels[chip.value] || chip.value;
    } else if (config.type === 'link_type') {
        if (Array.isArray(chip.value)) {
            const labels = chip.value.map(v => linkTypeLabels[v] || v);
            return labels.length > 2 ? labels.slice(0,2).join(', ') + '...' : labels.join(', ');
        }
        return linkTypeLabels[chip.value] || chip.value;
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
    } else if (config.type === 'instant') {
        // Filtres instantanés sans valeur à afficher (self-link, etc.)
        return '';
    }
    return chip.value;
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

// ============================================
// POPOVER MANAGEMENT
// ============================================
function openFieldSelector(event) {
    event.stopPropagation();
    closeAllPopovers();
    editingChipIndex = null;
    pendingFilterConfig = { addToGroup: null };
    
    const popover = document.getElementById('fieldSelectorPopover');
    positionPopover(popover, event.currentTarget);
    popover.classList.add('active');
    document.getElementById('popoverOverlay').classList.add('active');
}

// Ajoute directement le filtre self-link sans boîte de dialogue
function addSelfLinkFilter() {
    closeAllPopovers();
    
    // Vérifier si le filtre existe déjà
    const exists = filterGroups.some(group => 
        group.some(chip => chip.field === 'self_link')
    );
    
    if (exists) {
        return; // Ne pas ajouter en double
    }
    
    const newChip = { field: 'self_link', operator: '=', value: true, target: 'link' };
    filterGroups.push([newChip]);
    applyFilters();
}

function selectField(field, scope) {
    closeAllPopovers();
    const config = fieldConfig[field];
    pendingFilterConfig = { 
        ...pendingFilterConfig, 
        field, 
        needsTarget: scope === 'page',
        target: scope === 'link' ? 'link' : 'source'
    };
    openConfigPopover(field);
}

function openConfigPopover(field, existingChip = null) {
    const config = fieldConfig[field];
    const popover = document.getElementById('filterConfigPopover');
    const content = document.getElementById('popoverConfigContent');
    document.getElementById('configPopoverTitle').textContent = config.label;
    
    let html = '';
    
    // Sélecteur Source/Cible si nécessaire
    if (config.scope === 'page') {
        const currentTarget = existingChip?.target || pendingFilterConfig.target || 'source';
        html += `
            <div class="popover-row">
                <label class="popover-label">Appliquer à</label>
                <div class="target-selector">
                    <button type="button" class="target-btn source ${currentTarget === 'source' ? 'active' : ''}" onclick="selectTarget('source')">Source</button>
                    <button type="button" class="target-btn target ${currentTarget === 'target' ? 'active' : ''}" onclick="selectTarget('target')">Cible</button>
                </div>
                <input type="hidden" id="configTarget" value="${currentTarget}">
            </div>
        `;
    }
    
    if (config.type === 'text') {
        const op = existingChip?.operator || 'contains';
        const val = existingChip?.value || '';
        const opLabels = { 'contains': 'Contient', 'not_contains': 'Ne contient pas', 'regex': 'Regex', 'not_regex': 'Pas regex' };
        html += `
            <div class="popover-row">
                <label class="popover-label">Condition</label>
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
                <label class="popover-label">Valeur</label>
                <input type="text" class="popover-input" id="configValue" placeholder="Texte..." value="${val}">
            </div>
        `;
    } else if (config.type === 'number') {
        const op = existingChip?.operator || (config.operators[0] || '>');
        const val = existingChip?.value || '';
        html += `
            <div class="popover-row">
                <label class="popover-label">Opérateur</label>
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
                <label class="popover-label">Valeur</label>
                <input type="number" class="popover-input" id="configValue" placeholder="Nombre..." value="${val}">
            </div>
        `;
    } else if (config.type === 'boolean') {
        const val = existingChip?.value || 'true';
        html += `
            <div class="popover-row">
                <label class="popover-label">Valeur</label>
                <div class="styled-select-wrapper">
                    <input type="hidden" id="configValue" value="${val}">
                    <div class="styled-select-btn" onclick="toggleStyledSelect(this)">
                        <span class="select-value">${val === 'true' ? 'Oui' : 'Non'}</span>
                        <span class="material-symbols-outlined">expand_more</span>
                    </div>
                    <div class="styled-select-menu">
                        <div class="styled-select-item ${val === 'true' ? 'active' : ''}" data-value="true" onclick="selectStyledOption(this, 'configValue')">Oui</div>
                        <div class="styled-select-item ${val === 'false' ? 'active' : ''}" data-value="false" onclick="selectStyledOption(this, 'configValue')">Non</div>
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
        
        html += `
            <div class="popover-row">
                <label class="popover-label">Filtrer par</label>
                <div class="styled-select-wrapper">
                    <input type="hidden" id="configFilterMode" value="${filterMode}">
                    <div class="styled-select-btn" onclick="toggleStyledSelect(this)">
                        <span class="select-value">${filterMode === 'group' ? 'Groupe de codes' : 'Valeur exacte'}</span>
                        <span class="material-symbols-outlined">expand_more</span>
                    </div>
                    <div class="styled-select-menu">
                        <div class="styled-select-item ${filterMode === 'group' ? 'active' : ''}" data-value="group" onclick="selectStyledOption(this, 'configFilterMode'); toggleHttpCodeMode('group')">Groupe de codes</div>
                        <div class="styled-select-item ${filterMode === 'value' ? 'active' : ''}" data-value="value" onclick="selectStyledOption(this, 'configFilterMode'); toggleHttpCodeMode('value')">Valeur exacte</div>
                    </div>
                </div>
            </div>
            <div id="httpCodeGroupMode" style="${filterMode === 'group' ? '' : 'display:none'}">
                <div class="popover-row">
                    <label class="popover-label">Groupes (plusieurs possibles)</label>
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
                    <label class="popover-label">Opérateur</label>
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
                    <label class="popover-label">Code</label>
                    <input type="number" class="popover-input" id="configValue" placeholder="Ex: 200, 404..." value="${numVal}">
                </div>
            </div>
        `;
    } else if (config.type === 'internal_external') {
        const val = existingChip?.value || 'external';
        html += `
            <div class="popover-row">
                <label class="popover-label">Type</label>
                <div class="styled-select-wrapper">
                    <input type="hidden" id="configValue" value="${val}">
                    <div class="styled-select-btn" onclick="toggleStyledSelect(this)">
                        <span class="select-value">${internalExternalLabels[val]}</span>
                        <span class="material-symbols-outlined">expand_more</span>
                    </div>
                    <div class="styled-select-menu">
                        <div class="styled-select-item ${val === 'internal' ? 'active' : ''}" data-value="internal" onclick="selectStyledOption(this, 'configValue')">Interne</div>
                        <div class="styled-select-item ${val === 'external' ? 'active' : ''}" data-value="external" onclick="selectStyledOption(this, 'configValue')">Externe</div>
                    </div>
                </div>
            </div>
        `;
    } else if (config.type === 'dofollow_nofollow') {
        const val = existingChip?.value || 'nofollow';
        html += `
            <div class="popover-row">
                <label class="popover-label">Type</label>
                <div class="styled-select-wrapper">
                    <input type="hidden" id="configValue" value="${val}">
                    <div class="styled-select-btn" onclick="toggleStyledSelect(this)">
                        <span class="select-value">${dofollowNofollowLabels[val]}</span>
                        <span class="material-symbols-outlined">expand_more</span>
                    </div>
                    <div class="styled-select-menu">
                        <div class="styled-select-item ${val === 'dofollow' ? 'active' : ''}" data-value="dofollow" onclick="selectStyledOption(this, 'configValue')">Dofollow</div>
                        <div class="styled-select-item ${val === 'nofollow' ? 'active' : ''}" data-value="nofollow" onclick="selectStyledOption(this, 'configValue')">Nofollow</div>
                    </div>
                </div>
            </div>
        `;
    } else if (config.type === 'link_type') {
        const selectedValues = Array.isArray(existingChip?.value) ? existingChip.value : (existingChip?.value ? [existingChip.value] : []);
        html += `
            <div class="popover-row">
                <label class="popover-label">Types de lien (plusieurs possibles)</label>
                <div class="styled-checkbox-list" style="max-height: 140px;">
                    ${config.values.map(v => `
                        <label class="styled-checkbox-item">
                            <input type="checkbox" class="link-type-checkbox" value="${v}" ${selectedValues.includes(v) ? 'checked' : ''}>
                            <span class="checkbox-box"><span class="material-symbols-outlined">check</span></span>
                            <span class="checkbox-label">${linkTypeLabels[v]}</span>
                        </label>
                    `).join('')}
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
        const opLabels = { 'contains': 'Contient', 'not_contains': 'Ne contient pas', 'regex': 'Correspond à la regex', 'not_regex': 'Ne correspond pas à la regex' };
        
        html += `
            <div class="popover-row">
                <label class="popover-label">Filtrer par</label>
                <div class="styled-select-wrapper">
                    <input type="hidden" id="configFilterMode" value="${filterMode}">
                    <div class="styled-select-btn" onclick="toggleStyledSelect(this)">
                        <span class="select-value">${filterMode === 'status' ? 'État' : 'Valeur (texte)'}</span>
                        <span class="material-symbols-outlined">expand_more</span>
                    </div>
                    <div class="styled-select-menu">
                        <div class="styled-select-item ${filterMode === 'status' ? 'active' : ''}" data-value="status" onclick="selectStyledOption(this, 'configFilterMode'); toggleSeoMode('status')">État</div>
                        <div class="styled-select-item ${filterMode === 'value' ? 'active' : ''}" data-value="value" onclick="selectStyledOption(this, 'configFilterMode'); toggleSeoMode('value')">Valeur (texte)</div>
                    </div>
                </div>
            </div>
            <div id="seoStatusMode" style="${filterMode === 'status' ? '' : 'display:none'}">
                <div class="popover-row">
                    <label class="popover-label">État (plusieurs possibles)</label>
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
                    <label class="popover-label">Condition</label>
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
                    <label class="popover-label">Valeur</label>
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
        
        html += `
            <div class="popover-row">
                <label class="popover-label">Filtrer par</label>
                <div class="styled-select-wrapper">
                    <input type="hidden" id="configFilterMode" value="${filterMode}">
                    <div class="styled-select-btn" onclick="toggleStyledSelect(this)">
                        <span class="select-value">${filterMode === 'count' ? 'Nombre de schemas' : 'Type de schema'}</span>
                        <span class="material-symbols-outlined">expand_more</span>
                    </div>
                    <div class="styled-select-menu">
                        <div class="styled-select-item ${filterMode === 'count' ? 'active' : ''}" data-value="count" onclick="selectStyledOption(this, 'configFilterMode'); toggleSchemasMode('count')">Nombre de schemas</div>
                        <div class="styled-select-item ${filterMode === 'contains' ? 'active' : ''}" data-value="contains" onclick="selectStyledOption(this, 'configFilterMode'); toggleSchemasMode('contains')">Type de schema</div>
                    </div>
                </div>
            </div>
            <div id="schemasCountMode" style="${filterMode === 'count' ? '' : 'display:none'}">
                <div class="popover-row">
                    <label class="popover-label">Opérateur</label>
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
                    <label class="popover-label">Nombre</label>
                    <input type="number" class="popover-input" id="configValue" placeholder="Ex: 0, 1, 5..." value="${numVal}" min="0">
                </div>
            </div>
            <div id="schemasContainsMode" style="${filterMode === 'contains' ? '' : 'display:none'}">
                <div class="popover-row">
                    <label class="popover-label">Condition</label>
                    <div class="styled-select-wrapper">
                        <input type="hidden" id="configContainsOperator" value="${containsOp}">
                        <div class="styled-select-btn" onclick="toggleStyledSelect(this)">
                            <span class="select-value">${containsOp === 'contains' ? 'Contient' : 'Ne contient pas'}</span>
                            <span class="material-symbols-outlined">expand_more</span>
                        </div>
                        <div class="styled-select-menu">
                            <div class="styled-select-item ${containsOp === 'contains' ? 'active' : ''}" data-value="contains" onclick="selectStyledOption(this, 'configContainsOperator')">Contient</div>
                            <div class="styled-select-item ${containsOp === 'not_contains' ? 'active' : ''}" data-value="not_contains" onclick="selectStyledOption(this, 'configContainsOperator')">Ne contient pas</div>
                        </div>
                    </div>
                </div>
                <div class="popover-row">
                    <label class="popover-label">Types de schemas</label>
                    <div class="checkbox-actions" style="display:flex;gap:0.75rem;margin-bottom:0.35rem;font-size:0.75rem;">
                        <a href="#" onclick="event.preventDefault();document.querySelectorAll('.schema-checkbox').forEach(c=>c.checked=true);" style="color:var(--primary-color);text-decoration:none;">tout cocher</a>
                        <span style="color:var(--text-secondary);">|</span>
                        <a href="#" onclick="event.preventDefault();document.querySelectorAll('.schema-checkbox').forEach(c=>c.checked=false);" style="color:var(--text-secondary);text-decoration:none;">tout décocher</a>
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
        html += `
            <div class="popover-row">
                <label class="popover-label">Condition</label>
                <div class="styled-select-wrapper">
                    <input type="hidden" id="configOperator" value="${op}">
                    <div class="styled-select-btn" onclick="toggleStyledSelect(this)">
                        <span class="select-value">${op === 'in' ? 'Est dans' : "N'est pas dans"}</span>
                        <span class="material-symbols-outlined">expand_more</span>
                    </div>
                    <div class="styled-select-menu">
                        <div class="styled-select-item ${op === 'in' ? 'active' : ''}" data-value="in" onclick="selectStyledOption(this, 'configOperator')">Est dans</div>
                        <div class="styled-select-item ${op === 'not_in' ? 'active' : ''}" data-value="not_in" onclick="selectStyledOption(this, 'configOperator')">N'est pas dans</div>
                    </div>
                </div>
            </div>
            <div class="popover-row">
                <label class="popover-label">Catégories</label>
                <div class="checkbox-actions" style="display:flex;gap:0.75rem;margin-bottom:0.35rem;font-size:0.75rem;">
                    <a href="#" onclick="event.preventDefault();document.querySelectorAll('.cat-checkbox').forEach(c=>c.checked=true);" style="color:var(--primary-color);text-decoration:none;">tout cocher</a>
                    <span style="color:var(--text-secondary);">|</span>
                    <a href="#" onclick="event.preventDefault();document.querySelectorAll('.cat-checkbox').forEach(c=>c.checked=false);" style="color:var(--text-secondary);text-decoration:none;">tout décocher</a>
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
            <button class="popover-btn popover-btn-secondary" onclick="closeAllPopovers()">Annuler</button>
            <button class="popover-btn popover-btn-primary" onclick="confirmFilter()">Appliquer</button>
        </div>
    `;
    
    content.innerHTML = html;
    positionPopover(popover, document.querySelector('.btn-add-filter'));
    popover.classList.add('active');
    document.getElementById('popoverOverlay').classList.add('active');
    
    setTimeout(() => {
        const firstInput = popover.querySelector('input[type="text"], input[type="number"]');
        if (firstInput) firstInput.focus();
    }, 50);
}

// Listener global pour valider avec Entrée quand un popover est ouvert
document.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        const configPopover = document.getElementById('configPopover');
        if (configPopover && configPopover.classList.contains('active')) {
            e.preventDefault();
            confirmFilter();
        }
    }
});

function selectTarget(target) {
    document.querySelectorAll('.target-btn').forEach(b => b.classList.remove('active'));
    document.querySelector(`.target-btn.${target}`).classList.add('active');
    document.getElementById('configTarget').value = target;
}

function editChip(groupIndex, chipIndex, event) {
    event.stopPropagation();
    closeAllPopovers();
    
    const chip = filterGroups[groupIndex][chipIndex];
    editingChipIndex = { groupIndex, chipIndex };
    pendingFilterConfig = { field: chip.field, target: chip.target };
    
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
    let target = config.scope === 'link' ? 'source' : (document.getElementById('configTarget')?.value || 'source');
    
    if (config.type === 'text' || config.type === 'number') {
        operator = document.getElementById('configOperator').value;
        value = document.getElementById('configValue').value;
        if (!value) { closeAllPopovers(); return; }
    } else if (config.type === 'boolean' || config.type === 'internal_external' || config.type === 'dofollow_nofollow') {
        value = document.getElementById('configValue').value;
    } else if (config.type === 'link_type') {
        const checkboxes = document.querySelectorAll('.link-type-checkbox:checked');
        value = Array.from(checkboxes).map(cb => cb.value);
        if (value.length === 0) { closeAllPopovers(); return; }
        if (value.length === 1) value = value[0];
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
    
    const newChip = { field, operator, value, target };
    
    if (editingChipIndex) {
        filterGroups[editingChipIndex.groupIndex][editingChipIndex.chipIndex] = newChip;
    } else if (pendingFilterConfig.addToGroup !== null) {
        filterGroups[pendingFilterConfig.addToGroup].push(newChip);
    } else {
        filterGroups.push([newChip]);
    }
    
    closeAllPopovers();
    applyFilters();
}

function removeChip(groupIndex, chipIndex) {
    if (filterGroups[groupIndex].length === 1) {
        filterGroups.splice(groupIndex, 1);
    } else {
        filterGroups[groupIndex].splice(chipIndex, 1);
    }
    applyFilters();
}

// ============================================
// STYLED SELECT HELPERS
// ============================================
function toggleStyledSelect(btn) {
    event.stopPropagation();
    const menu = btn.nextElementSibling;
    const wasOpen = menu.classList.contains('show');
    document.querySelectorAll('.styled-select-menu.show').forEach(m => m.classList.remove('show'));
    if (!wasOpen) menu.classList.add('show');
}

function selectStyledOption(item, inputId) {
    const wrapper = item.closest('.styled-select-wrapper');
    document.getElementById(inputId).value = item.dataset.value;
    wrapper.querySelector('.styled-select-btn .select-value').textContent = item.textContent;
    wrapper.querySelectorAll('.styled-select-item').forEach(i => i.classList.remove('active'));
    item.classList.add('active');
    item.closest('.styled-select-menu').classList.remove('show');
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.styled-select-wrapper')) {
        document.querySelectorAll('.styled-select-menu.show').forEach(m => m.classList.remove('show'));
    }
});

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
// COLLECT & APPLY
// ============================================
function collectFiltersForURL() {
    const filters = [];
    filterGroups.forEach((group, idx) => {
        if (group.length === 1) {
            filters.push({
                type: 'group', logic: 'AND',
                items: [{ type: 'condition', ...group[0] }],
                interGroupLogic: idx > 0 ? 'AND' : undefined
            });
        } else {
            filters.push({
                type: 'group', logic: 'OR',
                items: group.map(c => ({ type: 'condition', ...c })),
                interGroupLogic: idx > 0 ? 'AND' : undefined
            });
        }
    });
    return filters;
}

function applyFilters() {
    const filters = collectFiltersForURL();
    const params = new URLSearchParams(window.location.search);
    params.set('page', 'link-explorer');
    params.delete('p');
    if (filters.length > 0) {
        params.set('filters', JSON.stringify(filters));
    } else {
        params.delete('filters');
    }
    window.location.search = params.toString();
}

function clearFilters() {
    filterGroups = [];
    const params = new URLSearchParams(window.location.search);
    params.set('page', 'link-explorer');
    params.delete('filters');
    params.delete('search');
    params.delete('p');
    window.location.search = params.toString();
}

// ============================================
// SEARCH
// ============================================
let searchTimeout;
document.getElementById('globalSearch').addEventListener('input', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        const params = new URLSearchParams(window.location.search);
        params.set('page', 'link-explorer');
        params.delete('p');
        if (this.value) params.set('search', this.value);
        else params.delete('search');
        window.location.search = params.toString();
    }, 500);
});

document.getElementById('globalSearch').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        clearTimeout(searchTimeout);
        const params = new URLSearchParams(window.location.search);
        params.set('page', 'link-explorer');
        params.delete('p');
        if (this.value) params.set('search', this.value);
        else params.delete('search');
        window.location.search = params.toString();
    }
});

// ============================================
// INIT
// ============================================
document.addEventListener('DOMContentLoaded', function() { renderChips(); });
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeAllPopovers(); });
</script>
