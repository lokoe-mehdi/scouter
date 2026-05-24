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

## ÉTAT D'IMPLÉMENTATION (2026-05-24) — quasi complet, en prod-ready

> **État final atteint** : PostgreSQL ne sert plus qu'au **crawl-time** (frontier +
> métadonnées). **TOUS les rapports lisent ClickHouse.** `cat_id` est éliminé des
> requêtes (catégorie calculée en live). Les anciens crawls sont **backfillés** dans
> CH, puis le PG correspondant est **purgé**. Migration + purge **automatiques au
> boot** (`./start.sh` / Coolify). Les 251 crawls de l'instance de dev ont été migrés.

Le flag historique `CLICKHOUSE_URL` (absent = PG-only) existe toujours, mais la cible
est désormais **CH partout**. La lecture est routée par `crawls.data_store`
(`pg` | `clickhouse`) : un crawl migré → CH ; un crawl pas encore migré (pendant le
backfill) → PG **via un shim qui calcule quand même `category` en live** (transitoire).

### Flags d'environnement (les deux ON par défaut dans les compose)
- `CLICKHOUSE_URL=http://clickhouse:8123` (+ `CLICKHOUSE_DB/USER/PASSWORD`) : active CH.
- `CLICKHOUSE_AUTO_MIGRATE=1` (défaut) : au démarrage du worker `crawler-go`, backfill
  en tâche de fond de tous les crawls pas encore migrés (idempotent, saute les faits).
- `CLICKHOUSE_DROP_PG=1` (défaut) : DESTRUCTIF — saute le post-proc PG, droppe les
  données PG en fin de crawl, ET purge le PG des anciens crawls après le backfill auto
  (après contrôle de complétude CH). Mettre `0` pour garder PostgreSQL.

