# crawler-go — moteur de crawl Scouter en Go

Réécriture Go du crawler + post-processing PHP (cf. `../refacto.md`). Un binaire unique,
**multi-crawls**, qui remplace le chemin `command='crawl'` de `app/bin/worker.php` et
`scouter.php crawl`. Il lit/écrit **exactement** le même schéma PostgreSQL : aucune
migration, aucun changement de l'app web/API/MCP PHP. Le renderer JS Go existant
(`../renderer`) est réutilisé via HTTP.

## Architecture

```
cmd/scouter-crawler/main.go   worker multi-crawls : poll jobs (command='crawl'),
                              pool de N crawls concurrents, recover, SIGTERM, recover()
internal/
  config/    crawls.config JSONB → Config (mapping fidèle à Cmder::crawl)
  model/     Page / Link partagés
  analysis/  crc (PageID = CRC-32/BZIP2), simhash, robots, safehttp (SSRF), sitemap
  page/      parse (1 seul parse DOM) : liens+position, extracts, schemas, headings,
             word_count + simhash (go-readability), rel2abs, detectIsHtml
  db/        pgx pool + retry deadlock, partitions, insert/update, ON CONFLICT, stats
  crawl/     fetcher (classic/throttle/JS), store (PageCrawler), orchestrateur depth
  postprocess/ inlinks, pagerank, semantic, categorize, duplicate, redirect, sitemap
  jobs/      claim FOR UPDATE SKIP LOCKED (command='crawl'), sync jobs↔crawls
```

## Parité (tests)

Les points les plus risqués sont validés **bit-pour-bit** contre PHP 8.3 :

- `internal/analysis` : `PageID` (= `hash('crc32', url)` = CRC-32/BZIP2, hex little-endian)
  et `Simhash::compute` (64 bits) — `go test ./internal/analysis/`.
- `internal/page` : `rel2abs` (port littéral de `Page::rel2abs`) — `go test ./internal/page/`.

```bash
go test ./...
```

> Dérive documentée et acceptée (décision verrouillée) : `word_count` / `simhash`
> reposent sur `go-readability` ≠ `fivefilters/readability.php`, donc les clusters de
> duplicates ne sont pas 100 % comparables entre anciens crawls PHP et nouveaux crawls Go.

## Cohabitation PHP ↔ Go (cutover)

- Worker **Go** : claim `WHERE status='queued' AND command='crawl'`.
- Worker **PHP** : avec `DELEGATE_CRAWL_TO_GO=1`, ajoute `AND command <> 'crawl'` — il ne
  garde que `batch-categorize` / `delete` / `bulk-ai` / `resume`.
- → Aucun routage manuel : dès que le flag est actif des deux côtés, tous les nouveaux
  crawls partent sur Go. Rollback : `DELEGATE_CRAWL_TO_GO=0` (et stopper `crawler-go`).

## Variables d'environnement

| Var | Rôle | Défaut |
|-----|------|--------|
| `DATABASE_URL` | DSN PostgreSQL | (requis) |
| `RENDERER_URLS` (ou `RENDERER_URL`) | renderers JS, séparés par `,` | — |
| `MAX_CONCURRENT_CRAWLS` | crawls simultanés dans le process | 4 |
| `MAX_CONCURRENT_CURL` | concurrence HTTP par crawl (override vitesse) | selon `crawl_speed` |
| `POOL_CONNS_PER_CRAWL` | dimensionnement du pool pgx | 6 |
| `SCOUTER_ALLOW_PRIVATE_IPS` | bypass anti-SSRF (crawl interne/dev) | — |

## Build / run

```bash
# local
go build ./... && go test ./...

# docker (service ajouté à ../docker-compose.yml)
docker compose up -d --build crawler-go
```

## Limites connues / TODO

- Écriture DB en **INSERT batch ON CONFLICT** (baseline de parité). L'optimisation
  `COPY → temp → INSERT…SELECT…ON CONFLICT` (cf. refacto §6) reste à brancher après
  validation de parité sur de vrais crawls.
- `getCurrentDepth` (resume) géré ; la reprise fine d'un crawl interrompu mérite un test
  d'intégration dédié.
- Parité Page complète (title/h1/links/schemas) couverte par un smoke test ; un golden
  test DB contre un crawl PHP réel (refacto Phase 0) reste à mettre en place.
