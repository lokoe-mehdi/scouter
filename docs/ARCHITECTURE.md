# Architecture technique

## Stack

- **Backend**: PHP 8.2 + Nginx
- **Base de donnees**: PostgreSQL 15
- **Container**: Docker + Supervisor

## Base de donnees PostgreSQL

### Tables principales

```sql
users           -- Utilisateurs (auth)
migrations      -- Migrations executees
crawls          -- Crawls (config, stats)
crawls_categories -- Categories de crawls
```

### Tables partitionnees (par crawl_id)

```sql
pages           -- URLs crawlees (partitionne)
links           -- Liens entre pages (partitionne)
categories      -- Categories d'URLs (partitionne)
```

### Colonnes importantes (pages)

- `url`, `domain`, `path` - Identification
- `status_code`, `content_type` - Reponse HTTP
- `title`, `meta_description`, `h1` - SEO
- `canonical`, `indexable` - Directives
- `response_time`, `depth` - Metriques
- `inlinks`, `outlinks`, `pagerank` - Maillage
- `cat_id` - Categorie assignee
- `extracts` (JSONB) - Donnees extracteurs custom

## Systeme de migrations

Les migrations sont executees automatiquement au demarrage.

### Creer une migration

1. Creer un fichier dans `migrations/`:
   ```
   YYYY-MM-DD-HH-II-nom-descriptif.php
   ```

2. Structure:
   ```php
   <?php
   $pdo = \App\PostgresDatabase::getInstance()->getConnection();
   $pdo->exec("ALTER TABLE ...");
   return true;
   ```

3. Redemarrer: `./start.sh`

### Execution manuelle

```bash
docker exec scouter php /app/migrations/migrate.php
```

## API REST

Toutes les APIs sont dans `web/api/`:

| Endpoint | Description |
|----------|-------------|
| create-project.php | Creer un crawl |
| start-crawl.php | Demarrer un crawl |
| stop-crawl.php | Arreter un crawl |
| delete-crawl.php | Supprimer un crawl |
| get-project-stats.php | Stats d'un crawl |
| execute-query.php | SQL Explorer |
| save-categorization.php | Sauver categories |

## Classes principales (app/)

- `Crawler.php` - Logique de crawl
- `CrawlDatabase.php` - Acces donnees crawl
- `PostgresDatabase.php` - Connexion PostgreSQL
- `JobManager.php` - Gestion des jobs
- `Category.php` - Categorisation
- `Pagerank.php` - Calcul PageRank
- `Auth.php` - Authentification
