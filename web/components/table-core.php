<?php
/**
 * Table Core - Fonctions partagées entre url-table et link-table
 * 
 * Ce fichier contient les fonctions utilitaires, définitions de colonnes
 * et fonctions de rendu communes aux deux composants de table.
 * 
 * @package Scouter
 * @since 2.1.0
 */

// ==============================================
// FONCTIONS UTILITAIRES
// ==============================================

/**
 * Extrait les conditions du WHERE pour afficher le scope
 * 
 * @param string $whereClause Clause WHERE SQL
 * @return array|null Liste des conditions ou null si vide
 */
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

/**
 * Substitue les paramètres PDO par leurs vraies valeurs pour l'affichage SQL
 * 
 * @param string $sql Requête SQL avec placeholders
 * @param array $params Paramètres PDO
 * @return string SQL avec valeurs substituées
 */
function substituteParamsInSql($sql, $params) {
    foreach ($params as $key => $value) {
        // Formater la valeur pour SQL
        if (is_string($value)) {
            $formattedValue = "'" . addslashes($value) . "'";
        } elseif (is_bool($value)) {
            $formattedValue = $value ? 'true' : 'false';
        } elseif (is_null($value)) {
            $formattedValue = 'NULL';
        } else {
            $formattedValue = $value;
        }
        // Remplacer le placeholder
        $sql = str_replace($key, $formattedValue, $sql);
    }
    return $sql;
}

// ==============================================
// DÉFINITION DES COLONNES
// ==============================================

/**
 * Retourne les colonnes disponibles pour les tables d'URLs
 * 
 * @param array $customExtractColumns Colonnes d'extracteurs JSONB
 * @return array Tableau [clé => label]
 */
