# Bulk AI Generator — proposition

> Génération en masse de **n'importe quel champ textuel** (résumé, mot-clé,
> tag, score, classification, title alternatif, …) sur une liste d'URLs,
> via OpenRouter, à partir d'un prompt configurable. **Plusieurs items
> peuvent être générés en un seul appel IA** (ex: `title_proposal` +
> `h1_proposal` + `metadesc_proposal` ensemble — ~3× moins cher que 3
> jobs séparés). Le résultat est stocké dans une **colonne `generation`
> JSONB sur `pages`** (même pattern que les `extracts` actuels), exposée
> comme colonnes optionnelles dans URL Explorer / Link Explorer, exportable
> en CSV.

---

## 1. Vision en une phrase

> *"Sélectionne 200 URLs depuis l'URL Explorer, définis les items à
> générer (un ou plusieurs, en un seul appel IA), dis quel contenu
> envoyer comme contexte, lance, valide, et retrouve les colonnes
> ajoutées dans tous les explorateurs comme n'importe quel autre champ."*

Un seul job peut produire plusieurs items en une passe :
- Job A : génère `title_proposal` + `h1_proposal` + `metadesc_proposal`
  ensemble (1 appel IA → 3 valeurs par URL)
- Job B : génère `summary_short` + `target_keyword` + `tone_of_voice`
  ensemble
- Job C : juste `is_thin_content` (booléen)

Tous ces items cohabitent dans `pages.generation` :
```json
{
  "title_proposal": "Pull col rond Acme — Coton Bio | Marque",
  "h1_proposal": "Pull col rond en coton biologique",
  "metadesc_proposal": "Découvrez notre pull col rond en coton...",
  "summary_short": "Pull col rond Acme en coton biologique...",
  "target_keyword": "pull coton bio",
  "tone_of_voice": "casual"
}
```
Et chacun devient une colonne `generation_xxx` dans URL/Link Explorer.

---

## 2. Where it lives — point d'entrée UI

### Bouton bulk depuis URL Explorer

L'URL Explorer est l'endroit naturel pour sélectionner un sous-ensemble
d'URLs (par filtres : catégorie, code, regex sur title…). On greffe un
bouton **"✨ Generate with AI"** dans la barre d'actions bulk, à côté des
actions existantes.

```
URL Explorer
─────────────────────────────────────────────────────────────────
[ Filter: cat = product, code = 200, word_count > 200 ]
                                            487 URLs sélectionnées
─────────────────────────────────────────────────────────────────
[Export CSV]  [📋 Copy URLs]  [✨ Generate with AI]   ← nouveau
─────────────────────────────────────────────────────────────────
URL                      Title              Code  H1
/product/abc            Cool Product        200   Cool Product
/product/xyz            Other Product       200   Other Thing
...
```

Cliquer ouvre une **modal wizard en 3 étapes** plutôt qu'une page dédiée :
on garde le contexte des filtres + la sélection.

### Widget permanent "AI Jobs"

Petit indicateur (bottom-right ou dans le top-header) qui liste les jobs
de génération en cours :

```
┌─ ✨ AI Jobs ──────────────────────────────────┐
│ ● summary_short    — 127/487 — ~$0.02       │  ← cliquable, voir détail
│ ✓ target_keyword   — done (3min) — $0.04    │
└──────────────────────────────────────────────┘
```

→ permet de quitter la modale, naviguer ailleurs, et garder le suivi.

---

## 3. Le workflow en 3 étapes

### Étape 1 — Configure (modal step 1)

