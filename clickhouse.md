# Migration PostgreSQL -> ClickHouse (données de crawl)

> Objectif : faire tenir Scouter dans le temps en stockant les **données de crawl**
> (gros volume, append-heavy, lectures analytiques) dans **ClickHouse**, tout en
> gardant PostgreSQL pour les **métadonnées transactionnelles**. Aucune régression.
>
> Changement majeur acté en parallèle : la **catégorisation n'est plus stockée**
> (plus de colonne `cat_id`). On garde juste les **règles YAML par projet** et on
> les transforme en **filtre SQL live** injecté dans les requêtes (ClickHouse
> encaisse). Côté utilisateur : inchangé (éditeur de segments YAML).

Ce document liste TOUT ce qu'il faut faire.

---

## ÉTAT D'IMPLÉMENTATION (2026-05-24)

Approche retenue pour migrer **sans régression** : **dual-write derrière le flag
`CLICKHOUSE_URL`**. Tant que le flag est absent → comportement PG-only identique.
Quand il est défini, le crawler écrit AUSSI dans ClickHouse et y fait le
post-processing ; **PostgreSQL reste écrit en entier** (source de vérité pendant la
transition), donc tous les rapports PG continuent de marcher. La lecture bascule
crawl-par-crawl via `crawls.data_store` (`pg` | `clickhouse`).

**Fait & testé (contre clickhouse-server 24) :**
- ✅ **Phase 0 (fondations)** : `docker/clickhouse/init.sql` (8 tables), service
  `clickhouse` dans les 2 compose + `.env.example`, client Go HTTP
  (`crawler-go/internal/db/clickhouse.go`), client PHP
  (`app/Database/ClickHouseDatabase.php`). Service CH + schéma dans la CI.
- ✅ **Phase 1 (écriture)** : dual-write pages/links/html/page_schemas dans CH
  (`crawl/ch_store.go`, batché, append-only, HTML brut décodé depuis DomZip).
- ✅ **Phase 2 (post-proc dans CH)** : `postprocess/clickhouse.go` — PageRank
  (Memory tables, 30 itérations), page_metrics (inlinks + pri + statuts via window
  functions), duplicate (exact en SQL + near-dup union-find), redirect-chains.
  Écrit page_metrics/duplicate_clusters/redirect_chains (DROP PARTITION + INSERT).
- ✅ **Phase 3/4 (lecture + sécurité) — surface query** : `CategoryExpr` (catégo
  live = CASE WHEN RE2), `ClickHouseSqlExecutor` (crawl_id forcé par sous-requêtes
  filtrées, dialecte CH, blocklist de fonctions, `category` live, jointure
  page_metrics), routage `CrawlStore::usesClickHouse`. **API v1 `/query` + `/schema`
  + SQL Explorer (`QueryController`) → CH** pour les crawls `data_store=clickhouse`.
  Le crawler pose `data_store='clickhouse'` au démarrage si CH actif.

