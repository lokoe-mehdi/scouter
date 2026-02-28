# Audit et Plan de Refactoring - Scouter

Audit de l'architecture de l'application Scouter pour identifier les améliorations à apporter.

---

## 📊 Vue d'ensemble

### Structure actuelle

```
scouter/
├── app/                    # 19 classes PHP (namespace App\)
├── web/
│   ├── api/               # 28 endpoints REST (fichiers PHP directs)
│   ├── pages/             # 23 pages (fichiers PHP directs)
│   ├── components/        # 12 composants UI
│   ├── charts/            # 6 classes graphiques (namespace Charts\)
│   └── assets/            # CSS/JS
├── migrations/            # 11 migrations PostgreSQL
├── scripts/               # 6 scripts utilitaires
├── renderer/              # Service Go (Puppeteer)
├── tests/                 # Tests unitaires Pest
└── docker/                # Configuration Docker
```

### Statistiques
- **Classes PHP** : 19 (toutes dans `App\`)
- **Endpoints API** : 28
- **Pages web** : 23
- **Composants** : 12
- **Lignes de code estimées** : ~15 000

---

## 🔴 Problèmes critiques

### 1. Namespace unique `App\` pour tout

**Problème** : Toutes les classes sont dans le namespace `App\`, mélangeant :
- Logique métier (Crawler, Page, Simhash)
- Accès données (CrawlDatabase, GlobalDatabase, PostgresDatabase)
- Utilitaires (HttpCodes, HtmlParser, CategoryColors)
- CLI (Cmder)
- Auth (Auth)
- Jobs (JobManager)

**Impact** : Difficile de comprendre les responsabilités, couplage fort.

**Solution proposée** :
```
App\
├── Core\                  # Classes centrales
│   ├── Crawler.php
│   ├── DepthCrawler.php
│   ├── Page.php
│   └── PageCrawler.php
├── Database\              # Accès données
│   ├── PostgresDatabase.php
│   ├── GlobalDatabase.php
│   └── CrawlDatabase.php
├── Auth\                  # Authentification
│   └── Auth.php
├── Job\                   # Gestion des jobs
│   └── JobManager.php
├── Analysis\              # Analyse SEO
│   ├── Simhash.php
│   ├── Pagerank.php
│   ├── RobotsTxt.php
│   └── Category.php
├── Util\                  # Utilitaires
│   ├── HttpCodes.php
│   ├── HtmlParser.php
│   ├── CategoryColors.php
│   └── JsRenderer.php
└── Cli\                   # Interface CLI
    └── Cmder.php
```

---

### 2. Code dupliqué dans `web/init.php` et `web/pages/init.php`

**Problème** : Les deux fichiers font exactement la même chose (auth + vérification accès).

**Solution** : Fusionner en un seul fichier `web/bootstrap.php` utilisé partout.

---

### 3. Fichiers monolithiques dans `web/pages/`

**Problème** : Certaines pages sont énormes :
- `url-explorer.php` : 73 Ko
- `link-explorer.php` : 82 Ko
- `sql-explorer.php` : 84 Ko
- `categorize.php` : 85 Ko

**Impact** : Code difficile à maintenir, logique métier mélangée avec le HTML.

**Solution** : 
- Extraire la logique métier dans des classes dédiées
- Utiliser un système de templates (Twig ou simple include)
- Séparer les requêtes SQL dans des repositories

---

### 4. Composants UI trop gros

**Problème** :
- `url-table.php` : 74 Ko
- `link-table.php` : 86 Ko
- `chart.php` : 45 Ko
- `url-details-modal.php` : 53 Ko

**Solution** : Décomposer en sous-composants réutilisables.

---

### 5. Classes legacy SQLite ✅ RÉSOLU

**Problème** : Certaines classes utilisaient encore SQLite alors que PostgreSQL est en place.

**Solution appliquée** : Classes supprimées le 28/01/2026 :
- ~~`Calcul.php`~~ - Supprimé (non utilisé)
- ~~`Category.php`~~ - Supprimé (non utilisé)
- ~~`Pagerank.php`~~ - Supprimé (non utilisé)

Les fonctionnalités sont maintenant dans `CrawlDatabase.php` (PostgreSQL).

---

## 🟡 Problèmes moyens

### 6. Pas de layer Repository/Service ✅ RÉSOLU

**Problème** : Les classes `GlobalDatabase` et `CrawlDatabase` étaient des "God classes" qui faisaient tout.

**Solution appliquée** (28/01/2026) :

#### Nouvelle architecture `App\Analysis\`
- **`PostProcessor.php`** : Orchestrateur post-crawl (550 lignes)
  - `calculateInlinks()` - Calcul des liens entrants
  - `calculatePagerank()` - Algorithme PageRank interne
  - `semanticAnalysis()` - Analyse title/h1/metadesc
  - `categorize()` - Catégorisation des URLs
  - `duplicateAnalysis()` - Détection duplicates (Simhash)

#### Nouveaux Repositories `App\Database\`
- **`PageRepository.php`** : CRUD pages (insert, update, batch, schemas)
- **`LinkRepository.php`** : CRUD liens (insert, batch)
- **`UserRepository.php`** : CRUD utilisateurs (auth, rôles)
- **`ProjectRepository.php`** : CRUD projets (partage, accès)
- **`CrawlRepository.php`** : CRUD crawls (stats, config)
- **`CategoryRepository.php`** : CRUD catégories (assignation projets)

#### Classes allégées / supprimées
- **`CrawlDatabase.php`** : 1049 → 492 lignes (-53%)
- **`GlobalDatabase.php`** : **SUPPRIMÉE** (902 lignes → 0)

Tous les appels ont été migrés vers les repositories spécialisés.

**Syntaxe actuelle** :
```php
// Users
$users = new UserRepository();
$users->getByEmail($email);
$users->create($email, $password, $role);

// Projects  
$projects = new ProjectRepository();
$projects->getForUser($userId);
$projects->share($projectId, $targetUserId);

// Crawls
$crawls = new CrawlRepository();
$crawls->getById($id);
$crawls->update($id, $data);

// Categories
$categories = new CategoryRepository();
$categories->getForUser($userId);
```

---

### 7. ✅ API sans structure (RÉSOLU)

**Problème** : 28 fichiers PHP dans `web/api/` sans framework, chacun gérant sa propre logique.

**Solution implémentée** :
- ✅ Routeur maison léger créé dans `app/Http/` (Router, Request, Response, Controller)
- ✅ Controllers dédiés par domaine dans `app/Http/Controllers/`
- ✅ Point d'entrée unique `web/api/v1/index.php`
- ✅ Fichiers de compatibilité pour la transition progressive
- ✅ Anciens fichiers archivés dans `web/api/_legacy/`

**Structure créée** :
```
app/Http/
  Router.php          # Routeur avec support {param}
  Request.php         # Wrapper requête HTTP
  Response.php        # Helpers JSON/CSV/HTML
  Controller.php      # Classe de base
  Controllers/
    CategoryController.php
    UserController.php
    ProjectController.php
    CrawlController.php
    JobController.php
    QueryController.php
    ExportController.php
    MonitorController.php
    CategorizationController.php

web/api/v1/
  index.php           # Point d'entrée unique
  .htaccess           # Rewrite rules
```

**Usage** :
```php
// Nouvelle API v1
$router->get('/categories', [CategoryController::class, 'index'], ['auth' => true]);
$router->post('/categories', [CategoryController::class, 'create'], ['auth' => true]);
$router->put('/categories/{id}', [CategoryController::class, 'update'], ['auth' => true]);
```

---

### 8. CSS monolithique

**Problème** : `style.css` fait 88 Ko, difficile à maintenir.

**Solution** : 
- Découper par composant/page
- Utiliser un préprocesseur (SASS) ou CSS modules

---

### 9. Fichier `index.php` énorme

**Problème** : `web/index.php` fait 140 Ko (!) - probablement généré ou avec beaucoup de code inline.

**Solution** : Refactorer en utilisant des includes/composants.

---

## 🟢 Points positifs

- ✅ **Docker** : Configuration complète avec workers
- ✅ **Migrations** : Système de migration en place
- ✅ **Tests** : Framework Pest configuré avec tests unitaires
- ✅ **Documentation** : Doctum avec dark mode et Getting Started
- ✅ **PostgreSQL** : Base centralisée avec partitionnement
- ✅ **Autoloading PSR-4** : Composer bien configuré
- ✅ **Séparation renderer** : Service Go indépendant pour Puppeteer

---

## 🗑️ Fichiers potentiellement inutiles

### À vérifier/supprimer

| Fichier | Raison |
|---------|--------|
| `app/Calcul.php` | Legacy SQLite, remplacé par `CrawlDatabase::calculateInlinks()` |
| `app/Category.php` | Legacy SQLite, remplacé par `CrawlDatabase::categorize()` |
| `app/Pagerank.php` | Legacy SQLite, remplacé par `CrawlDatabase::calculatePagerank()` |
| `cat.yml` (racine) | Template de catégorisation, peut-être inutile |
| `config.yml` (racine) | Template de config, peut-être inutile |
| `scripts/add-in-progress-column.php` | Migration one-shot ? |
| `scripts/migrate-categories.php` | Migration one-shot ? |

### Scripts utilitaires à garder

| Fichier | Usage |
|---------|-------|
| `scripts/create-demo-user.php` | Création utilisateur démo |
| `scripts/promote-admin.php` | Promotion admin |
| `scripts/test-robots-parser.php` | Test robots.txt |
| `scripts/watchdog.php` | Surveillance jobs |

---

## 📋 Plan de refactoring recommandé

### Phase 1 : Nettoyage (1-2 jours)
1. [ ] Supprimer les classes legacy SQLite (`Calcul`, `Category`, `Pagerank`) ou les marquer clairement deprecated
2. [ ] Fusionner les fichiers `init.php` dupliqués
3. [ ] Supprimer les scripts de migration one-shot inutiles
4. [ ] Nettoyer les fichiers de config template à la racine

### Phase 2 : Réorganisation namespaces (2-3 jours) ✅ TERMINÉ
1. [x] Créer la nouvelle structure de namespaces
2. [x] Migrer les classes une par une
3. [x] Mettre à jour les imports dans tous les fichiers
4. [x] Mettre à jour l'autoload Composer

**Structure finale :**
```
app/
├── Analysis/     # Simhash, Pagerank, RobotsTxt, Category, Calcul
├── Auth/         # Auth
├── Cli/          # Cmder
├── Core/         # Crawler, DepthCrawler, Page, PageCrawler
├── Database/     # PostgresDatabase, GlobalDatabase, CrawlDatabase
├── Job/          # JobManager
├── Util/         # HttpCodes, HtmlParser, CategoryColors, JsRenderer
└── bin/          # worker.php, reset-jobs.php
```

### Phase 3 : Refactoring Database (3-5 jours)
1. [ ] Extraire les repositories de `GlobalDatabase` et `CrawlDatabase`
2. [ ] Créer une couche Service
3. [ ] Simplifier les classes Database

### Phase 4 : Refactoring Web (5-7 jours)
1. [ ] Créer un router simple pour les API
2. [ ] Décomposer les pages monolithiques
3. [ ] Décomposer les composants UI
4. [ ] Optimiser le CSS

---

## 🎯 Quick wins (rapide à faire)

1. **Fusionner les `init.php`** - 30 min
2. **Supprimer les classes SQLite legacy** - 15 min
3. **Nettoyer les fichiers racine inutiles** - 15 min
4. **Documenter les scripts utilitaires** - 30 min

---

## 📝 Notes

- La migration PostgreSQL semble complète mais des reliquats SQLite existent
- L'architecture Docker avec workers est bien pensée
- Le projet est fonctionnel mais nécessite du refactoring pour la maintenabilité
- Priorité recommandée : Phase 1 → Phase 2 → Phase 3 → Phase 4

---

*Document généré le 28/01/2026*
