# Plan d'action — Fonctionnalité Sitemap

> **Statut** : v2 — 13 points clarifiés par l'utilisateur. Plan en attente d'approbation finale avant codage.
> **Ne rien coder tant que le plan n'est pas approuvé.**

## 0. Objectif

Permettre, au lancement d'un crawl, de fournir une URL de sitemap (`.xml`, `.txt`, sitemap-index). Une fois le crawl classique terminé :

1. Parser le sitemap (récursif si c'est un index).
2. Marquer les pages déjà crawlées qui sont dans le sitemap (`in_sitemap = true`).
3. Insérer les pages présentes dans le sitemap mais absentes du crawl (`depth = -1`, `in_crawl = false`, `in_sitemap = true`), puis les fetcher individuellement pour remplir leurs colonnes.
4. Ajouter une vue "Sitemap" dans la section Accessibilité du rapport, avec deux graphiques et deux tableaux.
5. Filtrer toutes les autres vues sur `in_crawl = true` pour ne pas polluer les statistiques.

---

## 1. Règles figées (validées)

| # | Sujet | Règle |
|---|-------|-------|
| 1 | Liens sortants des pages sitemap-only | **Non extraits**. Sitemap-only pages ne polluent pas inlinks/outlinks/PageRank — leur `pri` restera à 0 |
| 2 | URLs hors `allowed_domains` dans le sitemap | **Incluses** dans `pages` avec `external = TRUE`, **non fetchées** (comportement identique aux liens externes d'un crawl classique). Permet quand même de les voir dans la vue Sitemap |
| 3 | Sitemaps `.gz` | Supportés (détection via `Content-Encoding` ou suffixe `.gz`) |
| 4 | Limites anti-bombe | Profondeur d'index max **2**, **50** sitemaps enfants max, **50 000** URLs max au total |
| 5 | Échec fetch sitemap (404/XML cassé/timeout) | Log `warning` "Sitemap not fetchable", post-process `skipped`, crawl reste `finished` |
| 6 | `depth` des URLs sitemap | **Sitemap-only** → `depth = -1` (marqueur permanent). **Déjà dans le crawl** → on ne touche pas au `depth` existant |
| 7 | Placement input modale | Onglet **Scope** (`Règles & scope`), sous `allowed_domains` |
| 8 | Plusieurs sitemaps | **Textarea** (une URL par ligne), même UX que `allowed_domains` |
| 9 | 2e graphique | Calque du chart "Indexabilité des URLs crawlées" de la vue Accessibility (catégories : `indexable`, `non canonical`, `noindex`, `code != 200`), filtré sur `in_sitemap = true` |
| 10 | List-mode crawl | URLs du list-mode → `in_crawl = true` (assimilées au crawl) |
| 11 | URL dans crawl ET sitemap | `in_crawl = true`, `in_sitemap = true` |
| 12 | Vues comparison | Filtrées `in_crawl = true` aussi. Pas de diff sitemap entre crawls (hors scope) |
| 13 | Backfill | Pas nécessaire — `DEFAULT TRUE` sur `in_crawl`, `DEFAULT FALSE` sur `in_sitemap` suffisent pour les anciens rows |

---

## 2. Schéma DB & migration

### 2.1 Migration `migrations/2026-05-16-12-00-sitemap-columns.php`

Pattern à respecter (cf. `migrations/2026-03-29-00-00-async-deletion.php` et `migrations/2026-03-28-15-00-crawl-schedules.php`) :

- Check existence via `information_schema.columns` avant `ALTER TABLE`
- Echo `→` pour chaque étape, `✓` succès, `✗` erreur
- Return `true` / `false`
- Try/catch autour

**Changements** :

```sql
-- 1. Colonnes sur pages (parent table, propagation auto vers partitions)
ALTER TABLE pages ADD COLUMN in_crawl BOOLEAN DEFAULT TRUE;
ALTER TABLE pages ADD COLUMN in_sitemap BOOLEAN DEFAULT FALSE;

-- 2. Index pour les requêtes de la nouvelle vue
CREATE INDEX idx_pages_in_sitemap ON pages (crawl_id, in_sitemap) WHERE in_sitemap = TRUE;
CREATE INDEX idx_pages_in_crawl_false ON pages (crawl_id, in_crawl) WHERE in_crawl = FALSE;
```

> Avec PostgreSQL, ajouter une colonne avec `DEFAULT` constante est instantané depuis la v11 (pas de rewrite de table). OK pour la prod.

### 2.2 Mettre à jour `docker/postgres/init.sql`

Ajouter `in_crawl BOOLEAN DEFAULT TRUE` et `in_sitemap BOOLEAN DEFAULT FALSE` dans la déclaration de `CREATE TABLE pages` (autour de la ligne 184 de l'init.sql actuel) — pour que les nouvelles installations partent avec les colonnes.

### 2.3 Mettre à jour la config du crawl

Ajouter une clé dans le JSON `crawls.config` :

```json
{
  "scope": { "sitemap_urls": ["https://example.com/sitemap.xml", "https://example.com/sitemap-news.xml"] }
}
```

Tableau (une entrée par ligne du textarea). Pas de nouvelle colonne — le JSONB suffit (cohérent avec `store_html`, `follow_redirects`, etc.).

---

## 3. UI — modale de création de crawl

**Fichier** : `web/components/crawl-modal.php`

Ajouter un **textarea multi-lignes** (UX identique à `allowed_domains`) dans l'onglet **Scope** (`Règles & scope`), juste après `allowed_domains` (lignes 137 et suivantes). **Une URL de sitemap par ligne — l'utilisateur peut en saisir plusieurs.**

```php
<div class="form-group">
    <label for="sitemap_urls"><?= __('crawl_modal.sitemap_urls') ?></label>
    <textarea id="sitemap_urls" name="sitemap_urls" rows="3"
              placeholder="https://example.com/sitemap.xml&#10;https://example.com/sitemap-news.xml&#10;https://example.com/sitemap-products.xml"></textarea>
    <small class="form-hint"><?= __('crawl_modal.sitemap_hint') ?></small>
</div>
```

- L'input accepte **0, 1 ou N sitemaps** (sitemaps simples, sitemap-index, mix des deux — tous traités par le parser)
- Vide → l'étape de post-process est skippée silencieusement
- Une URL par ligne, parsing en tableau côté JS

**JS** (`web/assets/crawl-panel.js`) — sérialiser le textarea en tableau (split par retour ligne, trim, drop des lignes vides) et envoyer dans le payload sous `scope.sitemap_urls` (toujours un tableau, même s'il n'y a qu'une URL).

**API** (`web/api/index.php` + `CrawlController` — à confirmer) — stocker tel quel dans `config.scope.sitemap_urls` (tableau de strings, jamais une string seule).

**`app/Cli/Cmder.php` méthode `launch`** — lire `$config['sitemap_urls'] = $data['scope']['sitemap_urls'] ?? [];` au même endroit que les autres clés (autour de la ligne 115-120, cf. `store_html`). Si une string arrive par erreur, normaliser en `[$value]` pour robustesse.

---

## 4. Parser sitemap

**Nouveau fichier** : `app/Sitemap/SitemapParser.php`

Responsabilités :

- `parse(array $urls): SitemapResult` → accepte la liste de sitemaps fournie par l'utilisateur, retourne la liste plate d'URLs (dédupliquée)
- Supporte `.xml` (sitemap simple + sitemap-index récursif), `.txt`, `.xml.gz`
- Respecte les limites de la règle #4 (profondeur 2, 50 enfants max, 50 000 URLs max)
- Renvoie un `SitemapResult` objet : `urls`, `sitemaps_visited`, `errors` (liste des sitemaps non fetchables)
- **Ne filtre pas** sur `allowed_domains` — c'est le post-process qui le fait au moment de l'insertion (pour marquer `external = true`)
- Utilise `cURL` via le wrapper HTTP existant (à identifier — probablement `app/Core/PageCrawler` ou un helper)
- Timeout 30s par sitemap

**Test** : `tests/Unit/SitemapParserTest.php`
- Sitemap XML simple (fixture)
- Sitemap-index → 2 enfants → 10 URLs
- Sitemap TXT
- Sitemap gzippé
- Sitemap malformé → exception capturée, retourne erreur
- Limite de profondeur respectée

---

## 5. Post-process orchestration

**Fichier** : `app/Analysis/PostProcessor.php`

### 5.1 Ajouter une 7e étape

Le tableau `$steps` (lignes 60-67) doit accueillir `'sitemap'` **en dernier**, après `redirectChainAnalysis`. La méthode `sitemapAnalysis()` est ajoutée dans la classe.

### 5.2 Méthode `sitemapAnalysis()`

Pseudocode :

```php
public function sitemapAnalysis(): void
{
    $sitemapUrls = $this->getCrawlConfig('sitemap_urls'); // tableau, peut être vide

    if (empty($sitemapUrls)) {
        // Pas de sitemap configuré → on n'affiche rien (l'étape n'apparaît pas dans le log)
        return;
    }

    echo "\r \033[32m Sitemap analysis \033[0m : \033[36mfetching...\033[0m                    ";
    flush();

    try {
        $parser = new \App\Sitemap\SitemapParser();
        $result = $parser->parse($sitemapUrls); // accepte la liste
    } catch (\Throwable $e) {
        echo "\r \033[32m Sitemap analysis \033[0m : \033[33mskipped (" . $e->getMessage() . ")\033[0m                    \n";
        return;
    }

    if (!empty($result->errors)) {
        // Log un warning par sitemap non fetchable, mais continue
        foreach ($result->errors as $err) {
            $this->jobManager->addLog($this->jobId, "Sitemap not fetchable: $err", 'warning');
        }
    }

    $allUrls = $result->urls;
    echo "\r \033[32m Sitemap analysis \033[0m : \033[36m" . count($allUrls) . " URLs found, matching...\033[0m                    ";
    flush();

    // Séparer les URLs : déjà en base / nouvelles in-scope / nouvelles out-of-scope
    $known      = $this->existingUrls($allUrls);      // url => true
    $newUrls    = array_diff($allUrls, array_keys($known));
    $allowed    = $this->getAllowedDomains();         // tableau de domaines autorisés
    [$newInScope, $newOutOfScope] = $this->splitByScope($newUrls, $allowed);

    // 1. UPDATE in_sitemap = TRUE sur les URLs déjà connues — sans toucher au depth
    $matched = $this->markSitemapMatches(array_keys($known));

    // 2. INSERT des nouvelles in-scope : depth=-1, in_crawl=false, in_sitemap=true, external=false
    $inserted = $this->insertSitemapOnly($newInScope, external: false);

    // 3. INSERT des nouvelles out-of-scope : depth=-1, in_crawl=false, in_sitemap=true, external=true
    $insertedExt = $this->insertSitemapOnly($newOutOfScope, external: true);

    // 4. Fetcher UNIQUEMENT les in-scope (les externals ne sont pas fetchés, comme dans un crawl classique)
    echo "\r \033[32m Sitemap analysis \033[0m : \033[36mfetching " . count($newInScope) . " new URLs...\033[0m                    ";
    flush();
    $this->fetchSitemapOnlyPages($newInScope);

    echo "\r \033[32m Sitemap analysis \033[0m : \033[36m{$matched} matched, {$inserted} new, {$insertedExt} external\033[0m                    \n";
}
```

**Notes** :

- Les URLs **déjà présentes** dans `pages` (in_crawl=true) gardent leur `depth` existant — on ne fait que `UPDATE pages SET in_sitemap = TRUE`.
- Les URLs déjà présentes comme `external = true` (découvertes en lien sortant du crawl classique) reçoivent juste `in_sitemap = TRUE`, pas de re-fetch.
- Le `external=true` issu du sitemap reproduit le comportement du crawl classique sur les liens hors scope : on note leur existence sans les fetcher.

### 5.3 Fetch des pages sitemap-only — multi-thread, mêmes règles de speed

**Règle clé** : on **ne fetch pas les URLs une par une**. On réutilise le moteur de crawl actuel pour bénéficier du parallélisme et du throttling déjà configurés sur ce crawl.

**Implémentation** : réutiliser `app/Core/DepthCrawler.php` (qui contient déjà la logique `curl_multi` + concurrence + throttle, lignes 142-202 et 277+ pour le retry batch) avec :

- `simultaneousLimit` et `targetUrlsPerSecond` issus du `crawl_speed` du crawl en cours (`very_slow` → 2/1, `slow` → 3/5, `fast` → 8/15, `unlimited` → 10/∞) — exactement le même calcul qu'aujourd'hui (lignes 142-162)
- Respect de `MAX_CONCURRENT_CURL` env override (lignes 167-174)
- Respect de `user_agent`, `custom_headers`, `auth_username`/`password`, `respect_robots` (déjà gérés par le crawler)
- Réutilisation du timeout, des retries, du backoff exponentiel

**Différences vs. crawl classique** — un seul flag à passer au moment du fetch des URLs sitemap-only :

| Comportement | Crawl classique | Fetch sitemap-only |
|---|---|---|
| Parsing du HTML (Page::parse) | ✔ | ✔ |
| Title / H1 / Code / Canonical / Noindex / Schemas | ✔ | ✔ |
| Word count / Simhash | ✔ | ✔ |
| Insertion dans `links_<crawl_id>` | ✔ | ✗ |
| MAJ `inlinks`/`outlinks` des autres pages | ✔ | ✗ |
| Enqueue des URLs sortantes pour la suite | ✔ | ✗ |
| Concurrence (curl_multi) | ✔ | ✔ (même limites) |
| Throttling (URLs/sec) | ✔ | ✔ (même limites) |

**Refacto à prévoir** : ajouter un mode "fetch sans extraction de liens" à `DepthCrawler` — soit via un flag `$skipLinkExtraction` au constructeur, soit en factorisant la phase fetch+parse dans une méthode publique réutilisable. À évaluer au moment du codage selon ce qui est le moins invasif (probablement un flag + un `if` dans la méthode qui appelle `Page::extractLinks()` / `Page::updateInlinks()`).

**Log pendant le fetch** : la ligne unique de l'étape sitemap (cf. 5.4) se met à jour dynamiquement avec le compteur — par ex. `\r ... fetching 142/350 URLs...\033[0m`. **Pas de nouvelle ligne par URL fetchée**, pas de logs verbeux.

### 5.4 Format des logs — non-négociable

Toutes les sorties de `sitemapAnalysis()` doivent suivre **exactement** le format des autres post-process (cf. `PostProcessor.php` lignes 122-147 pour Inlinks, 158-264 pour Pagerank, 371-544 pour Duplicate analysis) :

- `\r` en début de chaîne (carriage return)
- Nom d'étape entouré de `\033[32m ... \033[0m` (vert)
- Statut en `\033[36m ... \033[0m` (cyan) pour le progress, `\033[33m` (jaune) pour les états no-op/skipped
- 20+ espaces de padding pour effacer le contenu précédent
- `flush()` après chaque echo
- `\n` **uniquement** sur le message final de l'étape

→ Un test visuel en local est obligatoire avant merge.

---

## 6. Vue "Sitemap" dans le rapport

### 6.1 Enregistrement de la vue

3 endroits à modifier :

1. **`web/components/sidebar-navigation.php`** ligne ~113 — ajouter le lien immédiatement après `redirect-chains`, à l'intérieur du groupe Accessibility :

```php
<a href="?crawl=<?= $crawlId ?>&page=sitemap"
   class="sidebar-panel-item <?= $page === 'sitemap' ? 'active' : '' ?>">
    <span class="material-symbols-outlined">map</span>
    <span><?= __('sidebar.sitemap') ?></span>
</a>
```

2. **`web/dashboard.php`** ligne ~405 (switch `$page`) — ajouter :

```php
case 'sitemap':
    include 'pages/sitemap.php';
    break;
```

3. **`web/dashboard.php`** ligne ~355 (tableau `$reportPages` — à confirmer) — ajouter `'sitemap'`.

### 6.2 Contenu de la vue `web/pages/sitemap.php`

Structure inspirée de `web/pages/redirect-chains.php` :

1. **Guard** : si le crawl n'a pas de `sitemap_url` configurée → message "Aucun sitemap configuré pour ce crawl"
2. **Scorecards (en haut)** : total URLs crawl, total URLs sitemap, intersection, sitemap-only, crawl-only indexables
3. **Graphique 1 — Distribution** (donut ou bar) : "Crawl only" / "Crawl + Sitemap" / "Sitemap only"
   - Crawl only : `in_crawl = TRUE AND in_sitemap = FALSE`
   - Both       : `in_crawl = TRUE AND in_sitemap = TRUE`
   - Sitemap only : `in_crawl = FALSE AND in_sitemap = TRUE`
4. **Graphique 2 — Indexabilité des URLs du sitemap** (donut, calque du chart `accessibility.chart_indexability` lignes 188-201) — mêmes 4 catégories et mêmes couleurs :
   - `indexable` : `compliant = TRUE` — vert `#6bd899ff`
   - `non_canonical` : `code = 200 AND noindex = false AND canonical = false` — jaune `#cfd86bff`
   - `noindex` : `code = 200 AND noindex = true` — orange `#d8bf6bff`
   - `http_not_200` : `code != 200` — rouge `#d86b6bff`
   - Toutes les requêtes filtrées avec `WHERE crawl_id = :id AND in_sitemap = TRUE`
5. **Tableau 1** : URLs crawl indexables **absentes** du sitemap (`WHERE in_crawl = TRUE AND compliant = TRUE AND in_sitemap = FALSE`)
6. **Tableau 2** : URLs sitemap **non indexables** (`WHERE in_sitemap = TRUE AND compliant = FALSE`)

### 6.3 Charts

Utilise le composant `web/components/chart.php` (Highcharts, déjà câblé). Type `bar` pour le 1er, `donut` pour le 2nd. Cf. patterns dans `web/pages/accessibility.php`.

### 6.4 Tableaux

Utilise `web/components/url-table.php` avec `$urlTableConfig`. 2 IDs différents (`sitemap_missing_table`, `sitemap_non_indexable_table`). Colonnes par défaut : `url`, `code`, `compliant`, `noindex`, `canonical`, `depth`.

---

## 7. Filtrage `in_crawl = true` sur toutes les autres vues

**Surface estimée : ~30 fichiers, ~50 requêtes SQL.**

### 7.1 Stratégie

Ajouter `AND in_crawl = TRUE` (ou `WHERE in_crawl = TRUE` selon contexte) à **toutes** les requêtes qui agrègent ou listent des `pages`, **sauf** dans la vue Sitemap elle-même.

### 7.2 Fichiers à passer en revue

**Pages report (simples)** :
- `web/pages/home.php`
- `web/pages/accessibility.php`
- `web/pages/codes.php`
- `web/pages/response-time.php`
- `web/pages/depth.php`
- `web/pages/redirect-chains.php`
- `web/pages/seo-tags.php`
- `web/pages/headings.php`
- `web/pages/content-richness.php`
- `web/pages/duplication.php`
- `web/pages/extractions.php`
- `web/pages/structured-data.php`
- `web/pages/inlinks.php`
- `web/pages/outlinks.php`
- `web/pages/pagerank.php`
- `web/pages/pagerank-leak.php`

**Pages comparison** (~12 fichiers `*-comparison.php`, `comparison-overview.php`, `new-urls.php`, `lost-urls.php`)

**Composants** :
- `web/components/url-table.php` — clause WHERE par défaut (ligne ~71)
- `web/components/link-table.php`
- `web/components/redirect-table.php` (si concerné)

**Post-processing** :
- `app/Analysis/PostProcessor.php` — vérifier que `calculateInlinks`, `calculatePagerank`, `semanticAnalysis`, `categorize`, `duplicateAnalysis`, `redirectChainAnalysis` **n'incluent pas** les pages sitemap-only dans leurs calculs (sinon le PageRank, inlinks, etc. seraient faux).

**Dashboard / stats globales** :
- `web/dashboard.php` lignes ~272-296 (scorecards comparison)
- `app/Database/CrawlDatabase.php` méthode `updateCrawlStats()` — exclure les pages `in_crawl = false` des totaux affichés

### 7.3 Méthode

Faire un grep systématique :

```bash
grep -rn "FROM pages" app/ web/ --include="*.php"
```

Pour chaque match, décider :
- Soit la requête est dans la vue Sitemap (pas de filtre)
- Soit elle nécessite `AND in_crawl = TRUE`

### 7.4 Risque

C'est la partie la plus susceptible de régression. Je ferai un **commit séparé** pour cette passe de filtrage, après que la vue Sitemap soit fonctionnelle, afin de pouvoir bisecter en cas de problème.

---

## 8. i18n

Clés à ajouter dans les **6** fichiers `web/lang/{en,fr,es,de,it,pt}.json` :

```
crawl_modal.sitemap_urls
crawl_modal.sitemap_hint
sidebar.sitemap
sitemap.page_title
sitemap.no_sitemap_configured
sitemap.scorecard_total_crawl
sitemap.scorecard_total_sitemap
sitemap.scorecard_intersection
sitemap.scorecard_sitemap_only
sitemap.scorecard_crawl_only_indexable
sitemap.chart_distribution_title
sitemap.chart_distribution_desc
sitemap.chart_distribution_crawl_only
sitemap.chart_distribution_both
sitemap.chart_distribution_sitemap_only
sitemap.chart_indexability_title
sitemap.chart_indexability_desc
sitemap.table_missing_from_sitemap_title
sitemap.table_non_indexable_in_sitemap_title
```

**À noter** : les 4 séries du graphique 2 (indexabilité du sitemap) réutilisent les clés existantes `accessibility.series_indexable`, `accessibility.series_non_canonical`, `accessibility.series_noindex`, `accessibility.series_http_not_200` — pas de duplication nécessaire.

**Méthode** : je commence par `en.json` (référence) et `fr.json` (traductions soignées), puis je propage les 4 autres langues en réutilisant le ton des clés existantes. Si une formulation ne te semble pas naturelle, dis-le, je corrige.

---

## 9. Tests unitaires

### 9.1 Nouveaux tests `tests/Unit/`

- **`SitemapParserTest.php`** — parsing XML simple, sitemap-index récursif, TXT, .gz, malformé, limites
- **`SitemapPostProcessTest.php`** — logique de match/insert, sans DB réelle (mock ou DB de test si déjà en place)
- **`SitemapLogFormatTest.php`** — assertion sur la chaîne ANSI produite (regex sur `\r\033\[32m Sitemap analysis...`) — garantit qu'on ne casse pas le format

### 9.2 Fixtures

`tests/fixtures/sitemaps/` :
- `simple.xml`
- `index.xml` + `child1.xml` + `child2.xml`
- `urls.txt`
- `simple.xml.gz`
- `malformed.xml`

### 9.3 Pattern à respecter

`describe` + `it` (Pest), `expect(...)->toBe(...)`. Cf. `tests/Unit/CrawlConfigTest.php`.

---

## 10. Plan de découpe en commits

Pour pouvoir bisecter facilement :

1. `feat(db): add in_crawl / in_sitemap columns + migration`
2. `feat(sitemap): SitemapParser with xml/txt/index/gz support + tests`
3. `feat(crawl): expose sitemap_url input in modal + persist in config`
4. `feat(post-process): sitemapAnalysis step with matching, insertion, fetching`
5. `feat(report): new Sitemap view (scorecards + 2 charts + 2 tables)`
6. `chore(queries): apply in_crawl=true filter to all existing report views and components`
7. `chore(i18n): add sitemap translation keys to all 6 languages`
8. `test: sitemap parser and post-process unit tests`

---

## 11. Plan de validation (avant merge)

- [ ] Migration tourne sans erreur sur DB existante (test sur un dump)
- [ ] Le crawl 531 (golighter.de) tourne avec un sitemap configuré
- [ ] Vérification visuelle des logs : chaque post-process tient sur une ligne, mise à jour dynamique sans duplication, couleurs identiques
- [ ] La vue Sitemap s'affiche correctement
- [ ] Les autres vues (accessibility, codes, depth, etc.) ne comptent pas les pages sitemap-only
- [ ] Comparaison entre 2 crawls : pas de régression
- [ ] Toutes les langues affichent les nouvelles clés (test rapide en switchant `?lang=fr/de/es/it/pt/en`)
- [ ] Suite de tests unitaires verte : `./vendor/bin/pest`

---

## 12. Points hors scope (à confirmer)

- Diff sitemap entre 2 crawls (vue Comparison) — pas dans cette itération
- Sitemaps multilingues / hreflang
- Validation des sitemaps (XSD, taille max recommandée)
- Détection automatique du sitemap via `robots.txt` (extension naturelle plus tard)
- Ré-exécution du post-process sitemap seul (re-running) — pour l'instant, lié au cycle de crawl complet