```
╔══════════════════════════════════════════════════════════════════╗
║  ✨ Generate with AI                                    step 1/3 ║
╠══════════════════════════════════════════════════════════════════╣
║                                                                  ║
║  Items à générer    (1 ou plusieurs en un seul appel IA)         ║
║  ┌──────────────────────────────────────────────────────────┐    ║
║  │ Item                  Type         Note                   │    ║
║  │ ╳ title_proposal      [Text    ▾]  Max 60 chars           │    ║
║  │ ╳ h1_proposal         [Text    ▾]  Max 80 chars           │    ║
║  │ ╳ metadesc_proposal   [Text    ▾]  140-160 chars          │    ║
║  │ ╳ score_quality       [Number  ▾]  Entier 0-100           │    ║
║  │ ╳ is_thin_content     [Boolean ▾]  true si < 300 mots     │    ║
║  │ [+ Ajouter un item]                                       │    ║
║  └──────────────────────────────────────────────────────────┘    ║
║  ⓘ Chaque item devient une clé dans `pages.generation` et         ║
║    une colonne dans URL/Link Explorer (`generation_xxx`).         ║
║  ⓘ Le **type** détermine les opérateurs disponibles dans          ║
║    URL Explorer : Number → >, <, between ; Text → contains, =     ║
║    ; Boolean → = true/false. La contrainte est aussi envoyée      ║
║    à l'IA via le system prompt pour qu'elle respecte le format.   ║
║  ⓘ Générer 3 items en 1 batch ≈ 1/3 du coût de 3 jobs séparés.   ║
║  ⚠ Les keys existantes seront écrasées pour les URLs              ║
║    sélectionnées (les autres URLs gardent leur valeur).          ║
║                                                                  ║
║  Contexte envoyé à l'IA pour chaque URL                          ║
║  ☑ URL (toujours inclus)                                         ║
║  ☑ Title              (~15 tokens)                               ║
║  ☑ H1                 (~10 tokens)                               ║
║  ☐ Meta description   (~25 tokens)                               ║
║  ☐ Contenu visible    ⚠ +500-2000 tokens / URL — coûteux         ║
║  ☐ Custom extract:    [select ▾]                                 ║
║  ☐ Catégorie                                                     ║
║                                                                  ║
║  Prompt template                                                 ║
║  ┌──────────────────────────────────────────────────────────┐    ║
║  │ Pour cette page e-commerce, génère les 3 items demandés :│    ║
║  │ - title_proposal     : title SEO optimisé, max 60 chars  │    ║
║  │ - h1_proposal        : titre principal, max 80 chars     │    ║
║  │ - metadesc_proposal  : meta description, 140-160 chars   │    ║
║  │                                                           │    ║
║  │ Reste factuel, mentionne le type de produit.             │    ║
║  │                                                           │    ║
║  │ URL : {url}                                              │    ║
║  │ Title actuel : {title}                                   │    ║
║  │ H1 actuel : {h1}                                         │    ║
║  └──────────────────────────────────────────────────────────┘    ║
║  Variables disponibles : {url} {title} {h1} {metadesc}           ║
║                          {visible_content} {category} {extract.X}║
║                                                                  ║
║  Model                                                           ║
║  [ Modèle léger (gpt-4o-mini)  ▾ ]    ⓘ Recommandé              ║
║                                                                  ║
║  Batch size (auto-calculé selon contexte × nb items)             ║
║  8 URLs par appel API  [auto]   [Manuel : __ URLs/call]          ║
║                                                                  ║
║                                          [Annuler]  [Suivant →]  ║
╚══════════════════════════════════════════════════════════════════╝
```

**Notes clés :**

- **Items à générer** = la liste des clés JSONB que ce job va créer/écraser
  dans `pages.generation`. Chaque item a :
    - un **nom** (validation : `[a-z][a-z0-9_]{0,49}`, unique dans le job)
    - un **type** parmi : `text`, `number`, `boolean` (+ peut-être `url`
      en V2 pour valider qu'on a bien une URL bien formée)
    - une **note optionnelle** (max length attendue, format, etc.) — utile
      pour rappeler à l'utilisateur ce qu'il a demandé
  Au minimum 1 item, pas de max strict (mais 5-6 c'est raisonnable pour
  pas exploser l'output JSON).

- **Le type est envoyé à l'IA dans le system prompt** sous forme de
  contraintes explicites. Pour les 5 items de l'exemple ci-dessus,
  l'IA reçoit :
  ```
  Réponds en JSON strictement typé :
    - title_proposal      : string
    - h1_proposal         : string
    - metadesc_proposal   : string
    - score_quality       : integer entre 0 et 100
    - is_thin_content     : boolean (true ou false, jamais "true" ni 1)
  ```
  → l'IA respecte naturellement le typage dans `response_format: {json_object}`,
  on récupère des vraies values typées dans la réponse.

- **Le type sert ensuite à 2 choses** :
    1. Le worker écrit dans `pages.generation` en utilisant le bon
       type natif JSONB (`42` au lieu de `"42"`, `true` au lieu de
       `"true"`). Validation : si l'IA renvoie un type incorrect
       (ex: une string là où on attend un number), l'URL est marquée
       failed et retry 1-par-1.
    2. URL/Link Explorer détecte le type via `jsonb_typeof()` et propose
       les **opérateurs de filtre adaptés** :
       - `text` → `=`, `≠`, `contains`, `starts_with`, `regex`
       - `number` → `=`, `≠`, `>`, `<`, `≥`, `≤`, `between`
       - `boolean` → `= true`, `= false`

- **Pourquoi multi-items en 1 batch ?** Le system prompt + le contexte URL
  (qui sont les gros consommateurs de tokens input) ne sont envoyés
  qu'**une seule fois** pour produire N valeurs. Ex : générer
  `title + h1 + metadesc` en 1 job coûte ~1/3 du prix de 3 jobs séparés.

- **Le sélecteur de contexte** détermine ce qui est envoyé à l'IA, donc
  directement la **précision** ET le **coût** :
    - Cocher juste `url + title + h1` → contexte ~30 tokens/URL → batch 30
    - Cocher en plus `visible_content` → contexte ~1000 tokens/URL → batch 4-5
    - L'estimation à l'étape 2 reflète immédiatement les changements.

- **`{visible_content}`** est extrait à la volée par le worker depuis
  l'HTML stocké (table `html`), décompressé, nettoyé (script/style/svg/nav
  retirés), text-only. Même pipeline que pour le simhash de duplication.
  Cap à 4000 caractères par URL pour pas faire exploser les tokens.

- **Le batch size auto** est calculé en prenant en compte ET le contexte
  ET le nombre d'items demandés :
  `batch = max(1, floor(8000 / (context_tokens + items_count × 80)))`.
  Vise ~8k tokens d'input par appel (sweet spot perf/coût/risque
  de parse fail). L'utilisateur peut override en manuel.