**Reste à faire (pas bloquant — PG dual-write fait tourner l'app) :**
- ⛔ **Les ~40 pages de rapport `web/pages/*.php`** + composants url/link/redirect-table :
  encore en PG (qui est dual-écrit, donc OK). À porter sur CH (dialecte + `category`
  au lieu de `cat_id` + jointure page_metrics). À faire avec relecture visuelle.
- ⛔ **`in_sitemap`** dans CH : laissé à 0 dans page_metrics (la membership sitemap
  n'est pas encore dans CH). À traiter avec le rapport sitemap.
- ⛔ **Prompts du chat IA** (`DrBriefPrompt`/`SqlGenPrompt`) : enseignent encore le
  dialecte PG. (Le MCP `run_sql` passe par l'API `/query` + `/schema` CH, déjà OK.)
- ⛔ **Catégorisation : suppression de `cat_id`** + de `applyCategorization` + job
  batch-categorize + étape Go `categorize` : NON fait (toujours en place pour PG).
- ⛔ **Backfill** des crawls existants PG→CH (Phase 6), worker d'expiration 7j
  (Phase 5), `page_generation` bulk-AI, frontier PG slim : NON faits.

---

## 0. TL;DR / décisions structurantes à valider

1. **Split de responsabilité** : PG = métadonnées + frontier de crawl ; CH = entrepôt
   analytique (pages/links/html/page_schemas/duplicate_clusters/redirect_chains).
2. **Modèle d'écriture (LE point dur, tranché)** : on distingue **deux**
   mutabilités. (a) La **file d'attente du crawl (frontier)** = read-modify-write
   ligne-à-ligne en continu (marquer crawlé, dédoublonner, promotion `in_crawl`) ->
   vraiment transactionnel -> **reste en PG** (slim, transitoire). (b) Le
   **post-processing** (PageRank, inlinks, duplicate, semantic, redirect) = **recalcul
   one-shot sur tout le dataset**, pas des updates ponctuels -> exprimable en
   "construire une table dérivée une fois" -> **tourne dans ClickHouse**. Donc :
   **PG = frontier seule ; CH = pages/links/html + post-processing**, écrits en
   append (jamais d'UPDATE sur CH). PG ne porte JAMAIS une copie des données lourdes.
3. **Catégorisation** : suppression de `cat_id`, des écritures (`CategorizationService::applyCategorization`,
   l'étape Go `categorize`, le job `batch-categorize`, le test de parité). On
   conserve `buildCaseWhenSql()` (déjà la "catégorisation live") adapté au dialecte CH.
4. **Couche de lecture** : ~40 pages de rapport + 2 explorers + QueryController +
   SqlExecutor + API v1 + MCP + Dr Brief passent leurs requêtes data-crawl sur CH
   (nouveau client CH), avec réécriture du dialecte SQL.
5. **SQL Explorer** : la substitution `pages -> pages_<crawl_id>` devient un filtre
   `crawl_id = <id>` (CH = 1 table partitionnée par crawl_id, pas N tables).

---

## 1. Périmètre : ce qui bouge vs ce qui reste

### Dans ClickHouse (données de crawl + post-processing, partitionné par `crawl_id`)
- Écrites pendant le crawl (append) : `pages`, `links`, `html`, `page_schemas`.
- Produites par le post-processing (dans CH) : `page_metrics` (inlinks, pri,
  title/h1/metadesc_status), `duplicate_clusters`, `redirect_chains`.

### Reste sur PostgreSQL (OLTP / métadonnées / petit volume / FK / updates fréquents)
- `users`, `projects` (+ `categorization_config` YAML), `project_shares`,
  `project_categories`, `project_category_links`, `crawl_categories` (couleurs),
  `crawls` (métadonnées + stats), `categorization_config`, `crawl_schedules`,
  `user_saved_queries`, `jobs`, `job_logs`, `ai_*` (runs/budget), `app_settings`,
  `api_keys`, `oauth*`.
- **Frontier de crawl** (file d'URLs à crawler) : table **slim** par crawl
  (id, url, domain, depth, crawled, in_crawl, external, blocked, in_sitemap,
  redirect_to) — la seule donnée de crawl en PG, transitoire (droppée en fin de
  crawl). Voir §3.

---

## 2. Pourquoi CH ici, et les pièges

**Gains** : compression massive (ZSTD), agrégations analytiques très rapides,
`DROP PARTITION` instantané (suppression de crawl), `bitCount(bitXor(a,b))` natif
pour le simhash, scaling horizontal.

**Pièges majeurs** (et comment l'archi §3 les neutralise) :
- **Pas d'UPDATE/upsert efficace** (append-only, merges async). -> on n'UPDATE
  jamais CH : pages/links écrits une fois ; les champs dérivés vont dans des tables
  recalculées par `REPLACE PARTITION`. La mutation (frontier) reste en PG.
- **Pas de transactions ni de contraintes** (FK/UNIQUE). La dedup d'URLs (ON CONFLICT)
  reste en PG (frontier) ; CH ne reçoit que des lignes déjà dédoublonnées.
- **Dialecte SQL différent** (regex RE2 et non POSIX/PCRE, casts, JSON/Map, fenêtres,
  `LIMIT BY` au lieu de `DISTINCT ON`...). Cf. §5/§6.
- **Lectures pendant `running`** : non exposées (Option B = comme aujourd'hui), donc
  pas de souci de versions non mergées. Les tables dérivées (REPLACE PARTITION) sont
  cohérentes après chaque checkpoint.

---

## 3. Architecture cible : frontier en PG, données + post-proc dans CH

```
PostgreSQL (OLTP, petit)              ClickHouse (entrepôt, gros)
  frontier_<crawl>                      pages        (append, 1 ligne/page crawlée)
   id,url,depth,crawled,                links        (append à la découverte)
   in_crawl,blocked,redirect_to         html         (append)
   (read-modify-write, dedup,           page_schemas (append)
    promotion, "what's left")           --- post-processing exécuté DANS CH ---
        |                               page_metrics (inlinks, pri, *_status)
        | pilote la boucle              duplicate_clusters
        | de crawl + resume             redirect_chains
```

**Principe** : on sépare les deux mutabilités.
- **PG = la frontier seule** (file d'attente, slim). C'est le SEUL truc vraiment
  transactionnel (marquage `crawled`, dédoublonnage `ON CONFLICT`, promotion
  `in_crawl`, requête temps réel "URLs restantes") -> ClickHouse ne sait pas le
  faire (merges asynchrones), donc ça reste en PG. C'est petit et transitoire.
- **CH = toutes les données + tout le post-processing.**
  - Pendant le crawl : chaque page crawlée -> **1 ligne appended dans CH `pages`**
    (jamais ré-écrite) ; les liens/html/schemas appended au fil de l'eau.
  - À chaque `stop`/`finish` : le **post-processing tourne EN ClickHouse** (inlinks,
    PageRank, semantic, duplicate, redirect) et écrit ses résultats dans des tables
    dérivées (`page_metrics`, `duplicate_clusters`, `redirect_chains`) -> **aucun
    UPDATE** (on remplace la partition de la table dérivée : `REPLACE PARTITION`).

**Pourquoi ça marche (et pourquoi c'est mieux que "PG scratch + export")** :
- Le post-proc n'est PAS des updates ponctuels : c'est un **recalcul groupé** ->
  parfait pour CH (gros INSERT d'une table dérivée). C'est ce qui rend le **PageRank
  rapide** sur des millions de pages (gain CH benchmarkable).
- PG ne porte jamais les données lourdes -> **reste minuscule** (juste la frontier +
  métadonnées). La frontier est droppée en fin de crawl (ou expiration).
- Pas d'étape d'export ni de copie scratch : les données sont dans CH **dès le
  crawl**. Un crawl `stopped` est donc **déjà consultable** (CH), et le resume
  continue via la frontier PG.

**Lectures** : toujours sur CH. Consultable dès `stopped`/`finished` (= comportement
actuel : pas de rapport détaillé pendant `running`).

**Le point technique à cadrer** : `pages` est append-only, écrit **une fois par page
crawlée**. Les champs dérivés (inlinks, pri, *_status) ne sont PAS dans `pages` mais
dans **`page_metrics`** (table séparée, jointe à la lecture), recalculée à chaque
checkpoint. Ça évite tout UPDATE sur `pages`. (Alternative : `pages` en
`ReplacingMergeTree((crawl_id,id),version)` + `argMax`, mais la table dérivée jointe
est plus simple et lisible.)

---

## 4. Schéma ClickHouse (data-crawl)

Principe : **une table par type, partitionnée par `crawl_id`** (`PARTITION BY crawl_id`),
triée par `ORDER BY (crawl_id, id)`. `DROP PARTITION crawl_id` = suppression de crawl
instantanée (remplace `drop_crawl_partitions`).

Mapping de types (extrait) :
- `CHAR(8)` (id crc32) -> `FixedString(8)` (ou `String`).
- `BIGINT` simhash -> `Int64`.
- `INTEGER`/`FLOAT` -> `Int32`/`Float64`.
- `BOOLEAN` -> `UInt8`.
- `TEXT` -> `String`.
- `TEXT[]` (schemas, page_ids, chain_ids) -> `Array(String)`.
- `JSONB` (extracts, generation) -> `String` (JSON brut) requêté via `JSONExtract*`,
  ou type `JSON`/`Map(String,String)` selon les besoins (décision §12).
- `html` : on peut **abandonner le base64+gzdeflate** (CH compresse en ZSTD) et
  stocker le HTML brut en `String`. À trancher (impacte la lecture PHP de `get_page_html`).

Moteurs :
- `pages`, `links`, `html`, `page_schemas` : `MergeTree`, **append-only** (écrits
  une fois pendant le crawl ; `pages` = 1 ligne/page crawlée, sans champs dérivés
  ni `cat_id`). `html` en `String` + `CODEC(ZSTD)`.
- `page_metrics` (`crawl_id, id, inlinks, pri, title_status, h1_status,
  metadesc_status, in_sitemap`), `duplicate_clusters`, `redirect_chains` : tables
  **dérivées** produites par le post-proc, **réécrites par `REPLACE PARTITION`** à
  chaque checkpoint (`ReplacingMergeTree` ou MergeTree + REPLACE). Jointes à `pages`
  sur `(crawl_id,id)` à la lecture.
- Aucun `cat_id` nulle part (catégorisation = live, §5).

À produire : `docker/clickhouse/init.sql` (DDL CH). Les partitions sont créées
automatiquement par CH à l'insert ; suppression = `ALTER TABLE ... DROP PARTITION`.

---

## 5. Refonte de la catégorisation (live, sans `cat_id`)

### Ce qu'on supprime
- Colonne `pages.cat_id` (+ tous les index `idx_pages_*_cat_id`).
- `CategorizationService::applyCategorization()` (l'écriture) + le job
  `batch-categorize-project` (Cmder + worker) + la commande associée.
- L'étape Go `crawler-go/internal/postprocess/categorize.go` + `Categorize()` +
  le test de parité catégorisation + `tests/parity/` (catégo) + le job CI associé.
- Les écritures dans `crawl_categories` issues du résultat de crawl.

### Ce qu'on garde / transforme
- **Stockage des règles** : `projects.categorization_config` (YAML) — inchangé.
  L'éditeur de segments YAML côté UI ne change pas (save/load identiques).
- **`crawl_categories`** : conservé en PG **uniquement pour les couleurs** (mapping
  `cat name -> color`), peuplé depuis les **noms+couleurs des règles YAML** (pas
  depuis le crawl). Sert au rendu UI.
- **`buildCaseWhenSql()`** : c'est déjà la catégorisation live. On le porte en
  **un builder d'expression CH** : `CASE WHEN match(url,'(?i)dom') AND match(url_path,'(?i)inc') AND NOT match(url_path,'(?i)exc') THEN 'name' ... END AS category`.
  - `url_path` = `replaceRegexpOne(url, '^https?://[^/]+', '')`.
  - Attention RE2 : les regex utilisateur ne doivent pas utiliser de
    backreferences/lookarounds (non supportés par RE2). À valider/avertir dans
    l'éditeur (cf. §12).

### Impact lecture (le gros morceau, ~40 pages)
Partout où il y a aujourd'hui `GROUP BY cat_id` / `WHERE cat_id IN (...)` / `JOIN crawl_categories`,
on remplace par l'**expression `category` calculée** injectée depuis les règles du projet :
- Rapports `web/pages/*` : `GROUP BY (CASE WHEN ... END)` au lieu de `cat_id`.
- `url-explorer` / `link-explorer` : filtre `WHERE (CASE WHEN ... END) IN (...)`.
- Export CSV, `ContextBuilder` (IA), `DrBriefPrompt`/`SqlGenPrompt`.
- Centraliser : **un seul helper** `CategoryExpr::forProject($projectId): {sql, params}`
  réutilisé par tous les sites (PHP) + son équivalent injecté côté SQL Explorer/IA.

---

## 6. Couche SQL : dialecte + sites de lecture

### Inventaire des sites de lecture data-crawl (à re-router vers CH)
- **~40 pages** `web/pages/*.php` : accessibility(+comparison), codes, code-changes,
  comparison-overview, content-richness(+comparison), depth(+comparison),
  duplication(+comparison), extractions, headings(+comparison), home, inlinks(+comparison),
  link-explorer, lost-urls, new-urls, outlinks(+comparison), pagerank(+leak/+comparison),
  redirect-chains, response-time, seo-tags(+comparison), sitemap(+comparison),
  structured-data(+comparison), url-explorer.
- **Composants** : `web/components/{url-table,link-table,redirect-table}.php`.
- **Controllers** : `QueryController` (SQL Explorer + url-details), `ApiV1Controller`
  (query, crawlSchema), `ExportController` (CSV), `BulkGenerateController` (écrit
  `generation` JSONB -> devient un write CH, cf. §12), `CategorizationController`
  (devient lecture live), `MonitorController` (taille partitions : `pg_total_relation_size`
  -> équivalent CH `system.parts`).
- **IA** : `SqlExecutor` (Dr Brief), `SqlQueryTool`, `DrBriefPrompt`, `SqlGenPrompt`,
  `ContextBuilder`, `AIUrlFiltersController`, `AILinkFiltersController`.
- **MCP** : `run_sql` -> `/api/v1/crawls/{id}/query` -> `SqlExecutor`.
- **monitor.php** : lit `pg_tables` -> à refaire via `system.parts` CH.

### Constructs PostgreSQL -> ClickHouse (tableau de migration)
| PG | ClickHouse | Sites concernés |
|---|---|---|
| `~*` / `~` | `match(s,'(?i)pat')` / `match(s,'pat')` (RE2) | CategorizationService, url/link-explorer, monitor |
| `regexp_replace(s,p,r)` | `replaceRegexpOne/All(s,p,r)` | CategorizationService (url_path) |
| `::numeric/::int/::text` | `toFloat64/toInt64/toString` | tous les `ROUND(AVG(...)::numeric,2)` |
| `bit_count((a # b)::bit(64))` | `bitCount(bitXor(a,b))` (plus simple !) | duplication (post-proc CH) |
| `x ->> 'k'` (JSONB) | `JSONExtractString(x,'k')` | extractions, explorers, bulk-gen |
| `x ? 'k'` | `JSONHas(x,'k')` | prompts IA, filtres |
| `jsonb_object_keys(x)` | `JSONExtractKeys(x)` | extractions, table components, bulk-gen |
| `COUNT(*) FILTER (WHERE c)` | `countIf(c)` / `sumIf(1,c)` | url/link-explorer |
| `col = ANY(arr)` | `has(arr,col)` | filtres schemas (url/link-explorer) |
| `array_agg(x)` | `groupArray(x)` | post-proc CH (duplicate/redirect clusters) |
| window functions (pagerank/semantic) | `OVER (...)` natif CH + itérations en requêtes | post-proc CH |
| `DISTINCT ON (c)` | `LIMIT 1 BY c` | (métadonnées — reste en PG) |
| `ROW_NUMBER() OVER (...)` | idem (CH 21.9+) ou `LIMIT n BY` | prompts IA (sampling) |
| `ILIKE` | `ILIKE` (supporté) ou `position(lower...)` | filtres |
| `||` (concat) | `concat()` | prompts IA |
| `NOW() - INTERVAL ...` | `now() - INTERVAL ...` (syntaxe proche) | prompts IA |
| `ON CONFLICT` | — (ReplacingMergeTree / pas nécessaire en v1) | writes |

### Garde-fou / Sécurité SQL (SQL Explorer, API, MCP, IA)
Aujourd'hui : whitelist de 6 tables, interdiction DML/DDL/fonctions dangereuses,
SELECT-only, **substitution `pages -> pages_<crawl_id>`**, injection du CTE
`crawl_categories WHERE project_id=...`, `statement_timeout`, READ ONLY.

À refaire pour CH :
- **Substitution -> filtre `crawl_id`** : CH a 1 table `pages` (pas `pages_123`).
  Le validateur doit **injecter/forcer `WHERE crawl_id = <id>`** (et l'imposer,
  pas juste réécrire le nom) pour garder l'isolation par crawl. Repenser
  `prepareSafeSql()` + `QueryController::execute()`.
- **Whitelist** : mêmes 6 tables (sans suffixe), + interdire les tables système CH
  (`system.*`), les fonctions dangereuses CH (`file()`, `url()`, `remote()`,
  `s3()`, `jdbc()`, `executable()`, dictionaries), les `INSERT/ALTER/OPTIMITE/...`.
- **READ ONLY** : utiliser un **user CH `readonly=1`** + `max_execution_time` +
  `max_result_rows` au lieu de `statement_timeout`/`SET TRANSACTION READ ONLY`.
- **Catégorie** : plus de CTE `crawl_categories` injecté ; on injecte l'expression
  `category` (CASE WHEN du projet) si la requête référence `category`.
- Mettre à jour `tests/Unit/SqlCteValidationTest.php` + `SqlExplorerSecurityTest.php`
  pour le nouveau modèle (crawl_id forcé, fonctions CH interdites).

### Prompts IA (sinon le LLM génère du PG invalide)
- `DrBriefPrompt.php`, `SqlGenPrompt.php`, le schéma de `get_crawl_schema`, la
  description du tool `run_sql` (MCP) + `seo-playbook.js` : réécrire pour enseigner
  le **dialecte ClickHouse** (regex RE2, JSON functions, `crawl_id` obligatoire,
  `category` = expression dérivée, pas de `cat_id`).

---

## 7. Crawler + post-processing (Go / PHP)

### Frontier (reste en PG, logique de crawl quasi inchangée)
- La boucle de crawl continue d'utiliser PG pour la **frontier slim** :
  `getUrlsToCrawl` (WHERE crawled=false), `insertPages` (`ON CONFLICT` dedup +
  promotion `in_crawl`), marquage `crawled=true`, `getCurrentDepth` (resume). Ces
  tables PG ne portent plus les champs lourds (title/html/extracts/...).

### Écriture des données -> ClickHouse (append, pendant le crawl)
- À chaque page crawlée+parsée : au lieu d'`updatePage` (UPDATE PG), on **append une
  ligne dans CH `pages`** (champs observés : code, title, h1, meta, canonical,
  simhash, word_count, schemas, response_time, flags...). + append `links`, `html`,
  `page_schemas`. Batché (bulk insert CH, pas ligne-à-ligne).
- Le crawler Go gagne un **client CH** (`clickhouse-go/v2`) pour ces writes, en plus
  de pgx pour la frontier.

### Post-processing -> exécuté DANS ClickHouse
- Porter les étapes en **SQL ClickHouse**, lancées à chaque `stop`/`finish` :
  - inlinks : `GROUP BY target` sur `links` -> `page_metrics`.
  - **PageRank** : 30 itérations de requêtes CH sur le graphe `links` (rapide ;
    écrit `pri` dans `page_metrics`). C'est LE gain de perf vs PG.
  - semantic : window functions (`COUNT() OVER (PARTITION BY title)`...) -> statuts.
  - duplicate : `bitCount(bitXor(s1,s2))` (trivial en CH) -> `duplicate_clusters`.
  - redirect chains : à partir des `links type='redirect'` -> `redirect_chains`.
  - Chaque sortie écrite via `REPLACE PARTITION` (idempotent, recalcul propre au
    resume). L'union-find des near-dup / la construction des chaînes peuvent rester
    en Go (lecture CH -> calcul -> insert CH).
- Retirer l'étape `categorize` (catégorisation = live, §5).
- `delete-crawl` : `ALTER TABLE ... DROP PARTITION <crawl_id>` sur chaque table CH
  + drop de la frontier PG.

### Clients à ajouter
- **Go** : `github.com/ClickHouse/clickhouse-go/v2` (writes pages/links + post-proc).
- **PHP** : `smi2/phpclickhouse` ou l'interface HTTP CH (lectures). Nouveau singleton
  `ClickHouseDatabase` à côté de `PostgresDatabase`.

### Bulk AI Generator
- `BulkGenerateController`/`BulkGenerator` écrivait `pages.generation` (JSONB) via
  `ON CONFLICT`. -> table CH dédiée `page_generation(crawl_id,id,generation Map)`
  (`ReplacingMergeTree`), jointe à la lecture.

---

## 8. Migration des données existantes PG -> CH

- **Backfill one-shot** : script qui, pour chaque crawl `finished` existant, lit les
  partitions PG (`pages`/`links`/`html`/`page_schemas`) et bulk-insert dans CH, puis
  **rejoue le post-proc en CH** (génère `page_metrics`/`duplicate_clusters`/
  `redirect_chains`), avec contrôle de complétude (counts PG == counts CH), puis drop
  des partitions PG. (Les champs dérivés PG existants ne sont pas réimportés tels
  quels : ils sont recalculés en CH pour cohérence avec le nouveau modèle.)
- **Ordre** : commencer par les crawls récents/consultés ; les vieux en tâche de
  fond.
- **Réversibilité** : tant que le backfill n'est pas validé par les golden tests
  (§10), **ne pas dropper** les partitions PG (rollback possible).
- **Cutover lecture** : un flag par crawl `data_store = pg|clickhouse` (colonne sur
  `crawls`) qui pilote le routing lecture, pour migrer crawl par crawl sans big-bang.

---

## 9. Infra / Docker / connexions
- Ajouter un service `clickhouse` (image `clickhouse/clickhouse-server`) au
  `docker-compose.yml` + `docker-compose.local.yml` (volume de données, `init.sql`).
- Variables d'env : `CLICKHOUSE_URL`/host/port/db/user/password (un user
  applicatif read-write pour writes, un user `readonly=1` pour SQL Explorer/IA/MCP).
- Réglages CH : `max_execution_time`, `max_result_rows`, `max_memory_usage` côté
  user readonly ; quotas.
- `monitor.php` / health : adapter aux métriques CH (`system.parts`, `system.tables`).

---

## 10. Tests & non-régression (impératif)
- **Golden diff par rapport** : sur un crawl de référence, exécuter chaque page de
  rapport en version PG (actuelle) et CH (nouvelle), comparer les sorties
  (counts/distributions) -> tolérance 0 sur les agrégats entiers.
- **Catégorisation** : golden diff entre l'ancien `cat_id` (PG) et la nouvelle
  expression `category` live (CH) sur le même crawl + jeux d'URLs (réutiliser les
  vecteurs de `tests/parity`, mais comparant PG `cat_id` vs CH `CASE WHEN`).
- **SQL Explorer / sécurité** : porter `SqlCteValidationTest` + `SqlExplorerSecurityTest`
  (crawl_id forcé, fonctions CH interdites, isolation par crawl/projet).
- **Backfill** : tests de complétude (counts, checksums) PG vs CH par table/crawl.
- **CI** : job ClickHouse (service `clickhouse` dans `.github/workflows/tests.yml`)
  qui charge le schéma CH et rejoue les requêtes des rapports.

---

## 11. Plan de déploiement par phases
1. **Phase 0 — fondations** : service CH (docker + CI), `init.sql` CH (pages/links/
   html/page_schemas/page_metrics/duplicate_clusters/redirect_chains + page_generation),
   clients Go + PHP, `ClickHouseDatabase`. Aucun changement fonctionnel.
2. **Phase 1 — écriture CH + frontier slim** : le crawler écrit pages/links/html en
   append dans CH ; la frontier PG devient slim. (Données en CH dès le crawl.)
3. **Phase 2 — post-processing dans CH** : porter inlinks/PageRank/semantic/
   duplicate/redirect en SQL CH -> `page_metrics`/`duplicate_clusters`/
   `redirect_chains` via `REPLACE PARTITION`. Retirer l'étape `categorize`.
4. **Phase 3 — couche de lecture CH** : porter le dialecte + le builder de catégorie
   live + jointure `pages`+`page_metrics` ; re-router les lectures (flag `data_store`).
   **Golden diff PG vs CH** vert sur un crawl de référence.
5. **Phase 4 — sécurité SQL** : nouveau `prepareSafeSql` (`crawl_id` forcé, fonctions
   CH interdites), prompts IA/MCP en dialecte CH, tests sécurité.
6. **Phase 5 — cycle de vie** : `delete-crawl` via `DROP PARTITION` CH + drop
   frontier PG ; **worker d'expiration des crawls stoppés > 7 jours** (cron) +
   colonne `crawls.resumable` + refus côté `CrawlController::resume` ; bulk-gen ->
   `page_generation`.
7. **Phase 6 — backfill** : migrer les crawls existants (récents d'abord) + rejouer
   le post-proc CH, valider par golden, basculer `data_store=clickhouse`.
8. **Phase 7 — suppression catégorisation + nettoyage** : retirer `cat_id`, l'étape
   Go `categorize`, le job batch-categorize, le test de parité ; drop des partitions
   PG migrées ; retrait du code PG data-crawl mort ; MAJ README/CLAUDE/refacto.

À chaque phase : golden diff + rollback possible (flag `data_store`, partitions PG
conservées jusqu'à validation).

---

## 12. Décisions (verrouillées)
- ✅ **Modèle d'écriture** : **frontier en PG (slim), données + post-proc dans CH**.
  Les pages/links/html sont **appended dans CH dès le crawl** (pas de copie PG, pas
  d'étape d'export). Le **post-processing tourne dans CH** à chaque `stop`/`finish`
  et écrit les tables dérivées (`page_metrics`, `duplicate_clusters`,
  `redirect_chains`) via `REPLACE PARTITION` (idempotent). La **frontier PG** (slim)
  pilote la boucle + le resume ; elle est **droppée à `finish`** (ou delete/
  expiration). -> PG ne porte jamais les données lourdes -> reste minuscule.
- ✅ **`html`** : **HTML brut dans CH** avec codec `ZSTD` (abandon du
  base64+gzdeflate). Raison : CH compresse la colonne entière (cross-row) en ZSTD,
  bien plus efficace que le gzdeflate par-ligne + le bloat +33% du base64. Le
  crawler **écrit le HTML brut directement dans CH** (on retire l'étape `zipDom`).
  `get_page_html` lit le brut depuis CH. Seul le **backfill** des vieux crawls PG
  doit décoder l'ancien `gzinflate(base64)` avant insert dans CH.
- ✅ **`extracts` / `generation`** : type **`Map(String, String)`** en CH (structure
  plate clé->valeur). `extracts['price']` (accès), `mapKeys(extracts)` (remplace
  `jsonb_object_keys`), `mapContains(extracts,'k')` (remplace `?`). Refacto assumée
  des sites qui faisaient `->>`/`jsonb_object_keys`. `schemas` reste `Array(String)`.
- ✅ **Regex utilisateur** : **avertir dans l'éditeur YAML** (RE2 : pas de
  backreferences/lookaround). Pas de regex complexe nécessaire.
- ✅ **`generation`** (Bulk AI) : table CH dédiée `page_generation(crawl_id,id,
  generation Map(String,String))` en `ReplacingMergeTree`, jointe à la lecture
  (puisque `pages` est immuable). Le bulk-gen écrit dans cette table.
- ✅ **Lecture d'un crawl EN COURS** : **Option B** — on lit **toujours dans CH**.
  Les rapports détaillés ne sont pas consultables tant que le crawl n'est pas
  terminé (= comportement actuel, donc **aucune régression**). Conséquence : pas de
  routing PG/CH à maintenir en régime permanent (lecture = CH point). Le flag
  `crawls.data_store` ne sert plus que pendant la **période de backfill** (les vieux
  crawls encore en PG sont lus en PG jusqu'à leur migration).
- **Suivi live** (monitor + barre de progression) : lu depuis `crawls` (métadonnées
  PG) -> OK, indépendant du store data.

### Cycle de vie d'un crawl & reprise (resume)
Les pages/links sont dans CH **dès le crawl** (append). Un crawl `stopped` est donc
déjà consultable. La **frontier PG** (slim) sert juste à reprendre.

| Transition | données pages/links (CH) | post-proc CH (page_metrics…) | frontier PG | Consultable ? |
|---|---|---|---|---|
| (pendant crawl) | append au fil de l'eau | -                         | active       | non (running) |
| `stop`   | déjà dans CH | **recalcul** (REPLACE PARTITION) | **gardée** (resume) | oui (CH) |
| `resume` | append des nouvelles pages | -            | continue     | non (running) |
| `finish` | déjà dans CH | **recalcul** (REPLACE PARTITION) | **droppée**  | oui (CH) |
| `delete` | DROP PARTITION CH | DROP PARTITION CH          | droppée      | - |

- Pas d'étape d'export : les données sont déjà dans CH. Seules les **tables dérivées**
  (post-proc) sont recalculées à chaque checkpoint (`REPLACE PARTITION`, idempotent).
  Le resume re-crawle uniquement les URLs restantes -> append des nouvelles pages,
  pas de doublon (la frontier garantit 1 crawl par URL).
- **Reprise** : continue via la frontier PG (`getCurrentDepth`, flags in_crawl/
  blocked...). Aucune reconstruction depuis CH (écarté : trop fragile).
- **Crawls stoppés abandonnés -> expiration à 7 jours** : un **worker périodique**
  (cron, à côté de `watchdog.php`/`scheduler.php` déjà lancés par le Dockerfile ;
  ex. `app/bin/stale-crawl-finalizer.php`, run quotidien) scanne les crawls
  `status='stopped'` dont la dernière activité (`finished_at`, posé au stop, ou un
  nouveau `stopped_at`) date de **> 7 jours** et les **finalise** :
  - **rien à recalculer** (les données + le dernier post-proc sont déjà dans CH) ;
  - **drop la frontier PG** -> libère le disque ;
  - **marque le crawl non-reprenable** : colonne `crawls.resumable` (bool, défaut
    true) passée à `false` ; le endpoint resume (`CrawlController::resume`) refuse
    alors de reprendre. Le crawl **reste consultable** (dans CH).
  - Délai 7 jours configurable (env / app_settings).
- Garde-fous : tables dérivées écrites par `REPLACE PARTITION` atomique (staging +
  swap), idempotent ; drop de la frontier PG (à finish) sans risque (données déjà
  dans CH).
- On ne reprend pas un crawl `finished` (déjà le cas aujourd'hui). Re-catégorisation
  d'un crawl = **live** (plus de `cat_id`) -> rien à recalculer.

---

## Annexe — fichiers clés impactés
- Lecture/dialecte : `web/pages/*.php` (~40), `web/components/{url,link,redirect}-table.php`,
  `app/Http/Controllers/{QueryController,ApiV1Controller,ExportController,MonitorController,
  CategorizationController,BulkGenerateController,AIUrlFiltersController,AILinkFiltersController}.php`.
- IA : `app/AI/{SqlExecutor,SqlQueryTool,DrBriefPrompt,SqlGenPrompt,ContextBuilder,
  CategorizationPrompt}.php`, `mcp/{tools.js,seo-playbook.js,server.js}`.
- Catégorisation : `app/Analysis/CategorizationService.php` (buildCaseWhenSql conservé,
  applyCategorization supprimé), `crawler-go/internal/postprocess/categorize.go` (supprimé),
  `tests/parity/*` (catégo), `app/Cli/Cmder.php` (batchCategorizeProject supprimé).
- Écriture/infra : `crawler-go/internal/db/*` (frontier PG slim + client CH pour
  writes pages/links/html + post-proc CH), `app/Database/*`
  (+ `ClickHouseDatabase`), `docker/clickhouse/init.sql` (nouveau), `docker-compose*.yml`,
  `.github/workflows/tests.yml`.
- Schéma/migration : `docker/postgres/init.sql` (retrait cat_id à terme), scripts de
  backfill PG->CH, colonne `crawls.data_store`.
</content>
</invoke>
