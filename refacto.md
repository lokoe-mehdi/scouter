# Refacto — Réécriture du crawler backend en Go

> **Objectif** : remplacer le moteur de crawl + le post-processing PHP par un binaire **Go** unique,
> plus rapide, plus robuste et plus économe en mémoire, **sans toucher** au schéma PostgreSQL,
> au web/API PHP, ni au format de config. Le renderer JS (déjà en Go) est absorbé/conservé.
>
> Stratégie : **strangler pattern** — on remplace le worker de crawl par un worker Go qui lit/écrit
> exactement les mêmes tables. À aucun moment l'app PHP (UI, API, MCP, dashboard, SQL explorer) ne change.

---

## ✅ État : implémenté (v1)

Le moteur Go est implémenté dans **`crawler-go/`** (voir `crawler-go/README.md`). Périmètre v1 = crawl + post-process.

- **Parité bit-pour-bit validée** (tests Go vs PHP 8.3) : `PageID` (CRC-32/BZIP2 hex little-endian),
  `Simhash` (64 bits), `rel2abs`. → `go test ./...`
- **Test d'intégration end-to-end réussi** contre le vrai schéma PostgreSQL (`init.sql`) : un crawl
  complet (fetch → parse → store → inlinks → pagerank → semantic → duplicate → redirect) écrit des
  pages/links/html/page_schemas corrects, avec `compliant` (noindex exclu), positions de liens
  (Navigation/Footer/Content), pagerank et inlinks calculés.
- **Cohabitation** : worker Go claim `command='crawl'` ; worker PHP filtre `AND command <> 'crawl'`
  via `DELEGATE_CRAWL_TO_GO=1` (ajouté à `docker-compose.yml` + service `crawler-go`).
- **Reste à faire** (post-parité sur crawls réels) : bascule écriture DB vers `COPY` (§6.4),
  golden test DB contre un crawl PHP réel (Phase 0), test de reprise (resume) dédié.

---

## 1. Pourquoi ce chantier

### Ce qui est lent / fragile aujourd'hui (constats sur le code actuel)

| Problème | Localisation actuelle | Impact |
|---|---|---|
| **Un process PHP par crawl**, `memory_limit=-1` | `scouter.php` | OOM possible, pas de back-pressure mémoire réelle |
| **Instanciation d'objets par page** (`new Page`, `new PageCrawler`, `new CrawlDatabase` à chaque URL dans le callback) | `DepthCrawler::runNormal/runJavascript` | overhead GC/alloc massif sur gros crawls |
| **`DOMDocument` recréé 3-4× par page** (extract XPath, headings, microdata, RDFa, readability) | `Page.php` | le HTML est re-parsé plusieurs fois → CPU gaspillé |
| **Parsing HTML via regex + ElementFinder + DOMDocument mélangés** | `Page.php`, `HtmlParser.php` | lent, fragile sur HTML malformé |
| **RollingCurl** (lib PHP) pour la concurrence HTTP | `DepthCrawler` | concurrence limitée, pas de vrai pool de connexions réutilisables |
| **Readability.php** (lourd) appelé 2× par page (word_count + simhash) | `Page::calculateWordCount`, `computeSimhash` | très coûteux, re-parse encore le DOM |
| **Boucle de profondeur en passes de 5000** avec re-création d'objets | `Crawler::depthStarter` | architecture par "depth" rigide |

### Ce qui est DÉJÀ bon et ne doit pas être jeté

- **Le post-processing est à ~90 % du SQL pur PostgreSQL** (inlinks, pagerank, semantic, categorize,
  duplicate, redirect chains). Cf. `PostProcessor.php`. → En Go on **ré-exécute exactement le même SQL** ;
  le gain de perf vient du fait qu'on retire l'overhead PHP autour, pas de réécrire l'algèbre.
- **Le schéma PostgreSQL partitionné par `crawl_id`** est solide et reste **inchangé**.
- **Le renderer JS est déjà en Go** (`renderer/main.go`, go-rod + Chrome) → on le garde tel quel
  (ou on le fusionne dans le binaire crawler à terme).
- **La sécurité anti-SSRF** (`SafeHttp`) et la logique robots/sitemap sont matures → à porter à l'identique.