- **Si une key existe déjà** dans `pages.generation` (genre l'admin a
  déjà lancé un job `title_proposal` la semaine dernière), on prévient
  et on écrase **seulement les URLs de cette nouvelle sélection**.
  Les URLs hors-sélection gardent leur ancienne valeur intacte.

### Étape 2 — Preview & estimation (modal step 2)

```
╔══════════════════════════════════════════════════════════════════╗
║  ✨ Generate with AI                                    step 2/3 ║
╠══════════════════════════════════════════════════════════════════╣
║                                                                  ║
║  📊 Estimation                                                   ║
║  ┌──────────────────────────────────────────────────────────┐    ║
║  │ URLs sélectionnées     :  487                             │    ║
║  │ Contexte par URL       :  ~1 050 tokens (avec contenu)    │    ║
║  │ Batch size auto        :  5 URLs / appel                  │    ║
║  │ Appels API estimés     :  ~98                             │    ║
║  │ Tokens input estimés   :  ~540 000                        │    ║
║  │ Tokens output estimés  :  ~30 000                         │    ║
║  │ Modèle                 :  openai/gpt-4o-mini              │    ║
║  │ Coût estimé            :  $0.099 (±15%)                   │    ║
║  │ Durée estimée          :  ~4 min                          │    ║
║  └──────────────────────────────────────────────────────────┘    ║
║                                                                  ║
║  ⚠ Le contexte "contenu visible" multiplie le coût par ~8        ║
║    par rapport à title+h1 seuls. Décoche-le si pas indispensable.║
║                                                                  ║
║  🔍 Aperçu sur 3 URLs (génération test gratuite)                 ║
║  ┌──────────────────────────────────────────────────────────┐    ║
║  │ /product/abc                                              │    ║
║  │ → "Pull col rond Acme en coton biologique, coupe          │    ║
║  │    standard. Idéal pour la mi-saison."                    │    ║
║  │                                                           │    ║
║  │ /product/xyz                                              │    ║
║  │ → "Sneakers vintage Acme cuir beige. Style streetwear     │    ║
║  │    rétro, semelle vulcanisée."                            │    ║
║  │ ...                                                       │    ║
║  └──────────────────────────────────────────────────────────┘    ║
║                                                                  ║
║  [← Modifier le prompt]                  [Annuler]  [Lancer 🚀] ║
╚══════════════════════════════════════════════════════════════════╝
```

- **L'estimation** est recalculée à chaque changement de contexte coché à
  l'étape 1. C'est `tokenize_estimate(template + N_urls × avg_context)`.
- **L'aperçu sur 3 URLs** est gratuit (sync, le user attend 5-10s) et
  permet de valider la qualité **avant** de payer 487 appels.

### Étape 3 — Job en cours / résultats