### Fait & testé (contre clickhouse-server 24, données réelles)
- ✅ **Fondations** : `docker/clickhouse/init.sql` (pages/links/html/page_schemas +
  dérivées page_metrics/duplicate_clusters/redirect_chains + page_generation).
  pages/html/page_schemas = **ReplacingMergeTree** (dédoublonnage physique de l'append).
  Service `clickhouse` dans les 2 compose + `.env.example` + service+schéma dans la CI
  (`.github/workflows/tests.yml`). Healthcheck = `clickhouse-client -q 'SELECT 1'`
  (le `wget` sur localhost est refusé dans l'image alpine).
- ✅ **Clients** : Go HTTP (`crawler-go/internal/db/clickhouse.go` : InsertJSONEachRow,
  QueryTSV/Scalar, DropPartition) ; PHP HTTP (`app/Database/ClickHouseDatabase.php`,
  param `{name:Type}`, settings `prefer_column_name_to_alias=1`).
- ✅ **Dual-write pendant le crawl** : `crawl/ch_store.go` append pages/links/html/
  page_schemas dans CH (HTML brut décodé du DomZip). PG est aussi écrit (transition) ;
  le crawler pose `data_store='clickhouse'` au démarrage du crawl si CH actif.
- ✅ **Post-proc DANS ClickHouse** (`postprocess/clickhouse.go`, `CHRunner`) : inlinks,
  **PageRank** (Memory tables `pr_cur/pr_next`, 30 itérations sur le graphe `links`),
  statuts sémantiques (window funcs), duplicate (exact en SQL + near-dup Hamming
  union-find en Go), redirect-chains. Écrit page_metrics/duplicate_clusters/
  redirect_chains via **DROP PARTITION + INSERT** (idempotent). Lit des **pages
  dédoublonnées** (`LIMIT 1 BY id`).
- ✅ **TOUS les rapports sur ClickHouse** — `cat_id` éliminé, catégorie = colonne
  `category` live (le NOM).
  - **Shim `ChPdo`/`ChStmt`** (compatible PDO, `app/Database/`) câblé dans
    `dashboard.php`. Réécrit `FROM/JOIN pages|links|…` en sous-requêtes
    `crawl_id`-filtrées, expose `category` + `cat_id` (synthétique = index de règle)
    + colonnes page_metrics + generation, dédoublonne (`LIMIT 1 BY id`), traduit le
    dialecte PG→CH (`::cast`, `FILTER`→`countIf`, `->>'k'`/`:p`/`{p}`→Map, `unnest`→
    `arrayJoin`, `substring(x[ ,/FROM]'re')`→`extract`, `array_length`→`length`,
    `percentile_cont … WITHIN GROUP`→`quantileExact`, `pages_<id>`/`pages@<id>`→source
    du crawl).
  - **Multi-crawl / comparaison** : `ChPdo(crawlId, compareId)` couvre les 2 crawls ;
    `<table>@<id>` géré ; sous-requêtes **corrélées** cross-crawl (que CH ne supporte
    PAS : `EXISTS (… b.url=c.url [AND b.col!=c.col])`) réécrites en `IN`/`NOT IN` non
    corrélées (les value-change via un JOIN **dans** la sous-requête `IN`).
  - **PERF (important)** : la catégorie (regex RE2) + les jointures page_metrics/
    page_generation ne sont injectées **que si la requête les référence**
    (`featuresOf()`). → requêtes sans catégorie ≈ 48 ms même sur 260k pages ; avec
    catégorie ≈ 140 ms. Sinon un gros crawl (152k pages) ramait.
  - **`PgReportPdo`** (`app/Database/`) : shim PG symétrique pour les crawls PAS encore
    migrés — injecte `category` en live (PG `~*`, **n'enrobe que si `category` est
    référencé** → vitesse PG native sinon).
  - **22 rapports validés** mono-crawl (harness `scripts/ch_report_smoke.php`) +
    **16 rapports de comparaison** (`scripts/ch_compare_smoke.php`) + url/link-explorer
    + composants `url-table`/`link-table`/`table-core`. Filtres catégorie des explorers
    = par NOM. Icône SQL des graphes en dialecte CH (`translateDialectOnly`).
  - **Fix racine url-table** : `c.crawl_id = X AND` était injecté dans **chaque** WHERE
    (donc dans les sous-requêtes → corrélation → CH refuse) — limité au 1er WHERE.
- ✅ **SQL Explorer / API v1 / MCP** : `ClickHouseSqlExecutor` (crawl_id forcé par
  sous-requêtes, dialecte CH, blocklist de fonctions `file/url/remote/s3/system…`,
  `category`+`cat_id` live, jointure page_metrics ; réutilise `ChPdo::virtualSource()`
  → zéro divergence avec les rapports). `ApiV1Controller::query/schema` +
  `QueryController::execute` routent vers CH si `data_store=clickhouse`.
- ✅ **Catégorisation live** : `app/Analysis/CategoryExpr.php` — `build()` (CH `match()`
  RE2), `buildPg()` (PG `~*`), `buildIdExpr()` (cat_id synthétique), `forCrawl()/
  forCrawlPg()` (charge le YAML du crawl puis du projet). `CrawlStore::usesClickHouse()`
  route. Couleurs par NOM via `getCategoryColor()` (déjà name-based dans le dashboard).
- ✅ **Backfill PG→CH** : `scouter-crawler backfill <id|all>`
  (`crawler-go/internal/backfill/backfill.go`). Lit les partitions PG, bulk-insert CH
  (HTML décodé base64+flate, extracts→Map, NULL-safe sur title/h1/… et code/RT),
  rejoue le post-proc CH, contrôle de complétude (counts CH ≥ 90% PG), flip
  `data_store`. **2 phases** : (1) pages/links/schemas/post-proc/flip = rapports OK
  vite ; (2) HTML (view-source, lourd) **en dernier, non-bloquant** pour ne pas qu'un
  gros crawl bloque la file. Idempotent (DROP PARTITION avant). **`SyncStats`** recopie
  les scorecards (clusters_duplicate, compliant_duplicate, redirect_*) de CH→`crawls.*`.
- ✅ **Purge PG** : `scouter-crawler purge-pg <id|all>` — `drop_crawl_partitions` PG
  des crawls `data_store=clickhouse`, **re-vérifie CH avant chaque drop** (jamais de
  perte). Synchronise aussi les scorecards au passage.
- ✅ **Auto-migration + purge au boot** : si `CLICKHOUSE_AUTO_MIGRATE`, le worker
  backfill tout au démarrage (en goroutine, worker dispo tout de suite) ; si
  `CLICKHOUSE_DROP_PG` aussi, purge ensuite. → `./start.sh`/Coolify migre + purge seul.
- ✅ **monitor.php** : carte storage = **total global + détail PostgreSQL vs ClickHouse**
  (taille CH via `system.parts`) + **storage par crawl** (PG / CH par crawl + badge
  store) + **barre de progression migration** (X/Y migrés). Bloc "projects" retiré.
- ✅ **CI** : service `clickhouse` + chargement du schéma + test d'intégration Go
  (`internal/db/clickhouse_integration_test.go`, opt-in si `CLICKHOUSE_URL`).

### Bugs corrigés en cours de route (pièges CH rencontrés)
- Pages dupliquées (append-only + sitemap re-fetch/retries) → dédoublonnage `LIMIT 1
  BY id` partout + ReplacingMergeTree.
- Nouvel analyzer CH : ne résout pas une colonne via `t.*` à travers plusieurs JOINs,
  ni une colonne partagée (crawl_id) entre sous-requêtes jointes → colonnes énumérées
  explicitement + clés des sous-requêtes jointes renommées (`_mcid/_mid`).
- Placeholders liés AVANT la traduction (la regex catégo contient des `?`/`(?i)`).
- Arrays CH rendus en string PG `{a,b}` (compat `trim`/`explode`/`unnest` des rapports).
- Backfill : NULL non scannables (title/h1/… nullable), html `unexpected EOF` (rendu
  non-fatal), contention quand 2 backfills tournaient en // (un seul à la fois).
- **Graphes vides (count/sum en strings)** : CH en `FORMAT JSON` quote les entiers
  64 bits (UInt64/Int64, ce que renvoient `count()`/`sum()`) → `json_decode` donnait
  des **strings** (`"95"`). Highcharts (pie/stacked) concatène alors les totaux au
  lieu de les additionner → **slices/barres vides** (seo-tags, et tout rapport ne
  castant pas en `(int)` ; headings marchait car il castait). Fix : `ClickHouseDatabase::httpQuery`
  ajoute `output_format_json_quote_64bit_integers=0` → les agrégats reviennent en
  nombres JSON (donc int/float PHP). Concerne TOUS les rapports CH d'un coup.
- **Pages externes absentes de CH** (régression : rapport "top external domains" /
  pagerank-leak, accessibility, pagerank, link-explorer vides). Cause : seules les
  pages crawlées étaient écrites dans CH `pages` ; les targets externes
  (`external=1, crawled=0`) restaient en PG (frontier). Fix : (a) dual-write live
  `CHStore.AddExternalPage` (dédup par id) appelé aux 3 sites `InsertPage` externes
  (redirect/canonical/ahref) dans `crawl/store.go` ; (b) backfill `pages()` =
  `WHERE in_crawl AND (crawled OR external)` + `crawled` réel (plus codé en dur à 1).
  Le post-proc lit `pages`, donc PageRank (dead-ends externes) + inlinks les couvrent
  sans changement. ⚠️ Les 251 crawls **déjà migrés+purgés** ont perdu leurs pages
  externes (PG droppé) → vides jusqu'à un re-crawl ; le fix vaut pour les nouveaux
  crawls et tout crawl re-backfillé tant que son PG existe encore.

- **URL-explorer / catégorisation / IA câblés sur CH** (étaient restés en PG brut →
  vides après purge). (a) `QueryController` url-details/inlinks/outlinks/html-source/
  quick-search routent vers `ChPdo` si CH (helper `reportDb()`), `category` (nom) au
  lieu de `cat_id`, extracts Map déjà décodé, HTML brut (pas base64+gzip).
  (b) `CategorizationController` stats/test/save/table en live CH (no cat_id) +
  `CategorizationService::testCategorizationCH` ; save saute `applyCategorization`+batch.
  (c) **Dr Brief `SqlQueryTool`** route vers `ClickHouseSqlExecutor` (était `SqlExecutor`
  PG en dur). (d) `ClickHouseSqlExecutor` **traduit le dialecte PG** (réutilise
  `ChPdo::translateDialectOnly`+`translateTablesOnly`, alias & crawl_categories gérés) et
  `ChPdo::rewriteDialect` apprend `~*`/`~`→`match()` → SQL Explorer / Dr Brief / MCP
  acceptent PG ET CH. (e) `SqlGenPrompt`/`DrBriefPrompt` ont une variante **dialecte CH**
  (gate `ClickHouseDatabase::enabled()`) ; panel schéma SQL Explorer = schéma CH
  (`category`, Map, dérivées) via `ApiV1Controller::clickHouseVirtualSchema()`.
- **Preview / éditeur de segment / AI catégo câblés sur CH** (2e passe) :
  (a) `MonitorController::preview` (onglet Preview du modal URL) lisait pages/html en
  PG brut → "URL non trouvée" ; routé via ChPdo + HTML brut (pas base64+gzip).
  (b) `web/pages/categorize.php` : éditeur vide car il lisait `categorization_config`
  (table **PG**) via `$pdo`=**ChPdo** (envoyé à CH où la table n'existe pas → échec
  silencieux). Corrigé : métadonnées via handle PG brut (`$pdoPg`/`$metaPdo`) + fallback
  `projects.categorization_config` quand la config crawl-level manque.
  (c) `AICategorizationController::sampleCrawl/randomFromBucket` échantillonnaient
  `pages_<id>` (PG purgé) → "No internal crawled URLs found" ; branche CH
  (`{db}.pages … rand() … LIMIT 1 BY id`).
  ⚠️ **Leçon** : toute requête de **métadonnée** (categorization_config, projects,
  crawl_categories, crawls) doit passer par le **PG brut**, jamais par `$pdo`=ChPdo
  (qui route vers ClickHouse).
- **Save catégorisation = propagation projet-wide** : chaque crawl FIGE la config
  projet dans son `categorization_config` à la création (Cmder/scheduler), et la
  catégorie live lit ce snapshot par-crawl EN PRIORITÉ → éditer le projet ne changeait
  que le crawl courant. Fix : `CategorizationController::save` upsert le nouveau YAML
  dans le snapshot de **TOUS les crawls du projet** (instantané, c'est live → aucun
  recalcul). → save sur un crawl = catégories à jour partout dans le projet direct.

### Reste à faire
- ⛔ **`in_sitemap`** dans CH : laissé à 0 dans page_metrics (la membership sitemap
  n'est pas encore portée dans CH). À traiter avec l'analyse sitemap dans CH.
- ⚠️ **Template Dr Brief stocké** (`app_settings.ai.openrouter.dr_brief_prompt`) :
  override le `defaultTemplate()`. S'il existe (custom admin), il garde son dialecte PG —
  fonctionne quand même (l'executor traduit), mais pour le CH-natif il faut le reset
  depuis /settings. Le défaut, lui, est CH-aware.
- ⛔ **Suppression définitive côté schéma PG** : colonne `pages.cat_id`,
  `CategorizationService::applyCategorization`, job `batch-categorize`, étape Go
  `categorize.go` + test de parité — toujours en place (utile pour PG/transition).
  À retirer une fois la migration 100% validée partout.
- ⛔ **Frontier PG slim** : PG garde encore le schéma `pages` complet (pas une table
  frontier dédiée) ; la purge vide les données mais le modèle "frontier slim" du §3
  n'est pas implémenté tel quel. Les crawls EN COURS gardent leur `pages` PG.
- ⛔ **Worker d'expiration 7 j** (crawls stoppés) + `crawls.resumable` : non fait.

### Fichiers clés (réalisés)
- Go : `crawler-go/internal/db/clickhouse.go`, `internal/crawl/ch_store.go`,
  `internal/postprocess/clickhouse.go` (CHRunner), `internal/backfill/backfill.go`,
  `internal/db/crawldb.go` (SetDataStore), `cmd/scouter-crawler/main.go` (CH client,
  backfill/purge-pg/auto-migrate, CH post-proc + SyncStats).
- PHP : `app/Database/{ClickHouseDatabase,ChPdo,ChStmt,PgReportPdo,CrawlStore}.php`,
  `app/Analysis/CategoryExpr.php`, `app/AI/ClickHouseSqlExecutor.php`,
  `app/Http/Controllers/{ApiV1Controller,QueryController}.php`, `web/dashboard.php`,
  `web/components/{chart,url-table,link-table,table-core}.php`, `web/pages/*` (rapports
  mono + comparaison convertis cat_id→category), `web/pages/monitor.php`.
- Infra : `docker/clickhouse/init.sql`, `docker-compose.yml` + `.local.yml`,
  `.env.example`, `migrations/2026-05-24-09-00-crawl-data-store.php` (colonne
  `crawls.data_store`), `docker/postgres/init.sql` (colonne data_store),
  `scripts/ch_report_smoke.php`, `scripts/ch_compare_smoke.php`.
- Branche : `feat/clickhouse-migration` (non mergée, non poussée).

### Commandes utiles
```
# migrer tous les anciens crawls PG -> CH (idempotent)
docker compose -f docker-compose.local.yml run --rm crawler-go go run ./cmd/scouter-crawler backfill all
# purger le PG des crawls migrés (re-vérifie CH avant chaque drop)
docker compose -f docker-compose.local.yml run --rm crawler-go go run ./cmd/scouter-crawler purge-pg all
# prod (binaire) : docker compose run --rm crawler-go backfill all  |  purge-pg all
```

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