> ⚠️ **Important à dire honnêtement** : le post-processing étant déjà du SQL, le réécrire en Go n'apporte
> **pas** de gain de perf en soi sur ces étapes (c'est Postgres qui travaille). Le vrai gain Go est sur
> **(1) le fetch concurrent**, **(2) le parsing HTML / extraction**, **(3) simhash + word_count**, et
> **(4) la suppression de l'overhead "1 objet PHP par URL"**. On porte quand même le post-processing en Go
> pour avoir **un seul binaire** qui pilote tout le cycle de vie — c'est un gain d'architecture/robustesse,
> pas de calcul brut.

---

## 2. Architecture actuelle (analyse de fond)

### 2.1 Cycle de vie complet d'un crawl

```
[Web UI / API PHP]
   POST création crawl  → INSERT crawls (status='pending', config JSONB)
   POST start crawl     → JobManager::createJob → INSERT jobs (status='queued')
                          UPDATE crawls SET status='queued', in_progress=1
        │
        ▼
[Worker PHP — app/bin/worker.php]  (4 replicas en Docker)
   boucle poll 2s :
     SELECT … FROM jobs WHERE status='queued' LIMIT 1 FOR UPDATE SKIP LOCKED
     UPDATE jobs SET status='running', pid=…
     CrawlDatabase::createPartitions()   ← crée les partitions AVANT (anti-deadlock)
     env: DATABASE_URL, RENDERER_URL(=renderer-1,2,3), MAX_CONCURRENT_CURL/CHROME,
          JOB_ID, PARTITIONS_CREATED=1
     proc_open("php scouter.php crawl <path>")   ← bloque jusqu'à fin
     selon exit code → jobs.status = completed / failed / stopped
        │
        ▼
[scouter.php crawl <path>]
   Cmder::crawl() :
     CrawlDatabase::getCrawlByPath(path) → config JSONB
     mapping config (general+advanced → clés "runtime" : respect.*, httpAuth, customHeaders…)
     new Crawler({crawl_id, depthMax, start, pattern=domains, config, crawl_type, url_list})
     Crawler::run()
        │
        ▼
[Crawler — app/Core/Crawler.php]  (orchestrateur)
   insertStart()  (spider: 1 URL depth 0  |  list: toutes les URLs depth 0)
   depthStarter() :
     for i in 0..depthMax :
        countRemainingUrls(i)
        while passes < 50 :
           urls = getNextUrls(batch=5000, depth=i)
           new DepthCrawler(...)->run({depth:i, urls, …})
     runPostProcessing()
     finishCrawl() / updateCrawlStats()
        │
        ▼
[DepthCrawler — app/Core/DepthCrawler.php]  (fetch parallèle d'une profondeur)
   3 modes :
     - classic        → RollingCurl, callback par réponse, retry backoff (2/4/8/16s, 429+5xx+timeout)
     - throttling     → batchs de N=targetUrlsPerSecond, sleep entre batchs
     - javascript     → POST /render-batch vers renderer Go (20 URLs/renderer × 3)
   vitesse : very_slow(1) / slow(5) / fast(20) / unlimited(0)  → simultaneousLimit + targetUrlsPerSecond
   override: MAX_CONCURRENT_CURL (env) puis max_concurrent_curl (config)
   checkStopSignal() périodique (lit crawls.status)
   chaque réponse → new PageCrawler(...)->run(request)
        │
        ▼
[PageCrawler — app/Core/PageCrawler.php]  (1 page → DB)
   new Page(url, headers, dom, pattern, config) → getPage()
   transaction (DeadlockRetry, 3 essais sur 40P01) :
     storePageComplete()   → UPDATE pages (code, title, h1, metadesc, canonical, simhash, schemas, extracts…)
     si redirect           → storeRedirect()  (insert link type=redirect + page cible même depth)
     sinon                 → storeLinks()     (insert links + insertPages des cibles depth+1)
                             storeRaw()        (insert html gzip base64, si store_html)
   skip_link_extraction (mode sitemap-only) → seulement storePageComplete + storeRaw
        │
        ▼
[Page — app/Core/Page.php]  (parsing/extraction — LE cœur métier)
   isHtml? + content-type html?  → encode(charset) → ElementFinder + DOMDocument
   extract()    : title, h1, meta_desc, canonical(abs) + xPathExtractors(+fns XPath2.0) + regexExtractors
   parse()      : liens via HtmlParser::extractLinksWithPosition (target abs, anchor, rel/nofollow,
                  xpath enrichi, position Nav/Header/Footer/Aside/Content), external?, blocked(robots)?
   configuration(): nofollow/noindex (meta robots + X-Robots-Tag), canonical (self?)
   detectIsHtml(): extension URL + content-type + magic bytes + ratio imprimable
   computeSimhash(): Readability → texte visible → Simhash::compute (64 bits)
   calculateWordCount(): Readability → strip → str_word_count
   analyzeHeadings(): h1_multiple, headings_missing (séquençage h1..h6)
   extractSchemaTypes(): JSON-LD (@type/@graph) + Microdata (itemtype) + RDFa (typeof)
   domZip = base64(gzdeflate(dom))     ← HTML stocké compressé
   id = crc32(url) en hex (CHAR(8))
```

### 2.2 Post-processing (`PostProcessor::run`)

Advisory lock `crawl_id + 200000`, `statement_timeout=0`, puis 7 étapes séquentielles
(chaque étape try/catch isolé — une étape qui plante ne fait pas échouer le crawl) :

1. **`calculateInlinks`** — 1 UPDATE : `pages.inlinks = COUNT(links.target)` (filtre `in_crawl=true`).
2. **`calculatePagerank`** — 30 itérations, damping 0.85, **entièrement en SQL** via `TEMP TABLE tmp_pr`,
   `work_mem=128MB`, gestion dead-ends, clause `nofollow=false` si `respect_nofollow`.
3. **`semanticAnalysis`** — 1 UPDATE avec window functions : `title/h1/metadesc_status` ∈ {empty,duplicate,unique}.
4. **`categorize`** — `CategorizationService` : règles YAML (projet > crawl) → `UPDATE … cat_id` par regex
   `~*`, premier match gagne, crée les `crawl_categories` au niveau projet.
5. **`duplicateAnalysis`** — exacts (même simhash, GROUP BY) + near-dup (Hamming ≤ 9 sur top 2000 par inlinks,
   `bit_count(p1.simhash # p2.simhash)`), clustering Union-Find en PHP → `duplicate_clusters`.
6. **`redirectChainAnalysis`** — charge les links `type=redirect`, construit les chaînes + détection de boucles
   (en PHP), → `redirect_chains`.
7. **`sitemapAnalysis`** — seulement si crawl `finished` (jamais sur stop/failed). Parse sitemap(s),
   `in_sitemap=true` sur pages connues, insère les URLs sitemap-only (`depth=-1, in_crawl=false`),
   fetch des nouvelles in-scope via `DepthCrawler` avec `skip_link_extraction=true`.

### 2.3 Couche DB (inchangée par le refacto)

- **`PostgresDatabase`** : singleton PDO, `deadlock_timeout=200ms`, `lock_timeout=60s`, `statement_timeout=120s`.
- **`CrawlDatabase`** : insertPage(s)/insertLink(s)/insertHtml/insertPageSchemas/updatePage,
  getUrlsToCrawl, countUrlsToCrawl, getCurrentDepth, updateCrawlStats, createPartitions, finishCrawl.
- **`DeadlockRetry`** trait : retry sur `40P01/40001/55P03`, backoff 50→500ms, 3 essais.
- **Partitionnement LIST par `crawl_id`** : tables `pages`, `links`, `html`, `page_schemas`,
  `duplicate_clusters`, `redirect_chains` → `*_<crawl_id>`. Fonctions PL/pgSQL `create_crawl_partitions(id)` /
  `drop_crawl_partitions(id)`, advisory lock `12345` pour sérialiser la création.

**Tables clés** (résumé des colonnes structurantes) :

- `pages(crawl_id, id CHAR(8), url, domain, depth, code, response_time, inlinks, outlinks, pri,
  content_type, redirect_to, crawled, compliant, noindex, nofollow, canonical, canonical_value,
  external, blocked, title, title_status, h1, h1_status, metadesc, metadesc_status, extracts JSONB,
  simhash BIGINT, is_html, h1_multiple, headings_missing, schemas TEXT[], word_count, in_crawl, in_sitemap,
  cat_id)` — PK `(crawl_id, id)`.
- `links(crawl_id, src CHAR(8), target CHAR(8), anchor, external, nofollow, type, xpath, position)` —
  **pas de PK** (multigraphe : doublons voulus).
- `html(crawl_id, id CHAR(8), html TEXT)` — HTML stocké (base64 gzdeflate côté PHP, cap ~1 Mo).
- `crawls(... status, config JSONB, urls, crawled, compliant, depth_max, crawl_type, in_progress, scheduled …)`.

> **Sémantiques DB subtiles à préserver absolument :**
> - `id` = `hash('crc32', $url)` en **hex** → `CHAR(8)`. (À reproduire bit-à-bit en Go : `crc32` IEEE, format hex.)
> - `insertPages` fait `ON CONFLICT (crawl_id,id) DO …` avec **promotion `in_crawl` false→true**
>   seulement si `in_sitemap=false` (cf. logique nofollow / sitemap).
> - Redirections : la cible garde **le même depth** que la source (pas depth+1).
> - `compliant` = `!blocked && !noindex && (canonical || !respect_canonical) && code==200 && domHash non vide`.

### 2.4 Renderer JS (déjà Go)

`renderer/main.go` — go-rod + Chrome headless, pool de pages (`PagePoolSize=20`, `MaxConcurrentPages=50`),
endpoints `/render`, `/render-batch` (≤20 URLs), `/health`. Capture httpCode + TTFB + finalURL + jsRedirect.
3 replicas en Docker. **On le conserve tel quel** ; le crawler Go l'appellera via HTTP (comme aujourd'hui),
avec possibilité ultérieure de l'embarquer dans le même binaire.