```
╔══════════════════════════════════════════════════════════════════╗
║  ✨ summary_short — Job #42                  ▶ Running 2:14      ║
╠══════════════════════════════════════════════════════════════════╣
║                                                                  ║
║  ████████████████░░░░░░░░░░░░░  127 / 487  (26%)                 ║
║  Coût actuel : $0.026   |  Tokens : 142k in / 8k out             ║
║                                                                  ║
║  [⏹ Arrêter le job]    [✕ Fermer (continue en arrière-plan)]    ║
║                                                                  ║
║  ─────────────────────────────────────────────────────────────   ║
║  Derniers résultats                                              ║
║  ┌──────────────────────────────────────────────────────────┐    ║
║  │ ✓ /product/qwe-456    "Sac à dos étanche 25L pour…"      │    ║
║  │ ✓ /product/rty-789    "Pull col rond en coton bio…"      │    ║
║  │ ⚠ /product/zxc-345    parse failed, retry 1/2…           │    ║
║  └──────────────────────────────────────────────────────────┘    ║
║                                                                  ║
╚══════════════════════════════════════════════════════════════════╝
```

Une fois fini :

```
╔══════════════════════════════════════════════════════════════════╗
║  ✓ summary_short — Job #42  Done in 4:12 — Cost: $0.094          ║
╠══════════════════════════════════════════════════════════════════╣
║                                                                  ║
║  487 URLs traitées  |  482 OK  |  5 failed                       ║
║                                                                  ║
║  La colonne `generation_summary_short` est maintenant disponible ║
║  dans :                                                          ║
║    • URL Explorer  (filtres + colonnes optionnelles + export)    ║
║    • Link Explorer (target des liens)                            ║
║    • SQL Explorer  (`SELECT generation->>'summary_short' …`)     ║
║                                                                  ║
║  [Voir dans URL Explorer →]    [📥 Export CSV de ce job]         ║
║  [♻ Régénérer les failed]    [💾 Sauvegarder comme template]    ║
║                                                                  ║
╚══════════════════════════════════════════════════════════════════╝
```

> **Pas d'écrasement de `title` / `h1` / `metadesc`** — jamais. Les
> données du crawl restent la source de vérité de ce que Google voit.
> Toute la "production IA" vit dans la colonne `generation` à côté.

---

## 4. Architecture technique

```
┌─ Browser ───────────────────────────────────┐
│  URL Explorer → ✨ Generate → Modal wizard  │
│         │              ▲                    │
│         │ POST           │ poll status         │
│         ▼              │                    │
└─────────┼──────────────┼────────────────────┘
          │              │
┌─────────▼──────────────▼────────────────────┐
│  PHP-FPM (Web API)                          │
│  - POST /api/bulk-generate/preview          │
│    → 3 URLs sync, return for preview        │
│  - POST /api/bulk-generate/start            │
│    → validate, estimate, create job row     │
│    → enqueue via JobManager                 │
│    → return job_id IMMÉDIATEMENT            │
│  - GET  /api/bulk-generate/status?job=X     │
│    → return progress + last N URLs          │
│  - POST /api/bulk-generate/stop             │
│    → mark job 'stopped', worker poll quits  │
└─────────┬───────────────────────────────────┘
          │ writes to
          ▼
┌─ PostgreSQL ────────────────────────────────┐
│  bulk_generation_jobs   (audit + status)    │
│  pages.generation JSONB (results!)          │
└─────────▲───────────────────────────────────┘
          │ UPDATE
┌─ Worker (Docker replica) ───────────────────┐
│  worker.php picks `bulk-ai-generate:<id>`   │
│  → loop URLs in batches                     │
│    → SELECT context (only cocked cols)      │
│    → if visible_content : decode html→text  │
│    → call OpenRouterClient::chatCompletion  │
│    → parse JSON response (array of {url,v}) │
│    → UPDATE pages                           │
│       SET generation = jsonb_set(           │
│         coalesce(generation,'{}')::jsonb,   │
│         '{key}', '"value"'::jsonb)          │
│       WHERE id IN (...)                     │
│    → UPDATE bulk_generation_jobs progress   │
│  → mark done / failed                       │
└─────────────────────────────────────────────┘
```

**Choix clés :**

- **Réutilise le pattern `extracts`** déjà bien rodé : colonne JSONB,
  keys arbitraires, accès par clé, expose dans les explorateurs comme
  colonnes optionnelles, exporte en CSV. Zéro nouveau pattern à inventer.

- **100% asynchrone via JobManager** — comme `batch-categorize-project`.
  Workers Docker dédiés, aucun blocage FPM.

- **Un seul job en DB pour l'audit** — on ne stocke pas les résultats
  par URL dans une table dédiée, ils vivent dans `pages.generation`.
  L'audit table garde juste : status, tokens, cost, error global.