function getAvailableColumns($customExtractColumns = []) {
    $columns = [
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
        'nofollow' => 'Nofollow',
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
    
    // Ajout des colonnes d'extracteurs JSONB
    foreach ($customExtractColumns as $columnName) {
        $label = ucwords(str_replace('_', ' ', $columnName));
        $columns['extract_' . $columnName] = 'Extracteur : ' . $label;
    }
    
    return $columns;
}

/**
 * Retourne les colonnes spécifiques aux liens
 * 
 * @return array Tableau [clé => label]
 */
function getLinkSpecificColumns() {
    return [
        'anchor' => 'Anchor',
        'external' => 'Externe',
        'nofollow' => 'Follow',
        'type' => 'Type de lien'
    ];
}

/**
 * Retourne le mapping des colonnes vers leurs équivalents SQL
 * 
 * @param string $prefix Préfixe de table (c, cs, ct)
 * @param array $customExtractColumns Colonnes d'extracteurs
 * @return array Mapping [colonne => SQL]
 */
function getColumnMapping($prefix = 'c', $customExtractColumns = []) {
    $mapping = [
        'url' => "{$prefix}.url",
        'depth' => "{$prefix}.depth",
        'code' => "{$prefix}.code",
        'inlinks' => "{$prefix}.inlinks",
        'outlinks' => "{$prefix}.outlinks",
        'response_time' => "{$prefix}.response_time",
        'schemas' => "array_length({$prefix}.schemas, 1)",
        'compliant' => "{$prefix}.compliant",
        'canonical' => "{$prefix}.canonical",
        'canonical_value' => "{$prefix}.canonical_value",
        'noindex' => "{$prefix}.noindex",
        'nofollow' => "{$prefix}.nofollow",
        'blocked' => "{$prefix}.blocked",
        'redirect_to' => "{$prefix}.redirect_to",
        'content_type' => "{$prefix}.content_type",
        'pri' => "{$prefix}.pri",
        'title' => "{$prefix}.title",
        'title_status' => "{$prefix}.title_status",
        'h1' => "{$prefix}.h1",
        'h1_status' => "{$prefix}.h1_status",
        'metadesc' => "{$prefix}.metadesc",
        'metadesc_status' => "{$prefix}.metadesc_status",
        'category' => "{$prefix}.cat_id",
        'h1_multiple' => "{$prefix}.h1_multiple",
        'headings_missing' => "{$prefix}.headings_missing",
        'word_count' => "{$prefix}.word_count"
    ];
    
    // Ajouter les colonnes extract_*
    foreach ($customExtractColumns as $col) {
        $colAlias = 'extract_' . preg_replace('/[^a-z0-9_]/i', '_', $col);
        $mapping[$colAlias] = "{$prefix}.extracts->>'" . addslashes($col) . "'";
    }
    
    return $mapping;
}

/**
 * Tooltips pour les en-têtes de colonnes
 * 
 * @return array Mapping [colonne => tooltip]
 */
function getColumnTooltips() {
    return [
        'response_time' => 'Time To First Byte',
        'pri' => 'PageRank Interne - Score d\'autorité basé sur les liens internes',
        'compliant' => 'URL indexable (pas de noindex, canonical ok, non bloquée)',
    ];
}

// ==============================================
// FONCTIONS DE RENDU DES CELLULES
// ==============================================

/**
 * Rend une cellule de code HTTP avec couleur
 * 
 * @param int $code Code HTTP
 * @return string HTML de la cellule
 */
function renderCodeCell($code) {
    $code = (int)$code;
    $textColor = function_exists('getCodeColor') ? getCodeColor($code) : '#95a5a6';
    $bgColor = function_exists('getCodeBackgroundColor') ? getCodeBackgroundColor($code, 0.3) : 'rgba(149, 165, 166, 0.3)';
    $displayValue = function_exists('getCodeDisplayValue') ? getCodeDisplayValue($code) : $code;
    
    return '<span class="badge" style="background: ' . $bgColor . '; color: ' . $textColor . '; font-weight: 600;">' 
         . htmlspecialchars($displayValue) . '</span>';
}

/**
 * Rend une cellule de catégorie avec couleur
 * 
 * @param int|null $catId ID de la catégorie
 * @param array $categoriesMap Mapping des catégories
 * @return string HTML de la cellule
 */
function renderCategoryCell($catId, $categoriesMap) {
    $catInfo = $categoriesMap[$catId] ?? null;
    $category = $catInfo ? $catInfo['cat'] : 'Non catégorisé';
    $bgColor = $catInfo ? ($catInfo['color'] ?? '#aaaaaa') : '#aaaaaa';
    $textColor = function_exists('getTextColorForBackground') ? getTextColorForBackground($bgColor) : '#fff';
    
    return '<span class="badge" style="background: ' . $bgColor . '; color: ' . $textColor . ';">' 
         . htmlspecialchars($category) . '</span>';
}

/**
 * Rend une cellule booléenne avec icône check/cancel
 * 
 * @param bool $value Valeur booléenne
 * @return string HTML de la cellule
 */
function renderBooleanCell($value) {
    if ($value) {
        return '<span class="material-symbols-outlined" style="color: #6bd899; font-size: 1.2rem; opacity: 0.8;">check_circle</span>';
    }
    return '<span class="material-symbols-outlined" style="color: #95a5a6; font-size: 1.2rem; opacity: 0.7;">cancel</span>';
}

/**
 * Rend une cellule de statut SEO (unique/duplicate/empty)
 * 
 * @param string $status Statut SEO
 * @return string HTML de la cellule
 */
function renderSeoStatusCell($status) {
    $status = strtolower($status ?? '');
    
    if ($status === 'unique') {
        return '<span class="badge badge-success">Unique</span>';
    } elseif ($status === 'duplicate') {
        return '<span class="badge badge-warning">Duplicate</span>';
    } elseif ($status === 'empty') {
        return '<span class="badge badge-danger">Empty</span>';
    }
    return '<span style="color: #95a5a6;">—</span>';
}

/**
 * Rend une cellule de comptage de mots avec couleur selon tranches
 * 
 * @param int $wordCount Nombre de mots
 * @return string HTML de la cellule
 */
function renderWordCountCell($wordCount) {
    $wc = (int)$wordCount;
    
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
    
    return '<span style="display: inline-block; padding: 0.2rem 0.5rem; background: ' . $wcBg 
         . '; color: ' . $wcColor . '; border-radius: 4px; font-weight: 500;">' 
         . number_format($wc) . '</span>';
}

/**
 * Rend une cellule de schemas (comptage)
 * 
 * @param string $schemas Valeur PostgreSQL TEXT[]
 * @return int Nombre de schemas
 */
function countSchemas($schemas) {
    if (empty($schemas) || $schemas === '{}') {
        return 0;
    }
    $schemasStr = trim($schemas, '{}');
    if (empty($schemasStr)) {
        return 0;
    }
    return count(explode(',', $schemasStr));
}

/**
 * Rend une cellule d'URL avec lien externe et option copie
 * 
 * @param string $url URL à afficher
 * @param bool $copyUrl Afficher le bouton copier
 * @return string HTML de la cellule
 */
function renderUrlCell($url, $copyUrl = false) {
    $html = '<div style="display: flex; align-items: center; overflow: hidden;">';
    
    if ($copyUrl) {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $html .= '<span class="copy-path-btn" data-path="' . htmlspecialchars($path) . '" title="Copier le chemin" style="cursor: pointer; color: var(--text-secondary); margin-right: 0.4rem; flex-shrink: 0;" onclick="event.preventDefault(); event.stopPropagation(); navigator.clipboard.writeText(this.dataset.path).then(() => { if(typeof showGlobalStatus === \'function\') showGlobalStatus(\'Chemin copié\', \'success\'); })">';
        $html .= '<span class="material-symbols-outlined" style="font-size: 16px;">content_copy</span>';
        $html .= '</span>';
    }
    
    $html .= '<span class="url-clickable" data-url="' . htmlspecialchars($url) . '" style="cursor: pointer; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; min-width: 0;">';
    $html .= htmlspecialchars($url);
    $html .= '</span>';
    
    $html .= '<a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener noreferrer" title="Ouvrir l\'URL dans un nouvel onglet" style="display: inline-flex; align-items: center; color: var(--text-secondary); text-decoration: none; margin-left: 0.5rem; flex-shrink: 0;">';
    $html .= '<span class="material-symbols-outlined" style="font-size: 16px;">open_in_new</span>';
    $html .= '</a>';
    
    $html .= '</div>';
    
    return $html;
}

/**
 * Rend une cellule de texte tronqué avec tooltip
 * 
 * @param string $text Texte à afficher
 * @param int $maxWidth Largeur max en pixels
 * @return string HTML de la cellule
 */
function renderTruncatedCell($text, $maxWidth = 200) {
    return '<span style="display: block; max-width: ' . $maxWidth . 'px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="' . htmlspecialchars($text ?? '') . '">' 
         . htmlspecialchars($text ?? '') . '</span>';
}

/**
 * Rend une cellule de profondeur avec badge
 * 
 * @param int $depth Profondeur
 * @return string HTML de la cellule
 */
function renderDepthCell($depth) {
    return '<span class="badge badge-info">' . (int)$depth . '</span>';
}

/**
 * Rend une cellule de PageRank
 * 
 * @param float $pri Valeur PageRank (0-1)
 * @return string Valeur formatée en pourcentage
 */
function renderPriCell($pri) {
    return number_format(($pri ?? 0) * 100, 4) . '%';
}

/**
 * Rend une cellule de temps de réponse
 * 
 * @param float $responseTime Temps en ms
 * @return string Valeur formatée
 */
function renderResponseTimeCell($responseTime) {
    return round($responseTime ?? 0, 2) . ' ms';
}