---

## 3. Architecture cible (Go)

### 3.1 Principe

Un binaire **`scouter-crawler`** (Go) qui remplace `app/bin/worker.php` **et** `scouter.php crawl`.
Il :
1. poll la table `jobs` (même protocole `FOR UPDATE SKIP LOCKED`),
2. exécute le crawl en interne (goroutines + worker pool), au lieu de `proc_open`,
3. exécute le post-processing (mêmes requêtes SQL),
4. met à jour `jobs` / `crawls` exactement comme aujourd'hui.

> **Décision (verrouillée) : un seul process Go multi-crawls.** Le binaire gère **plusieurs crawls
> en parallèle** via un pool de jobs interne (goroutines), au lieu de N replicas Docker à 1 job chacun.
> Implications d'archi à concevoir dès le départ :
> - **Pool de jobs** : `maxConcurrentCrawls` (config/env) crawls actifs simultanément ; chaque crawl
>   a son propre sous-pool de goroutines de fetch (`simultaneousLimit`).
> - **Budget mémoire/CPU global partagé** entre crawls : un sémaphore global de connexions HTTP sortantes
>   et de connexions Chrome (renderer), pas seulement par crawl, pour éviter qu'un gros crawl affame les autres.
> - **Pool pgx unique** dimensionné pour N crawls × leurs writers (attention au `max_connections` Postgres).
> - **Isolation des pannes** : un crawl qui panique (`recover`) ne doit pas tuer le process ni les autres crawls.
> - **Arrêt gracieux** : SIGTERM → on arrête d'accepter de nouveaux jobs, on laisse finir/checkpointer les crawls
>   en cours (ou on les repasse `queued` pour reprise via `getCurrentDepth`).