- **Progress query** : pour calculer le progress du job en live, on
  utilise `processed_count` mis à jour par le worker à chaque batch.
  Le détail des derniers résultats traités est lu directement depuis
  `pages` (WHERE l'URL est dans la sélection du job AND a la key).

### Schéma DB

```sql
-- Nouvelle colonne sur la table partitionnée pages (toutes partitions).
ALTER TABLE pages ADD COLUMN generation JSONB;
CREATE INDEX idx_pages_generation_gin ON pages USING GIN (generation);

-- Audit table : un row par job lancé, JAMAIS pour stocker les résultats.
CREATE TABLE bulk_generation_jobs (
    id              SERIAL PRIMARY KEY,
    user_id         INTEGER REFERENCES users(id) ON DELETE SET NULL,
    crawl_id        INTEGER NOT NULL,
    items           JSONB NOT NULL,          -- [{"name":"score_quality","type":"number","note":"0-100"}, ...]
    prompt_template TEXT NOT NULL,
    context_fields  TEXT[] NOT NULL,         -- {'url','title','h1','visible_content'}
    page_ids        TEXT[] NOT NULL,         -- IDs des URLs incluses dans le job
    model           VARCHAR(100) NOT NULL,
    batch_size      SMALLINT NOT NULL,
    url_count       INTEGER NOT NULL,
    processed_count INTEGER NOT NULL DEFAULT 0,
    failed_count    INTEGER NOT NULL DEFAULT 0,
    status          VARCHAR(20) NOT NULL,    -- queued/running/done/failed/stopped
    input_tokens    INTEGER NOT NULL DEFAULT 0,
    output_tokens   INTEGER NOT NULL DEFAULT 0,
    estimated_cost  NUMERIC(10,6),
    actual_cost     NUMERIC(10,6),
    error_message   TEXT,
    errors_sample   JSONB,                   -- {"page_id": "reason", …} max 20
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at      TIMESTAMP,
    finished_at     TIMESTAMP
);
CREATE INDEX idx_bgj_user_time ON bulk_generation_jobs(user_id, created_at DESC);
CREATE INDEX idx_bgj_crawl     ON bulk_generation_jobs(crawl_id, created_at DESC);
```

### Lecture des générations

Comme pour `extracts`, mais en exploitant le typage natif JSONB :
```sql
-- Accès direct avec extraction typée (note: -> garde le type natif,
-- ->> force en text)
SELECT url,
       generation->>'title_proposal'   AS title,         -- texte
       (generation->>'score_quality')::int AS score,     -- number cast
       (generation->>'is_thin_content')::bool AS thin    -- boolean cast
FROM pages WHERE generation ? 'title_proposal';

-- Détection des keys connues + leur type, en un seul query.
-- Utilisée par URL Explorer pour proposer les colonnes `generation_xxx`
-- ET les bons opérateurs de filtre.
SELECT key,
       jsonb_typeof(value) AS type,
       COUNT(*) AS n_pages
FROM pages,
     LATERAL jsonb_each(generation)
WHERE crawl_id = X AND jsonb_typeof(generation) = 'object'
GROUP BY key, jsonb_typeof(value)
ORDER BY key, n_pages DESC;
```

Résultat exemple :
```
key                  | type    | n_pages
---------------------+---------+---------
h1_proposal          | string  | 484
is_thin_content      | boolean | 484
metadesc_proposal    | string  | 484
score_quality        | number  | 482
score_quality        | string  | 2       ← 2 pages où l'IA a échoué le typage
title_proposal       | string  | 484
```

L'URL Explorer prend le type **dominant** par key. Si > 95% des values
sont du type attendu → utilise ce type. Sinon → fallback en text (et
on peut afficher un warning "⚠ X URLs ont un type inattendu").

L'URL Explorer fait déjà cette détection auto pour `extracts` (string vs
numeric) — on ajoute juste le même code en plus complet pour `generation`,
avec en bonus le support natif des booleans. Pareil pour le Link Explorer
qui peut filtrer par `target.generation->>'X'`.

### Sémantique d'écrasement

```sql
-- Le worker fait, pour chaque URL traitée, un MERGE JSONB :
UPDATE pages
SET generation = coalesce(generation, '{}'::jsonb)
              || :new_keys::jsonb
WHERE crawl_id = :cid AND id = :pid;

-- :new_keys est le JSON des items du job courant, ex pour 3 items :
-- {"title_proposal":"…","h1_proposal":"…","metadesc_proposal":"…"}
```

