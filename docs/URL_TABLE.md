# Composant URL Table

Composant réutilisable pour afficher une liste d'URLs avec pagination AJAX, filtres de colonnes et export CSV.

## Utilisation

Le composant supporte **deux modes** : simplifié et avancé.

### Mode simplifié (recommandé)

Toutes les colonnes sont automatiquement disponibles. Vous ne spécifiez que les conditions WHERE et ORDER BY.

```php
<?php
$urlTableConfig = [
    'title' => 'Mon titre personnalisé',
    'id' => 'unique_id',
    'whereClause' => 'WHERE c.crawled=1 AND c.code = :code',
    'orderBy' => 'ORDER BY c.inlinks DESC',
    'sqlParams' => [':code' => 404],
    'pdo' => $pdo,
    'projectDir' => $_GET['project'],
    'defaultColumns' => ['url', 'code', 'inlinks'],
    'perPage' => 50
];

include __DIR__ . '/../components/url-table.php';
?>
```

**Avantages du mode simplifié :**
- ✅ Toutes les colonnes sont toujours disponibles
- ✅ Pas d'erreur si l'utilisateur sélectionne une colonne non présente dans la requête
- ✅ Plus simple à écrire
- ✅ Support automatique des colonnes personnalisées `cstm_*`

### Colonnes personnalisées (extracteurs)

**Toutes les colonnes personnalisées `cstm_*` de la table `extracts` sont TOUJOURS disponibles dans le sélecteur de colonnes**, peu importe la configuration.

Pour afficher par défaut toutes les colonnes personnalisées :

```php
<?php
$urlTableConfig = [
    'title' => 'Pages avec extractions personnalisées',
    'id' => 'extractions',
    'whereClause' => 'WHERE c.compliant=1',
    'orderBy' => 'ORDER BY c.url',
    'defaultColumns' => ['url', 'category', 'title', 'cstm'], // 'cstm' inclut toutes les colonnes cstm_*
    'pdo' => $pdo,
    'perPage' => 50
];

include __DIR__ . '/../components/url-table.php';
?>
```

**Fonctionnement :**
- Le mot-clé `'cstm'` dans `defaultColumns` sera remplacé par toutes les colonnes `cstm_*`
- Ces colonnes seront affichées par défaut dans le tableau
- **Toutes les colonnes `cstm_*` sont disponibles dans le sélecteur**, même sans mettre `'cstm'` dans `defaultColumns`
- Les labels sont préfixés : `cstm_prix` → "Extracteur : Prix"

### Mode avancé

Pour des requêtes SQL personnalisées complexes.

```php
<?php
$urlTableConfig = [
    'title' => 'Mon titre personnalisé',
    'id' => 'unique_id',
    'sqlQuery' => "SELECT ... FROM crawl ...", // Requête SQL complète
    'sqlParams' => [':param' => 'value'],
    'pdo' => $pdo,
    'projectDir' => $_GET['project'],
    'defaultColumns' => ['url', 'code'],
    'perPage' => 50
];

include __DIR__ . '/../components/url-table.php';
?>
```

## Exemple complet

```php
<?php
// Dans votre vue (ex: pages/my-page.php)

$urlTableConfig = [
    'title' => 'URLs avec erreurs 404',
    'id' => 'urls_404',
    'sqlQuery' => "SELECT 
        c.url,
        c.depth,
        c.code,
        c.inlinks,
        COALESCE(cat.cat, 'Non catégorisé') as category
        FROM crawl c
        LEFT JOIN categories cat ON c.cat_id = cat.id
        WHERE c.code = :code
        ORDER BY c.inlinks DESC",
    'sqlParams' => [':code' => 404],
    'defaultColumns' => ['url', 'code', 'inlinks', 'category'],
    'pdo' => $pdo,
    'projectDir' => $_GET['project'] ?? ''
];

include __DIR__ . '/../components/url-table.php';
?>
```

## Fonctionnalités

- ✅ **Pagination AJAX** : Changement de page sans rechargement
- ✅ **Sélection de colonnes** : Dropdown pour choisir les colonnes affichées
- ✅ **Export CSV** : Export complet (sans limite de pagination)
- ✅ **Copie dans presse-papiers** : Format TSV pour Excel/Sheets
- ✅ **Popins d'URL** : Détails d'URL en modal
- ✅ **Lien externe** : Icône pour ouvrir l'URL en nouvelle fenêtre
- ✅ **Tooltip de confirmation** : "Texte copié" après copie
- ✅ **Multi-instances** : Plusieurs composants sur la même page

## Colonnes disponibles

- `url` - URL (obligatoire, toujours affichée)
- `depth` - Profondeur
- `code` - Code HTTP
- `category` - Catégorie
- `inlinks` - Liens entrants
- `outlinks` - Liens sortants
- `response_time` - Temps de réponse (ms)
- `compliant` - Compliant
- `canonical` - Canonical
- `noindex` - Noindex
- `nofollow` - Nofollow
- `blocked` - Bloqué
- `redirect_to` - Redirige vers
- `content_type` - Type de contenu
- `pri` - PageRank
- `title` - Title
- `h1` - H1
- `meta_desc` - Meta Description

## Notes importantes

### ID unique du composant
Chaque instance du composant doit avoir un `$componentId` unique pour éviter les conflits JavaScript et CSS entre plusieurs instances sur la même page.

### Requête SQL
- La requête doit retourner les colonnes que vous souhaitez afficher
- La pagination est gérée automatiquement (LIMIT/OFFSET)
- Pour l'export CSV, la requête est exécutée sans limite

### Paramètres GET
Le composant utilise des paramètres GET préfixés avec l'ID du composant :
- `p_{componentId}` : Numéro de page
- `columns_{componentId}` : Colonnes sélectionnées

Exemple : `?p_main_urls=2&columns_main_urls=url,code,depth`

## Multi-instances

Vous pouvez avoir plusieurs composants sur la même page :

```php
<?php
// Composant 1 : URLs 404
$urlTableConfig = [
    'title' => 'URLs 404',
    'id' => 'urls_404',
    'sqlQuery' => "SELECT ... WHERE code = 404 ...",
    'sqlParams' => [],
    'defaultColumns' => ['url', 'code'],
    'pdo' => $pdo,
    'projectDir' => $_GET['project'] ?? ''
];
include __DIR__ . '/../components/url-table.php';
?>

<?php
// Composant 2 : URLs lentes
$urlTableConfig = [
    'title' => 'URLs lentes (>2s)',
    'id' => 'urls_slow',
    'sqlQuery' => "SELECT ... WHERE response_time > 2000 ...",
    'sqlParams' => [],
    'defaultColumns' => ['url', 'response_time'],
    'pdo' => $pdo,
    'projectDir' => $_GET['project'] ?? ''
];
include __DIR__ . '/../components/url-table.php';
?>
```

Chaque composant aura sa propre pagination, ses propres colonnes et son propre export CSV indépendant.