### 3.2 Packages Go proposés

```
cmd/scouter-crawler/main.go        ← entrypoint worker (poll jobs)
internal/
  config/      mapping crawls.config (JSONB) → Config struct (équiv. Cmder::crawl)
  jobs/        JobManager : poll, claim, status, logs (équiv. app/Job/JobManager.php)
  db/          pool pgx, retry deadlock, partitions, batch COPY/insert
  crawl/
     orchestrator.go   ← équiv. Crawler.php (frontier, depth, stop signal)
     fetcher.go        ← équiv. DepthCrawler (HTTP client réutilisé, worker pool, throttle, retry)
     renderer.go       ← client HTTP du renderer Go (/render-batch)
  page/
     parse.go          ← équiv. Page.php (golang.org/x/net/html, 1 seul parse)
     links.go          ← extractLinksWithPosition (anchor, rel, xpath, position)
     extract.go        ← title/h1/meta/canonical + xpath/regex extractors
     schema.go         ← JSON-LD / Microdata / RDFa
     content.go        ← readability (go-readability) → word_count + texte simhash
     detect.go         ← detectIsHtml (extension/content-type/magic bytes)
  analysis/
     simhash.go        ← équiv. Simhash.php (MÊME algo, voir §5.2)
     robots.go         ← équiv. RobotsTxt.php (fetch+cache+match)
     sitemap.go        ← équiv. SitemapParser (limites identiques)
     safehttp.go       ← équiv. SafeHttp (anti-SSRF)
  postprocess/
     inlinks.go pagerank.go semantic.go categorize.go duplicate.go redirect.go sitemap.go
     (chaque fichier = même SQL que PostProcessor.php, piloté depuis Go)
```

### 3.3 Choix de librairies Go (à valider)

| Besoin | Lib PHP actuelle | Lib Go proposée |
|---|---|---|
| Postgres | PDO | `jackc/pgx/v5` (+ `pgxpool`) — COPY pour inserts massifs |
| Parsing HTML | DOMDocument/ElementFinder | `golang.org/x/net/html` (+ `PuerkitoBio/goquery` si confort) |
| XPath | DOMXPath | `antchfx/htmlquery` (XPath sur net/html) |
| Readability | fivefilters/readability.php | `go-shiori/go-readability` |
| HTTP fetch | RollingCurl | `net/http` (client unique, pool de conns, HTTP/2) + worker pool maison |
| Charset | mb_convert_encoding | `golang.org/x/net/html/charset` + `golang.org/x/text/encoding` |
| YAML (catégo) | spyc | `gopkg.in/yaml.v3` |
| Gzip HTML | gzdeflate + base64 | `compress/flate` + `encoding/base64` (**format identique obligatoire**) |
| Simhash | maison | maison (réimplémentation **exacte**) |
| crc32 id | `hash('crc32')` hex | `hash/crc32` IEEE → `fmt.Sprintf("%08x")` (**à vérifier polynôme**) |

---

## 4. Mapping détaillé PHP → Go (parité fonctionnelle)