L'opérateur `||` JSONB fait un **merge gauche** : les keys du job courant
sont ajoutées si nouvelles, écrasées si existantes, MAIS les autres keys
(d'autres jobs antérieurs) **restent intactes**.

→ C'est ce qui permet à plusieurs jobs de cohabiter naturellement sur la
même URL : un job A produit `summary_short`+`target_keyword`, un job B
produit `title_proposal`+`h1_proposal`, et `pages.generation` contient
les 4 keys en parallèle.

### Composants PHP à créer

| Fichier | Rôle |
|---------|------|
| `app/AI/BulkGenerator.php`               | Orchestrateur : prend un job_id, loop batches, write `pages.generation` (avec valeurs typées natives) |
| `app/AI/Prompts/BulkGeneratePrompt.php`  | Build le prompt batch (system + user JSON), encode la spec multi-items (nom + type + note) dans le system prompt, valide le typing de la réponse |
| `app/AI/ContextBuilder.php`              | Construit le contexte par URL selon les champs cochés (lit html si `visible_content`) |
| `app/AI/PromptEstimator.php`             | Tokenize estimate (chars/4 heuristic ou tiktoken-php) |
| `app/Http/Controllers/BulkGenerateController.php` | API endpoints preview/start/status/stop |
| `app/Cli/Cmder.php` (existant)           | + méthode `bulkAiGenerate($jobIdArg)` |
| `app/bin/worker.php` (existant)          | + branche pour `bulk-ai-generate:<id>` |
| `migrations/...-bulk-generation.php`     | ALTER TABLE pages + CREATE TABLE bulk_generation_jobs |
| `web/components/bulk-ai-modal.php`       | UI 3 étapes (avec sélecteur de type par item) |
| `web/components/jobs-in-progress.php`    | Widget bottom-right |
| `web/pages/url-explorer.php` (existant)  | Détection auto des `generation_xxx` keys + leur type → opérateurs de filtre adaptés (>, <, contains, = true/false) |
| `web/pages/link-explorer.php` (existant) | Idem côté target des liens |

---

## 5. Économie de tokens — le cœur du système

### Le problème

Le coût varie ENORMEMENT selon le contexte coché ET le nombre d'items :

| Contexte par URL                  | Tokens/URL | Batch auto | Coût 1000 URLs, 1 item | Coût 1000 URLs, 3 items |
|-----------------------------------|-----------:|-----------:|----------------------:|------------------------:|
| url + title (minimal)             | ~25        | 30-50      | $0.005                | $0.008                  |
| url + title + h1 + metadesc       | ~60        | 20-30      | $0.012                | $0.018                  |
| + visible_content (4k chars cap)  | ~1 050     | 4-8        | $0.090                | $0.105                  |

**Observations clés :**
- `visible_content` multiplie le coût par 8-18×. Opt-in explicite, warning.
- Passer de 1 à 3 items dans le même job ajoute juste ~15-50% au coût
  (output tokens augmentent, input reste pareil). C'est le PRIX du
  multi-item batching, et c'est ÉNORMÉMENT moins cher que 3 jobs séparés
  (qui paieraient 3× le contexte input).

### Tactiques d'économie implémentées

1. **Contexte minimal opt-in** — l'utilisateur coche uniquement ce dont
   son prompt a besoin. Détection auto des `{variables}` non utilisées
   pour proposer de décocher.

2. **Batching dynamique** — le batch size s'adapte au contexte coché ET
   au nombre d'items à générer :
   `batch = max(1, floor(8000 / (context_tokens + items_count × 80)))`.
   On vise ~8k tokens d'input par appel (sweet spot perf/coût/risque de
   parse fail). Plus on demande d'items, plus le batch rétrécit pour
   garder l'output JSON sous contrôle.

3. **Output JSON structuré multi-items + typé** — on force
   `response_format: {type: "json_object"}` sur OpenRouter et on précise
   le type attendu de chaque item dans le system prompt. Le modèle
   répond avec **N items par URL avec les vrais types natifs** :
   ```json
   {"results": [
     {
       "page_id": "abc12345",
       "title_proposal": "Pull col rond Acme — Coton Bio | Marque",
       "h1_proposal": "Pull col rond en coton biologique",
       "metadesc_proposal": "Découvrez notre pull...",
       "score_quality": 78,
       "is_thin_content": false
     },
     {
       "page_id": "xyz67890",
       "title_proposal": "Sneakers vintage Acme — Cuir Beige | M.",
       "h1_proposal": "Sneakers vintage en cuir beige",
       "metadesc_proposal": "...",
       "score_quality": 91,
       "is_thin_content": false
     }
   ]}
   ```
   → pas de tokens gaspillés en formatting markdown ni phrases d'intro,
   un seul appel produit N valeurs au lieu de N appels = N×, ET les
   valeurs numériques/booléennes coûtent moins de tokens d'output
   qu'une string équivalente (`78` = 1 token, `"78"` = 3 tokens).

4. **`visible_content` capé à 4000 caractères** — au-delà, on tronque.
   Pour 99% des pages ça suffit largement (l'IA n'a pas besoin du body
   complet pour générer un résumé).

5. **Cache sur identique** — `hash(prompt + context) → value`. Évite la
   double facturation si l'utilisateur regénère après avoir ajusté quelques
   URLs.

6. **Modèle léger par défaut** (`model_light`).

7. **Estimation pre-flight obligatoire** — coût en USD affiché AVANT.

8. **Fail fallback** — si le batch JSON parsing échoue, retry le batch
   en mode 1-par-1.

9. **Hard cap par job** — 5 000 URLs maximum. Au-delà, splitter.

### Pourquoi pas un cache plus agressif (cross-job)

Tentant mais dangereux : si l'admin change le prompt, le contexte, ou si
le contenu de la page change, on doit régénérer. Le cache cross-job ne
sait pas distinguer ces cas. On reste sur le cache intra-job pour le MVP.

---

## 6. Exemple chiffré concret

Un site e-commerce de 2 400 produits. L'utilisateur veut générer
**3 propositions SEO en un seul job** : un `title_proposal`, un
`h1_proposal`, et une `metadesc_proposal` pour chaque produit.

### Configuration

```
Items            : title_proposal (max 60 chars)
                   h1_proposal (max 80 chars)
                   metadesc_proposal (140-160 chars)
Contexte coché   : url + title + h1 + visible_content (cap 4k chars)
Prompt           : "Pour chaque produit, génère les 3 items demandés…"
Modèle           : openai/gpt-4o-mini ($0.15/M in, $0.60/M out)
Filtre URL       : cat = product, code = 200 → 487 URLs
```

### Estimation pre-flight

```
Contexte/URL     :  ~1 050 tokens (1 000 pour visible_content)
Items/URL        :  3 (≈ 80 tokens output × 3 = 240 tokens/URL)
Batch auto       :  4 URLs/call  (max(1, floor(8000 / (1050 + 3×80))))
Appels API       :  ceil(487/4) = 122 calls
Input tokens     :  122 × 350 (system + instructions) + 487 × 1 050
                 :  = 42 700 + 511 350 = ~554 000
Output tokens    :  487 × 240 (3 items × ~80 tokens) = ~117 000
Coût estimé      :  554k × $0.15/M + 117k × $0.60/M = $0.083 + $0.070 = $0.153
Durée            :  122 × ~3s = ~6 min
```

### Coût comparé : 1 job multi-items vs 3 jobs séparés

```
1 job multi-items (notre approche)  :  $0.15
3 jobs séparés (1 par item)         :  $0.27  (3× le contexte input)

→ Économie ~45% en faisant les 3 items dans le même job.
```

### Réel après run

```
Tokens input     :  541 200   (estimation -2%)
Tokens output    :  112 400
Coût réel        :  $0.149
Durée            :  5m 47s
URLs ok          :  484 / 487
URLs failed      :  3 (retry 1-par-1 réussi sur 2, 1 page sans HTML stocké)
```

### Après le run

L'utilisateur retourne dans **URL Explorer**. Dans le panneau "Colonnes
optionnelles" il voit maintenant **3 nouvelles colonnes** :
`generation_title_proposal`, `generation_h1_proposal`,
`generation_metadesc_proposal` (à côté des `extract_xxx`).

Il les active → 3 colonnes apparaissent dans le tableau. Export CSV
→ un fichier de 484 lignes avec url + title + h1 + 3 propositions IA,
prêt à coller dans un Google Sheet pour validation manuelle puis
dispatch vers le CMS.

### Si plus tard il veut aussi un score qualité + un booléen + un mot-clé

Il relance le wizard avec **3 nouveaux items typés** :
- `score_quality` (type: **number**, note: "Entier 0-100")
- `is_thin_content` (type: **boolean**, note: "true si < 300 mots")
- `target_keyword` (type: **text**, note: "1-3 mots")

Le worker fait `UPDATE … SET generation = generation || '{
  "score_quality": 78,
  "is_thin_content": false,
  "target_keyword": "pull coton bio"
}'::jsonb` → ça AJOUTE 3 nouvelles keys avec leurs **vrais types natifs**
à `pages.generation` sans toucher aux 3 anciennes.