| Composant PHP | Go cible | Notes de parité critiques |
|---|---|---|
| `scouter.php` + `Cmder::crawl` | `config` + `main` | reproduire le mapping `general/advanced → respect.*, httpAuth, customHeaders, xPath/regexExtractors`, override env `MAX_CONCURRENT_*` |
| `app/bin/worker.php` | `cmd/scouter-crawler` | poll `jobs`, orphan recovery (`running→queued`, `stopping→stopped`), signaux SIGTERM, backoff erreurs, heartbeat |
| `Crawler.php` | `crawl/orchestrator.go` | frontier par depth, batch 5000, `getCurrentDepth` pour resume, stop signal, lance post-process puis `finishCrawl` |
| `DepthCrawler.php` | `crawl/fetcher.go` | 3 modes (classic/throttle/js), vitesses, retry backoff (429/5xx/timeout, 4 essais), stats périodiques |
| `PageCrawler.php` | `page` + `db` | transaction par page OU batch (cf. §6 perf), redirect vs links vs sitemap-only |
| `Page.php` | `page/*.go` | **1 seul parse DOM** au lieu de 4 ; reproduire id crc32, compliant, canonical abs, schemas |
| `HtmlParser::extractLinksWithPosition` | `page/links.go` | xpath enrichi + position (Nav/Header/Footer/Aside/Content) |
| `Simhash.php` | `analysis/simhash.go` | **algo identique** (shingles 3 mots, 64 bits, vote) sinon les clusters divergent |
| `RobotsTxt.php` | `analysis/robots.go` | cache par host, UA Googlebot, wildcards `*`/`$`, anti-SSRF |
| `SitemapParser` | `analysis/sitemap.go` | mêmes limites (50k URLs, 50 sitemaps, depth 2, 50MB, gzip, txt) |
| `SafeHttp` | `analysis/safehttp.go` | mêmes plages IP privées v4/v6, validate final IP post-redirect, bypass `SCOUTER_ALLOW_PRIVATE_IPS` |
| `CategorizationService` | `postprocess/categorize.go` | même SQL `~*`, premier match, `crawl_categories` projet |
| `PostProcessor::*` | `postprocess/*.go` | **réutiliser le SQL tel quel** (inlinks, pagerank tmp_pr, semantic, duplicate, redirect chains) |
| `JobManager` | `jobs/` | tables `jobs`/`job_logs`, sync `jobs.status → crawls.status` |
| `CrawlDatabase`/`DeadlockRetry` | `db/` | retry 40P01, partitions, advisory locks |
| `renderer/main.go` | inchangé | conservé (HTTP) |

---

## 5. Points durs / pièges à ne pas rater

### 5.1 L'identifiant de page (`id`)
`hash('crc32', $url, false)` en PHP retourne le CRC32 en **hexadécimal** (`CHAR(8)`).
→ En Go : `crc32.ChecksumIEEE([]byte(url))` puis `fmt.Sprintf("%08x", sum)`.
**À tester sur 1000 URLs réelles** que les hex matchent à 100 % (sinon les jointures `links.src/target ↔ pages.id`
cassent et tout le crawl est incohérent). ⚠️ Vérifier le polynôme exact utilisé par PHP `crc32` (IEEE 802.3).

### 5.2 Simhash bit-pour-bit
Si l'algo diffère (normalisation, tokenisation en shingles de 3 mots, hash 64 bits, seuil de vote),
les `duplicate_clusters` ne seront pas comparables entre anciens et nouveaux crawls. Porter `Simhash.php`
**ligne à ligne**, avec un test de non-régression sur un corpus fixe (mêmes textes → même hash 64 bits).

### 5.3 Compression HTML
`html.html` = `base64_encode(gzdeflate($dom))`. L'UI PHP relit ce format (`get_page_html`).
→ Go doit produire **exactement** `base64(flate(raw))` (zlib `flate`, pas gzip). Vérifier qu'un round-trip
Go-écrit / PHP-lu fonctionne.

### 5.4 Normalisation d'URL & `rel2abs`
`Page::rel2abs` est une implémentation maison (gère `.`/`..`, query, fragment, base href, slash canonical).
Le moindre écart change les `id` cibles → graphe différent. Porter avec une **table de tests** d'URLs
relatives → absolues issues du code PHP (capturer des cas réels). Idem `domAbs()` (réécriture des href avant parse).