Au final, `pages.generation` ressemble à :
```json
{
  "title_proposal": "Pull col rond Acme — Coton Bio | Marque",
  "h1_proposal": "Pull col rond en coton biologique",
  "metadesc_proposal": "Découvrez notre pull col rond...",
  "score_quality": 78,
  "is_thin_content": false,
  "target_keyword": "pull coton bio"
}
```

Et dans URL Explorer, l'utilisateur peut maintenant filtrer :
- `generation_score_quality > 80` → top tier
- `generation_is_thin_content = true` → pages à enrichir
- `generation_target_keyword contains "pull"` → audit thématique

Les 6 colonnes `generation_xxx` sont exposées avec les opérateurs adaptés
à leur type (numérique, booléen, texte).

---

## 7. Roadmap

### MVP (sprint 1 — ~1 semaine)

- Migration : `ALTER TABLE pages ADD generation JSONB` + `bulk_generation_jobs`
- `BulkGenerateController` : preview / start / status / stop
- `BulkGenerator` orchestrator + worker integration (multi-items, multi-types,
  validation du typing de la réponse IA + retry 1-par-1 si type mismatch)
- `ContextBuilder` (extraction du visible_content réutilisant le decode html existant)
- `BulkGeneratePrompt` builder (multi-items typés, batch JSON output, contrainte
  de type explicite dans le system prompt)
- `PromptEstimator` (rough char/4 tokenizer, prend en compte nb d'items)
- Modal wizard 3 steps (UI dynamique pour ajouter/retirer des items + dropdown type)
- URL Explorer : détection auto des `generation_xxx` keys via `jsonb_typeof()`
  + colonne optionnelle + opérateurs adaptés au type + export
- Link Explorer : idem côté target
- Test sur 100 URLs réelles avec un mix d'items (text + number + boolean)

### V2 (sprint 2)

- Widget "Jobs in progress" persistent
- Templates de prompts sauvegardables ("Mon prompt résumé e-commerce")
- Retry intelligent sur les failed (bouton "♻ Re-essayer les N failed")
- `{visible_content}` paramétrable (cap 2k vs 4k vs 8k)
- Bouton "Inspect a generation" : voir la page + l'output côte-à-côte
- Délétion d'une generation key complète (UPDATE pages SET generation = generation - 'key')

### V3 (futur)

- A/B prompts : lancer 2 prompts sur le même set, comparer
- Re-génération auto sur changement de contenu (au prochain crawl, si
  le simhash a changé, marquer la generation key comme "stale")
- Multi-pass (modèle critique chain)
- Programmation : auto-régen toutes les semaines sur nouveaux URLs
- Export riche : Markdown / HTML / JSON Lines en plus du CSV

---

## 8. Questions ouvertes

1. **Permission** : qui lance des jobs ? Tous les users avec
   `requireCrawlManagement` ? Admin only ? Quota par jour par user ?
2. **Quota global** : budget mensuel par projet en USD ? On peut afficher
   "budget restant ce mois" depuis OpenRouter `/auth/key`.
3. **Validation pre-write** : on écrit dans `pages.generation` directement
   ou on demande une validation explicite (bouton "Appliquer" à la fin) ?
   Pour le MVP je propose **écriture directe** — l'utilisateur peut DELETE
   la key en 1 clic si insatisfait. Mais à débattre.
4. **Modération** : si OpenRouter refuse un prompt (content policy),
   comment afficher (`status = 'refused'` distinct de `'failed'`) ?
5. **Re-crawl** : quand un nouveau crawl du projet tourne, les anciennes
   generations sont perdues (table partitionnée par crawl_id, nouveau
   crawl = nouvelle partition vide). C'est OK pour le MVP, mais un V2
   pourrait proposer "copier les generations du crawl précédent"
   en option de re-crawl.
6. **Variables exotiques** : `{outlinks_count}`, `{depth}`, … on les
   ajoute tout de suite ou on attend la demande ?

---

## 9. Ce que ça n'est PAS

- ❌ Un éditeur de contenu web in-app (apply changes to live site)
- ❌ Un système de revue éditoriale multi-user (workflow d'approbation)
- ❌ Un générateur d'articles long-form (cas trop spécifique)
- ❌ Une intégration directe avec WordPress / Shopify (l'export CSV est
  l'interface)
- ❌ Un orchestrateur multi-modèles (Claude ET GPT en parallèle) — un seul
  modèle par job pour rester simple
- ❌ **Un écraseur de title/h1/metadesc** — JAMAIS. Le crawl reste la
  source de vérité de ce que Google voit. Tout output IA vit dans
  `generation`, à côté.