### 5.5 Sémantique `in_crawl` / nofollow / canonical / sitemap
- `respect_nofollow` ON : liens nofollow (et tous les liens d'une page `meta robots nofollow`) → `in_crawl=false`,
  mais **promus** si une autre page les pointe en dofollow (logique `insertPages ON CONFLICT`).
- `respect_canonical` ON + page non-canonique : on ne suit **que** la canonical, `outlinks` recalculé.
- sitemap-only : `depth=-1`, `in_crawl=false`, `skip_link_extraction=true`.

Ces règles sont éparpillées dans `PageCrawler::storeLinks/storePageComplete` et `CrawlDatabase::insertPages`.
→ Centraliser dans `page` + `db` et couvrir par tests.

### 5.6 Stop signal & resume
- `checkStopSignal` lit `crawls.status` périodiquement → `stopping/stopped/failed` lève l'arrêt.
- `skip_stop_signal=true` pendant le post-process (sinon un stop tuerait la finalisation).
- Resume : `getCurrentDepth()` permet de reprendre un crawl non-nouveau à la bonne profondeur.
- Post-process protégé par advisory lock `crawl_id+200000` (idempotence multi-process).

### 5.7 Retry & codes
Retryable = `429, 500, 502, 503, 504` + timeout. 4 essais, backoff `2/4/8/16s` ±20 % jitter.
Codes redirect spéciaux : `301/302/303/307/308` + code interne **`311`** = redirection JS détectée par le renderer.

### 5.8 detectIsHtml
Heuristique multi-niveaux (extension d'URL, content-type, magic bytes binaires, ratio de caractères imprimables
< 0.8). À porter fidèlement (sinon des PDF/images deviennent des "pages HTML" et polluent les stats).

---

## 6. Stratégie de performance Go (où sont les vrais gains)

1. **Un seul `*http.Client`** partagé (Transport avec `MaxIdleConnsPerHost`, HTTP/2, keep-alive),
   au lieu de RollingCurl recréé par batch. Worker pool de N goroutines = `simultaneousLimit`.
2. **Un seul parse HTML par page** (`net/html`), réutilisé pour liens + extraits + headings + schemas +
   readability. Aujourd'hui PHP parse le DOM **4 fois**.
3. **Readability une seule fois** par page → en extraire `word_count` ET le texte pour simhash
   (aujourd'hui appelée 2×).
4. **Écriture DB (décision verrouillée) : `COPY` pgx → table temp → `INSERT … SELECT … ON CONFLICT`.**
   - On accumule les pages/links/html découverts dans des buffers en mémoire, puis on **flush par lot**
     (ex. toutes les 500-1000 pages, ou toutes les N ms) au lieu d'1 transaction par page (modèle PHP actuel).
   - Flush = `CopyFrom` vers une `TEMP TABLE` (ultra-rapide, pas de WAL par ligne) puis un seul
     `INSERT INTO pages SELECT … FROM tmp ON CONFLICT (crawl_id,id) DO …` qui **préserve la sémantique
     d'upsert** (promotion `in_crawl`, `DO NOTHING`, etc.). `links` n'a pas de PK → COPY direct possible.
   - **Garde-fou de parité** : implémenter d'abord l'INSERT batch `ON CONFLICT` classique (Phase 1, identique
     à PHP) comme **référence de correction**, puis basculer sur COPY et **vérifier que les dumps DB sont
     bit-identiques** avant de garder COPY. La perf est l'objectif, mais la parité est validée d'abord.
   - `updatePage` (mise à jour de la page crawlée elle-même) reste en UPDATE ciblé (1 ligne par page),
     éventuellement groupé par lot via `UPDATE … FROM (VALUES …)`.
5. **Throttling** : token bucket Go (`rate.Limiter`) au lieu du sleep par batch.
6. **Mémoire bornée** : streaming des frontières (curseur DB ou batch 5000), pas de chargement global.

---

## 7. Plan d'exécution par phases (incrémental, chaque phase testable)

> Règle : à chaque phase, le **schéma DB ne change pas** et l'app PHP continue de tourner.
> On peut faire tourner **PHP et Go en parallèle** sur des crawls différents pendant la migration.

### Phase 0 — Préparation & filet de sécurité (avant tout code Go)
- [ ] Geler le **format de config** : documenter le JSON `crawls.config` (general/advanced) comme contrat.
- [ ] Écrire des **golden tests** côté PHP : sur 3-5 sites réels, dumper `pages`/`links`/`html`/`duplicate_clusters`
      d'un crawl PHP de référence → ce sont les **oracles** de parité.
- [ ] Extraire des **fixtures HTML** réelles (pages variées : SPA, multi-h1, JSON-LD, charsets exotiques,
      redirections, canonical, nofollow) → corpus de test partagé PHP↔Go.
- [ ] Bench de référence PHP (URLs/s, RAM, durée post-process) pour comparer.

### Phase 1 — Squelette Go + accès DB (aucun comportement de crawl)
- [ ] `cmd/scouter-crawler` : connexion pgx, lecture `crawls`/`jobs`, lecture config JSONB → struct `Config`.
- [ ] Port de `config` mapping (équiv. `Cmder::crawl`) + tests : un même `crawls.config` doit produire
      la même `Config` que PHP (respect.*, httpAuth, extractors, overrides env).
- [ ] Port `db` : pool, `DeadlockRetry`, `createPartitions` (appel des fonctions PL/pgSQL existantes),
      `updateCrawlStats`, `finishCrawl`, helpers insert/update **avec sémantique ON CONFLICT identique**.
- [ ] Tests d'intégration sur une base Postgres jetable (docker) : insert/update/round-trip.

### Phase 2 — Parsing & extraction (`page`) — le cœur, testé hors-réseau
- [ ] `analysis/simhash.go` + test bit-pour-bit vs PHP sur le corpus.
- [ ] `page/parse.go` + `links.go` + `extract.go` + `schema.go` + `detect.go` + `content.go`.
- [ ] `rel2abs` / `domAbs` + table de tests d'URLs.
- [ ] **Test de parité Page** : pour chaque fixture HTML, le `Page` Go produit les mêmes champs que PHP
      (id, title, h1, meta, canonical, links[], simhash, schemas, word_count, h1_multiple, headings_missing,
      is_html, nofollow, noindex, compliant). C'est le test le plus important du chantier.

### Phase 3 — Fetcher & orchestrateur (`crawl`) — mode classic d'abord
- [ ] `analysis/safehttp.go` (anti-SSRF) + `analysis/robots.go` + tests.
- [ ] `crawl/fetcher.go` mode **classic** : client HTTP partagé, worker pool, retry backoff, stats.
- [ ] `crawl/orchestrator.go` : frontier par depth, batch 5000, stop signal, resume.
- [ ] Écriture DB par page (d'abord transaction simple, parité avant perf).
- [ ] **Test end-to-end** sur un petit site contrôlé : comparer le dump Go au golden PHP (Phase 0).
      Tolérance 0 sur le graphe (ids, links), tolérance documentée sur word_count/simhash si lib readability diffère.

### Phase 4 — Throttling + mode JavaScript (renderer)
- [ ] Token bucket (very_slow/slow/fast/unlimited) + override `MAX_CONCURRENT_CURL`.
- [ ] `crawl/renderer.go` : appel `/render-batch` (20×N), gestion `311` (JS redirect), retry.
- [ ] Test avec le renderer Go existant (docker) sur un site SPA.

### Phase 5 — Post-processing (`postprocess`)
- [ ] Porter les 7 étapes en **réexécutant le SQL existant** depuis Go (inlinks, pagerank/tmp_pr,
      semantic, categorize via YAML, duplicate, redirect chains, sitemap).
- [ ] Advisory lock `crawl_id+200000`, `statement_timeout=0`, try/catch par étape (1 plante ≠ crawl failed).
- [ ] `analysis/sitemap.go` (parser) pour l'étape sitemap.
- [ ] Test parité : `duplicate_clusters`, `redirect_chains`, `pri`, `inlinks`, `*_status`, `cat_id`
      identiques au golden.

### Phase 6 — Worker multi-crawls / boucle jobs (remplace `worker.php` pour le crawl)
- [ ] Boucle poll : `SELECT … FROM jobs WHERE status='queued' AND command='crawl' … FOR UPDATE SKIP LOCKED`.
      **Filtrer par `command='crawl'`** (le worker Go ne prend QUE le crawl ; le reste reste au worker PHP).
- [ ] **Pool de jobs interne** : jusqu'à `maxConcurrentCrawls` crawls en parallèle (goroutines), chacun avec
      son sous-pool de fetch. Sémaphores globaux (HTTP sortant, connexions renderer) partagés entre crawls.
- [ ] Claim → `running`, orphan recovery (`running→queued`, `stopping→stopped`), signaux SIGTERM (arrêt gracieux),
      backoff sur erreurs DB, heartbeat, `recover()` par crawl (une panique ≠ process mort).
- [ ] Sync `jobs.status → crawls.status` (équiv. `JobManager::updateJobStatus`), logs `job_logs`.
- [ ] **Côté PHP** : ajouter le filtre symétrique au `worker.php` pour qu'il ne prenne PLUS les jobs
      `command='crawl'` (sinon course entre worker PHP et Go). Il ne garde que delete/batch/bulk-ai.

### Phase 7 — Intégration Docker & cutover progressif
- [ ] Image Go (`Dockerfile` multi-stage, binaire statique).
- [ ] Ajouter un service `crawler-go` (1 replica, multi-crawls) ; **réduire** les `worker-1..4` PHP à
      ce qu'il leur reste (delete/batch/bulk-ai) — ils ne crawlent plus dès que le filtre `command` est en place.
- [ ] **Cutover** : dès que le filtre `command='crawl'` est actif des deux côtés, **tous** les nouveaux crawls
      partent sur Go automatiquement (pas besoin de routage manuel). Surveiller parité & perf en prod 1 semaine.
- [ ] Ajuster `maxConcurrentCrawls` + pool pgx selon charge réelle et `max_connections` Postgres.
- [ ] Optionnel : fusionner le renderer dans le binaire Go (supprimer les services `renderer-*`).

### Phase 8 — Nettoyage
- [ ] Déprécier `scouter.php crawl`, `app/Core/*`, `app/Analysis/PostProcessor`, `app/bin/worker.php`.
- [ ] Garder le PHP web/API/MCP. Mettre à jour la doc (`README`, `API.md`, `docs/`).

---

## 8. Décisions verrouillées

| Sujet | Décision | Conséquences |
|---|---|---|
| **Modèle de concurrence** | ✅ **1 seul process Go multi-crawls** (pool de jobs interne) | pool de jobs `maxConcurrentCrawls`, sémaphores globaux HTTP/Chrome, pool pgx unique, isolation panique (`recover`), arrêt gracieux. Cf. §3.1 |
| **Parité Readability** | ✅ **go-readability, dérive acceptée** | word_count/simhash légèrement différents PHP↔Go → clusters de duplicate non 100% comparables entre anciens (PHP) et nouveaux (Go) crawls. À documenter dans l'UI/notes de version |
| **Écriture DB** | ✅ **COPY → temp → INSERT…SELECT…ON CONFLICT** (perf), INSERT batch comme baseline de parité | cf. §6.4. Parité validée avant de garder COPY |
| **Périmètre v1** | ✅ **Crawl + post-process uniquement** | delete-crawl, delete-project, batch-categorize, bulk-ai-generate, scheduler **restent en PHP**. Le binaire Go ne gère que les jobs `command='crawl'` ; les autres `command` continuent d'être pris par le worker PHP (cohabitation pendant la transition) |

### Conséquence directe sur le cutover (importante)

Comme **seul le crawl** passe en Go en v1, le worker Go et le worker PHP **cohabitent** :
- Le worker **Go** ne claim que les jobs `WHERE status='queued' AND command='crawl'`.
- Le worker **PHP** continue de claim les jobs `command IN ('batch-categorize-project','delete-crawl','delete-project','bulk-ai-generate')`.
- → Filtrer le `SELECT … FOR UPDATE SKIP LOCKED` par `command` des deux côtés pour éviter qu'un worker prenne un job qu'il ne sait pas exécuter. **À faire dès la Phase 6.**

### Risques résiduels (pas des décisions, juste à surveiller)

| Risque | Mitigation |
|---|---|
| Crawls existants (PHP) doivent rester lisibles | schéma DB identique → cohabitation native, aucun re-crawl forcé |
| XPath 2.0 maison (`replace/lower-case/upper-case/matches/ends-with/tokenize`) dans les extractors | porter le mini-parseur de `Page::extract()` à l'identique + tests (peu de cas mais utilisé en prod) |
| `max_connections` Postgres saturé par le process Go multi-crawls | dimensionner le pool pgx en fonction de `maxConcurrentCrawls × writers`, et de la conf Postgres |

---

## 9. Critères de "done" (definition of done du refacto)

1. Sur le corpus de fixtures, **parité Page à 100 %** sur le graphe (ids, links) et les champs SEO.
2. Sur 3-5 crawls réels, **dumps DB Go == dumps DB PHP** (tolérances documentées simhash/word_count).
3. **Perf** : ≥ 2× URLs/s en mode classic, RAM bornée et stable (pas de fuite sur 1M pages).
4. **Robustesse** : stop/resume, retry, deadlock, OOM-safe vérifiés.
5. Worker Go tourne en prod en canary 1 semaine sans régression vs PHP.
6. App PHP (UI/API/MCP/dashboard/SQL explorer) **inchangée et fonctionnelle**.

---

## 10. Annexe — Fichiers PHP de référence (sources de vérité à porter)

```
app/Cli/Cmder.php                 → mapping config (crawl())
app/Core/Crawler.php              → orchestration depth, post-process, finish
app/Core/DepthCrawler.php         → fetch parallèle, 3 modes, retry, throttle, vitesses
app/Core/PageCrawler.php          → page → DB (store* + sémantique nofollow/canonical/sitemap)
app/Core/Page.php                 → parsing/extraction (LE cœur — 1216 lignes)
app/Analysis/PostProcessor.php    → 7 étapes SQL post-crawl
app/Analysis/CategorizationService.php → catégorisation YAML→SQL
app/Analysis/Simhash.php          → simhash 64 bits (à porter bit-pour-bit)
app/Analysis/RobotsTxt.php        → robots fetch/cache/match
app/Sitemap/SitemapParser.php     → sitemap (limites)
app/Util/HtmlParser.php           → extractLinksWithPosition, xpath helpers
app/Util/JsRenderer.php           → client renderer
app/Util/SafeHttp.php             → anti-SSRF
app/Database/CrawlDatabase.php    → insert/update/stats/partitions
app/Database/DeadlockRetry.php    → retry deadlock
app/Job/JobManager.php            → jobs/job_logs, sync crawls
app/bin/worker.php                → boucle poll jobs (à remplacer)
renderer/main.go                  → renderer JS (conservé)
docker/postgres/init.sql + migrations/ → schéma (inchangé)
docker-compose.yml                → services (ajouter worker-go)
```
</content>
</invoke>
