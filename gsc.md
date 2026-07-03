# Connecteur Google Search Console — Plan

> Statut : **planification** (rien n'est codé). Ce document décrit (1) ce que **toi** tu dois
> faire côté Google Cloud, (2) l'architecture technique, (3) le modèle de données, (4) les jobs,
> (5) la vue « Search Analytics », (6) le découpage en phases.
>
> Décisions verrouillées (Mehdi, 2026-07-02) :
> - Backfill initial : **16 mois** (max GSC).
> - Types de recherche : **web uniquement** (extensible plus tard).
> - Clics anonymisés : **ligne explicite `(anonyme)` matérialisée** par jour (total − somme mots-clés).
> - Vue accessible **au niveau du projet** (pas dans le dashboard de crawl, qui est scopé par crawl).
> - Stockage dans **ClickHouse**, requêtable via le **SQL Explorer**, avec la **date** stockée par ligne.

---

## 0. Rappel des contraintes GSC qui pilotent le design

| Contrainte | Conséquence design |
|---|---|
| **Délai de fraîcheur 2–3 j** (parfois +) ; les derniers jours sont incomplets | Sync quotidienne = **fenêtre glissante 7 jours** re-récupérée à chaque passage. On ne se fie jamais à « hier ». |
| **Requêtes anonymisées** : dès qu'on demande la dimension `query`, Google **supprime les requêtes à faible volume** → Σ(lignes mots-clés) < total réel | Il faut récupérer aussi les **marges** (site sans dimension, page sans query) pour connaître le vrai total, puis matérialiser le **delta anonymisé**. |
| **Position moyenne non additive** (contrairement clics/impressions) | Le bucket `(anonyme)` porte **uniquement clics + impressions** ; sa position reste vide. La position agrégée sur une plage se recalcule en **moyenne pondérée par impressions**. |
| **25 000 lignes / requête**, pagination via `startRow` | Boucle de pagination par jour/dimension. |
| **Quota 1 200 req/min/site**, 40 000/min/projet GCP, quotas de charge court/long terme | Throttling du backfill + backoff sur 429 (attendre ~15 min). |
| **Rétention 16 mois glissants** | Backfill = 16 mois ; au-delà la donnée n'existe plus chez Google → **notre CH devient l'archive** au-delà de 16 mois. |
| **Property = `sc-domain:` (domaine) ou `https://…/` (préfixe URL)** | Le `siteUrl` doit être **URL-encodé** dans le path API (`sc-domain:ex.com` → `sc-domain%3Aex.com`). On stocke le type. |

Sources : [Usage Limits](https://developers.google.com/webmaster-tools/limits) · [Performance data deep dive](https://developers.google.com/search/blog/2022/10/performance-data-deep-dive) · [Getting all your data](https://developers.google.com/webmaster-tools/v1/how-tos/all-your-data) · [Refresh token 7-day rule](https://www.unipile.com/google-oauth-refresh-token/).

---

## 1. ✅ CE QUE TU DOIS FAIRE CÔTÉ GOOGLE CLOUD (avant tout code)

Tout se passe dans **[console.cloud.google.com](https://console.cloud.google.com)**. ~15 min.

### 1.1 Créer / choisir un projet GCP
1. En haut, **sélecteur de projet → Nouveau projet** (ex. `scouter-gsc`). Note le **Project ID**.

### 1.2 Activer l'API
2. **APIs & Services → Library** → cherche **« Google Search Console API »** → **Enable**.
   (Si tu vois aussi « Search Console API » legacy `webmasters` : c'est la même famille, l'activation suffit.)

### 1.3 Écran de consentement OAuth
3. **APIs & Services → OAuth consent screen**.
   - **User type : External** (obligatoire sauf si compte Google Workspace : Internal possible et plus simple).
   - App name : `Scouter`, email support = le tien, logo optionnel.
   - **Scopes** : ajouter **`.../auth/webmasters.readonly`** (c'est un scope **« sensible »**). Ajouter aussi `openid` et `.../auth/userinfo.email` (non sensibles, pour afficher le compte connecté).
   - **⚠️ POINT CRITIQUE — Publishing status : passer en « In production ».**
     En mode **Testing**, Google **révoque le refresh token au bout de 7 jours** → la sync quotidienne casserait toute seule. En **Production** non vérifié, tu auras juste un **écran d'avertissement « app non vérifiée »** (clic « Paramètres avancés → Continuer ») mais **le refresh token ne meurt plus à 7 jours**. Pour un usage interne c'est parfait ; la **vérification Google complète** (vidéo, justification) n'est nécessaire que si tu ouvres ça à des utilisateurs externes larges.
   - Ajoute ton adresse (et celles des collègues qui connecteront) même en Production.

### 1.4 Créer les identifiants OAuth
4. **APIs & Services → Credentials → Create credentials → OAuth client ID**.
   - **Application type : Web application**.
   - **Authorized redirect URIs** : ajouter l'URL de callback de Scouter, ex. :
     - prod : `https://<ton-domaine-scouter>/gsc/callback`
     - local : `http://localhost:8080/gsc/callback` (adapte le port de ton `docker-compose.local`).
   - Valide → récupère **Client ID** et **Client Secret**.

### 1.5 Renseigner les variables d'environnement
5. Ces 3 valeurs vont dans l'environnement de Scouter (comme les autres secrets infra), **PAS** dans la base. Ajoute-les dans `.env` (et dans `.env.example` en placeholder), elles seront lues par `getenv()` :

```bash
# --- Google Search Console OAuth ---
GOOGLE_OAUTH_CLIENT_ID=xxxxxxxx.apps.googleusercontent.com
GOOGLE_OAUTH_CLIENT_SECRET=GOCSPX-xxxxxxxx
GOOGLE_OAUTH_REDIRECT_URI=https://<ton-domaine>/gsc/callback
```

   - `SCOUTER_ENCRYPTION_KEY` (déjà présent) sert à **chiffrer les refresh tokens** au repos → rien de nouveau à générer.
   - **Docker** : ces vars doivent être exposées au conteneur `scouter` **et au worker** (le worker relance `scouter.php` via `proc_open` avec un `$env` explicite qui **n'hérite pas** de l'environnement parent — cf. §5.4). On les listera dans `docker-compose.yml` / `docker-compose.local.yml` au même endroit que les `S3_*` / `CLICKHOUSE_*`.

6. **Côté Search Console (pas GCP)** : le compte Google que tu utiliseras pour connecter doit être **propriétaire/utilisateur** de la property dans [search.google.com/search-console](https://search.google.com/search-console). Sinon `sites.list` ne la renverra pas.

> **C'est tout pour toi.** Une fois ces 6 points faits, je peux implémenter tout le flux OAuth + backfill + vues.

---

## 2. Architecture — vue d'ensemble

```
                     ┌─────────────────────────────────────────────┐
   Navigateur ──────▶│  web/project.php  → bouton "Connecter GSC"   │
                     │  web/search-analytics.php (vue projet)       │
                     └───────────────┬─────────────────────────────┘
                                     │  OAuth redirect / AJAX
                     ┌───────────────▼─────────────────────────────┐
   PHP (web app)     │  App\Http\Controllers\GscController          │
                     │   /gsc/connect /gsc/callback /gsc/select     │
                     │   /gsc/disconnect  /api/gsc/query …          │
                     │  App\Google\GoogleOAuthClient (curl)         │
                     │  App\Google\SearchConsoleClient (curl)       │
                     │  App\Gsc\ConnectorRepository (PG, chiffré)   │
                     │  App\Gsc\GscIngestor (écrit ClickHouse)      │
                     └───────┬───────────────────────┬─────────────┘
                             │ enqueue jobs (PG)      │ read
              ┌──────────────▼─────────┐    ┌─────────▼──────────┐
   Jobs       │ PostgreSQL             │    │ ClickHouse         │
              │  gsc_connectors        │    │  gsc_site_daily    │
              │  jobs / job_logs       │    │  gsc_page_daily    │
              └──────────────┬─────────┘    │  gsc_query_daily   │
                             │              │  gsc_page_query_…  │
              ┌──────────────▼─────────┐    └────────────────────┘
   Worker PHP │ app/bin/worker.php      │            ▲
   (existant) │  gsc-backfill:<id>      │────────────┘ ingest via SearchConsoleClient
   +4 réplicas│  gsc-sync:<id>          │
              │  gsc-delete:<id>        │
              └────────────────────────┘
   Cron       app/bin/gsc-sync-scheduler.php (quotidien) → enqueue gsc-sync pour chaque connecteur actif
```

**Choix clés (justifiés par l'existant)** :
- **Tout en PHP** (l'app web possède l'OAuth, les jobs non-crawl, l'UI). Le crawler Go n'est pas touché.
- **Client Google hand-rolled en curl** (comme `App\AI\OpenRouterClient`, `App\Storage\S3Storage` en SigV4 maison, le client ClickHouse HTTP). On **n'ajoute pas** `google/apiclient` : 3 endpoints suffisent, et le projet a une politique de dépendances minimales.
- **Refresh token chiffré AES-256-GCM** avec la clé dérivée de `SCOUTER_ENCRYPTION_KEY` — on **réutilise le mécanisme de `App\Settings\AppSettings`** (on extrait les 3 méthodes `encrypt/decrypt/deriveKey` dans un `App\Util\SecretCrypto` partagé).
- **Jobs = table PG + `command` discriminant**, worker PHP existant (`SELECT … FOR UPDATE SKIP LOCKED`). On ajoute 3 commandes.
- **ClickHouse** pour la donnée analytique, **partitionnée par `project_id`** (nouveau : jusqu'ici CH n'était partitionné que par `crawl_id`) → suppression d'un connecteur = `DROP PARTITION` instantané, exactement comme un crawl.

---

## 3. Modèle de données

### 3.1 PostgreSQL — table `gsc_connectors` (métadonnées + secret)

Migration `migrations/2026-07-02-XX-XX-gsc-connectors.php` (runner PG existant, `migrations/migrate.php`).

```sql
CREATE TABLE gsc_connectors (
    id                  SERIAL PRIMARY KEY,
    project_id          INTEGER NOT NULL UNIQUE REFERENCES projects(id) ON DELETE CASCADE,
    site_url            TEXT    NOT NULL,              -- "sc-domain:ex.com" ou "https://www.ex.com/"
    property_type       VARCHAR(16) NOT NULL,          -- 'domain' | 'url_prefix'
    google_email        TEXT,                          -- compte connecté (affichage)
    google_sub          TEXT,                          -- id stable du compte Google
    refresh_token_enc   TEXT    NOT NULL,              -- chiffré 'enc:v1:...'
    scope               TEXT,
    status              VARCHAR(24) NOT NULL DEFAULT 'connecting',
                        -- connecting | backfilling | active | error | disconnecting
    backfill_cursor     DATE,                          -- jour en cours de backfill (reprise sur crash)
    backfill_started_at TIMESTAMPTZ,
    last_synced_date    DATE,                          -- dernier jour de données réellement présent
    last_sync_at        TIMESTAMPTZ,
    last_error          TEXT,
    created_by          INTEGER REFERENCES users(id),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

- **1 connecteur par projet** (`UNIQUE project_id`). Le partage projet (`project_shares`) donne l'accès à la vue à tous les collaborateurs.
- L'**access token** (durée 1 h) n'est **jamais stocké** : on le régénère depuis le refresh token à chaque job (mis en cache en mémoire le temps du process).
- L'état PKCE/CSRF du handshake OAuth (`state`, `code_verifier`) est gardé en **`$_SESSION`** (l'app est déjà session-based) — pas de table nécessaire.

### 3.2 ClickHouse — 4 tables analytiques

À ajouter dans `crawler-go/internal/db/schema.sql` (source canonique, appliquée idempotemment au boot du crawler via `EnsureSchema`) **ET** créées à la demande depuis PHP (`CREATE TABLE IF NOT EXISTS`) au moment où on active le premier connecteur, pour ne pas attendre un reboot crawler. ⚠️ Rappel : **aucun `;` dans `schema.sql`** (le loader CI split dessus).

Convention commune à toutes : `ReplacingMergeTree(version)` (même idiome que la table `pages`), `PARTITION BY project_id`, `version = toUnixTimestamp(now())`. La **date est stockée** en colonne `date`. Lecture dédupliquée via `LIMIT 1 BY (…clés…) ORDER BY version DESC` (ou `FINAL`).

```sql
-- (1) Totaux SITE par jour  (dimensions = [date])  → vrais totaux, incluent l'anonymisé
CREATE TABLE IF NOT EXISTS scouter.gsc_site_daily (
    project_id  Int32,
    site        String,
    search_type LowCardinality(String) DEFAULT 'web',
    date        Date,
    clicks      Int64,
    impressions Int64,
    position    Float32,          -- position moyenne du jour (telle que renvoyée par GSC)
    version     UInt64 DEFAULT toUnixTimestamp(now())
) ENGINE = ReplacingMergeTree(version)
PARTITION BY project_id
ORDER BY (project_id, search_type, date);

-- (2) Totaux par URL par jour  (dimensions = [date, page])  → total par URL (anonymisé inclus)
CREATE TABLE IF NOT EXISTS scouter.gsc_page_daily (
    project_id  Int32, site String, search_type LowCardinality(String) DEFAULT 'web',
    date Date, page String,
    clicks Int64, impressions Int64, position Float32,
    version UInt64 DEFAULT toUnixTimestamp(now())
) ENGINE = ReplacingMergeTree(version)
PARTITION BY project_id
ORDER BY (project_id, search_type, date, page);

-- (3) Totaux par MOT-CLÉ par jour  (dimensions = [date, query]) + lignes '(anonyme)' matérialisées
CREATE TABLE IF NOT EXISTS scouter.gsc_query_daily (
    project_id  Int32, site String, search_type LowCardinality(String) DEFAULT 'web',
    date Date, query String,
    clicks Int64, impressions Int64, position Float32,
    is_anon UInt8 DEFAULT 0,     -- 1 = ligne synthétique '(anonyme)'
    version UInt64 DEFAULT toUnixTimestamp(now())
) ENGINE = ReplacingMergeTree(version)
PARTITION BY project_id
ORDER BY (project_id, search_type, date, query);

-- (4) Joint MOT-CLÉ × URL par jour (dimensions = [date, query, page]) + '(anonyme)' par URL
CREATE TABLE IF NOT EXISTS scouter.gsc_page_query_daily (
    project_id  Int32, site String, search_type LowCardinality(String) DEFAULT 'web',
    date Date, page String, query String,
    clicks Int64, impressions Int64, position Float32,
    is_anon UInt8 DEFAULT 0,
    version UInt64 DEFAULT toUnixTimestamp(now())
) ENGINE = ReplacingMergeTree(version)
PARTITION BY project_id
ORDER BY (project_id, search_type, date, page, query);
```

**Pourquoi 4 tables et pas une seule ?**
- L'anonymisation fait que **chaque niveau d'agrégat a un total différent** ; on ne peut pas dériver le total-URL en sommant le joint (undercount). Il faut donc les marges physiques.
- Elles sont **les rollups** : « total par URL » = simple lecture de `gsc_page_daily` (pas de somme sur des milliers de mots-clés → répond à ta contrainte d'agrégation pour l'analyse croisée future).
- `gsc_site_daily` = la « data site au global hors URL », identique à ce que tu vois dans l'onglet global de Search Console.

**Volumétrie** : négligeable devant les crawls (une property = quelques centaines de k lignes/jour au pire pour le joint). Une partition par projet est confortable et donne un delete instantané.

### 3.3 Matérialisation du bucket `(anonyme)`

Calculé **à l'ingestion**, jour par jour (on a déjà toutes les marges en mémoire) :

- Dans `gsc_query_daily` : ligne `query='(anonyme)', is_anon=1` avec
  `clicks = site_daily.clicks − Σ query_daily.clicks` (idem impressions), `position = 0/NULL`.
- Dans `gsc_page_query_daily` : pour **chaque URL**, ligne `query='(anonyme)', is_anon=1` avec
  `clicks = page_daily.clicks(page) − Σ page_query_daily.clicks(page)` (idem impressions).

→ Les totaux **réconcilient** : `SELECT sum(clicks) FROM gsc_query_daily WHERE date=X` = total site du jour X. Pareil par URL. Tu peux filtrer/exclure l'anonymisé via `is_anon`.

> Note : on peut plafonner les valeurs négatives à 0 (arrondis GSC pouvant produire un petit négatif). À logguer si ça dépasse un seuil.

---

## 4. Flux OAuth & cycle de vie du connecteur

### 4.1 Connexion (première fois)
1. **Page projet** (`web/project.php`) : bouton **« Connecter Google Search Console »** (visible au propriétaire).
2. `GET /gsc/connect?project=<id>` → génère `state` + PKCE (`code_verifier`/`code_challenge` S256), les stocke en session, **redirige vers Google** :
   `https://accounts.google.com/o/oauth2/v2/auth?client_id=…&redirect_uri=…&response_type=code&access_type=offline&prompt=consent&scope=openid%20email%20https://www.googleapis.com/auth/webmasters.readonly&state=…&code_challenge=…&code_challenge_method=S256`
   (`access_type=offline` + `prompt=consent` = garantit un **refresh token**.)
3. **Callback** `GET /gsc/callback?code&state` → vérifie `state`, **échange le code** (`POST https://oauth2.googleapis.com/token`) → `access_token`, `refresh_token`, `id_token` (→ email). Chiffre et **stocke le refresh token** (statut `connecting`).
4. Appelle **`sites.list`** (`GET https://www.googleapis.com/webmasters/v3/sites`) → affiche un **sélecteur des properties** accessibles (on met en avant celles qui matchent le domaine du projet, mais on autorise le choix libre).
5. `POST /gsc/select-property` → écrit `site_url` + `property_type`, statut `backfilling`, **enqueue `gsc-backfill:<connectorId>`**, redirige vers la vue Search Analytics (« backfill en cours »).

### 4.2 Suppression du connecteur
- Bouton **« Déconnecter »** → `POST /gsc/disconnect` → statut `disconnecting`, **enqueue `gsc-delete:<connectorId>`**.
- Le job : `ALTER TABLE gsc_* DROP PARTITION <project_id>` sur les 4 tables (instantané), appelle `https://oauth2.googleapis.com/revoke` (révoque le token côté Google, best-effort), supprime la ligne `gsc_connectors`. Modèle calqué sur `Cmder::deleteCrawl` / `delete-project`.

### 4.3 Endpoints (routing)
On calque **`web/oauth.php`** (déjà un front-controller OAuth pour le MCP) : soit un `web/gsc.php`, soit des routes dans `app/Http/Router.php`. Controller `App\Http\Controllers\GscController` :
`/gsc/connect`, `/gsc/callback`, `/gsc/select-property`, `/gsc/disconnect`, + AJAX `/api/gsc/query`, `/api/gsc/timeseries`, `/api/gsc/status`.

---

## 5. Jobs

Trois nouvelles commandes, branchées dans **`app/bin/worker.php`** (branche + `$env` forwardé) et **`scouter.php`** (case), exactement comme `export:` / `delete-*`.

### 5.1 `gsc-backfill:<connectorId>` (initial, 16 mois)
- Rafraîchit un access token depuis le refresh token.
- Boucle de `date = today-2` (dernier jour raisonnablement complet) jusqu'à `today-485` (~16 mois), **jour par jour** :
  - Pour chaque table cible, appelle `searchAnalytics.query` avec `startDate=endDate=date`, `type='web'`, `dataState='final'` (données figées), pagination `rowLimit=25000` + `startRow` jusqu'à épuisement :
    - `[date]` → `gsc_site_daily`
    - `[date, page]` → `gsc_page_daily`
    - `[date, query]` → `gsc_query_daily`
    - `[date, query, page]` → `gsc_page_query_daily`
  - Calcule et insère les lignes `(anonyme)` (cf. §3.3).
  - Écrit le `backfill_cursor = date` (reprise sur crash → l'orphan-recovery du worker relance le job, on repart du curseur).
- Insertion CH par batch `FORMAT JSONEachRow` (via `ClickHouseDatabase`). Idempotent grâce à `ReplacingMergeTree(version)`.
- Fin → statut `active`, `last_synced_date = today-2`.
- **Throttling** : petite pause entre appels pour rester < 1 200 req/min/site ; sur `429`/quota → attente ~15 min + backoff exponentiel.
- **Durée** : de quelques minutes (petit site) à ~1 h (gros site). Le worker est en 4 réplicas → les autres jobs continuent. (Option de résilience : chunker en 1 sous-job par mois. Recommandé seulement si un backfill dépasse ~30 min en pratique.)

### 5.2 `gsc-sync:<connectorId>` (quotidien, fenêtre glissante)
- **Fenêtre `[last_synced_date − 6 … today-1]`** (≈ 7 derniers jours), `dataState='all'` (inclut la donnée fraîche, qui se stabilisera aux passages suivants).
- Re-récupère ces jours et **ré-insère avec un `version` plus récent** → `ReplacingMergeTree` remplace les anciennes lignes. **Pas de DELETE explicite, pas de fenêtre de trou, pas de doublon** : c'est l'équivalent propre de ton « supprimer les 7 derniers jours puis réinsérer ».
- Recalcule les lignes `(anonyme)` sur ces jours.
- Met à jour `last_synced_date = max(date réellement présent)` → gère nativement le cas « pas de données pour la veille » (GSC en retard) : les jours vides ne font rien.
- ⚠️ Limite du `ReplacingMergeTree` : si une paire (page,query) **disparaît** d'un jour re-récupéré (rare chez GSC, la donnée ne fait que se finaliser), l'ancienne ligne subsiste jusqu'à un `OPTIMIZE`. Si on veut l'exactitude stricte : `ALTER TABLE … DELETE WHERE project_id=X AND date>=… AND version<…` occasionnel. **Recommandé : ne pas s'en soucier en v1**, la donnée GSC ne régresse quasi jamais.

### 5.3 Planification de la sync — cron
- Nouveau `app/bin/gsc-sync-scheduler.php`, ajouté au crontab (`Dockerfile` / supervisord `[program:cron]`), **1×/jour** (ex. 06:00) : pour chaque connecteur `status='active'`, **enqueue `gsc-sync:<id>`** si `last_synced_date < today-1` (idempotent : on peut aussi enqueue inconditionnellement, le job est sûr).
- On réutilise le modèle `next_run`/cron du `scheduler.php` existant, mais séparé (le `scheduler.php` actuel est dédié aux crawls).

### 5.4 Environnement forwardé au worker (⚠️ piège connu)
`proc_open` avec un `$env` explicite **n'hérite pas** de l'environnement parent. Pour les 3 jobs GSC, forwarder (comme le fait déjà la branche `bulk-ai-generate` / `export`) :
`DATABASE_URL, PATH, JOB_ID, SCOUTER_ENCRYPTION_KEY` (déchiffrer le refresh token), `CLICKHOUSE_URL/DB/USER/PASSWORD` (écrire CH), **`GOOGLE_OAUTH_CLIENT_ID/SECRET/REDIRECT_URI`** (rafraîchir le token). Le `gsc-delete` a aussi besoin des `CLICKHOUSE_*`.

---

## 6. La vue « Search Analytics » (niveau projet)

**Nouveau fichier `web/search-analytics.php`** (entrée top-level comme `project.php`/`dashboard.php`), scopée `?project=<id>`, lien depuis `web/project.php`. Réutilise **toute** la charte existante — aucune réinvention visuelle.

### 6.1 Composants réutilisés (déjà en place)
| Besoin | Réutilise | Fichier |
|---|---|---|
| Cartes KPI | `Component::card([...])` | `web/components/card.php` |
| Graphe temporel (clics/impressions) | `Component::chart(['type'=>'area'/'line', …])` (Highcharts) | `web/components/chart.php` |
| Tableau (pagination AJAX, tri, sélecteur de colonnes, export CSV, copie) | `DataTable` JS + CSS | `web/assets/data-table.js` / `data-table.css` |
| Moteur de filtres à chips (regex, ET/OU) | `FilterBar` + `fieldConfig` | `web/assets/filter-bar.js` + bloc `url-explorer.php` L1503-1560 |
| Tokens couleurs / typo / cards | `:root` + palette | `web/assets/style.css` (`--primary-color:#4ECDC4`…), `web/config/palette.php` |
| i18n | `__('gsc.…')` | `web/lang/*.json` |

Meilleur gabarit à cloner : **`web/pages/codes.php`** (cards + charts + tableaux dans une page).

### 6.2 Contrôles de la vue
- **Sélecteur de plage de dates** : **à construire** (aucun date-range picker générique n'existe). Deux `input[type=date]` (from/to) + **présets** 7 / 28 / 90 / 365 j + « 16 mois ». Style repris des sélecteurs de planification de `web/project.php` (L400-475). Comparaison période précédente en option (deltas sur les cards).
- **Toggle de mode** : **Mots-clés** / **URLs** / **Les deux** → choisit la table lue :
  - Mots-clés → `gsc_query_daily`
  - URLs → `gsc_page_daily`
  - Les deux → `gsc_page_query_daily`
- **Barre de filtres** (`FilterBar`) : champs `query` (text : contains / regex / not_regex) et `url`/`page` (idem), **combinables** — OU dans un groupe, ET entre groupes, **regex supporté** (exactement la logique URL Explorer). En mode « Les deux », les deux champs sont dispo simultanément.
- **KPIs (cards)** : Clics, Impressions, **CTR = Σclicks/Σimpr**, **Position moyenne = Σ(position×impressions)/Σimpressions** (pondérée impressions — standard, approximation assumée car GSC ne renvoie qu'une moyenne par ligne). Toggle inclure/exclure l'anonymisé (`is_anon`).
- **Graphe** : série temporelle clics + impressions sur la plage (depuis `gsc_site_daily`, ou recalculée si un filtre est actif).
- **Tableau** : colonnes selon le mode (`query` et/ou `page`, clics, impressions, CTR, position moyenne), tri, pagination, **export CSV** (cf. §8).
- **Ligne `(anonyme)`** : visible dans le tableau et dans le total (togglable), pour que les totaux réconcilient à l'écran.

### 6.3 Endpoint data
`POST /api/gsc/query` `{project_id, mode, date_from, date_to, filters[], sort, dir, page, per_page, include_anon}` :
- Construit du SQL ClickHouse **scopé `project_id` + `date BETWEEN`**, applique un `buildFilterConditions` adapté GSC (repris de `url-explorer.php` L171-473 mais réduit aux champs `query`/`page`), agrège (`sum`, CTR, position pondérée), pagine.
- Ces tables **n'étant pas scopées par crawl**, on **n'utilise pas `ChPdo`** (qui force un `crawl_id`) : on écrit du CH direct via `ClickHouseDatabase::select()` avec params bindés (`{name:Type}`), sûr contre l'injection.
- `POST /api/gsc/timeseries` → série journalière pour le graphe.

---

## 7. Intégration SQL Explorer

Tu veux pouvoir requêter keywords/urls sur une plage de dates depuis le SQL Explorer.

- **Exposer les 4 tables** `gsc_*` à l'exécuteur : les ajouter à `ALLOWED_BASE_TABLES` (`app/AI/ClickHouseSqlExecutor.php` L49) et documenter leur schéma dans `app/AI/SqlGenPrompt.php` (bloc CH).
- **Scoping** : l'explorer est ouvert depuis un contexte crawl → il connaît le `project_id` (via `crawl.project_id`). On ajoute dans `ChPdo` une règle qui, pour une table `gsc_*`, injecte **`WHERE project_id = <projet du crawl courant>`** (comme le `crawl_id` est injecté pour `pages`/`links`). La **plage de dates** reste au choix de l'utilisateur dans son `WHERE date BETWEEN …`.
- Résultat : `SELECT query, sum(clicks) FROM gsc_query_daily WHERE date >= '2026-01-01' GROUP BY query ORDER BY 2 DESC` fonctionne, scopé au projet, avec les caps de lignes/temps existants.
- Export CSV de l'explorer : déjà géré par le système d'export ; on ajoute un **type d'export `gsc`** dans `App\Export\ExportService` qui **streame** `FORMAT CSVWithNames` depuis CH (réutilise `streamSelectToFile`, cf. mémoire *async-csv-exports*) — pas de bufferisation PHP.

---

## 8. Sécurité, quotas, résilience (récap)

- **Refresh token** chiffré AES-256-GCM (`SecretCrypto`, clé dérivée de `SCOUTER_ENCRYPTION_KEY`). Jamais affiché (masqué comme les clés API). Access token jamais persisté.
- **Client secret Google** en variable d'env (secret infra), pas en base.
- **Quotas** : throttling backfill (< 1 200 req/min/site), backoff 429/quota (~15 min), curseur de reprise. La sync quotidienne = ~7 jours × 4 requêtes → négligeable.
- **Reprise sur crash** : `backfill_cursor` + orphan-recovery du worker (re-queue des jobs `running` non-crawl au boot). Les jobs sont idempotents (ReplacingMergeTree).
- **Isolation projet** : partition CH par `project_id` + injection forcée `project_id` dans l'explorer.

---

## 9. Fichiers — création / modification (récap implémentation)

**Créer :**
- `migrations/2026-07-02-XX-XX-gsc-connectors.php` (table PG)
- `app/Util/SecretCrypto.php` (extrait AES de `AppSettings`, réutilisable)
- `app/Google/GoogleOAuthClient.php` (auth URL, échange code, refresh, revoke)
- `app/Google/SearchConsoleClient.php` (`sites.list`, `searchAnalytics.query` + pagination + backoff)
- `app/Gsc/ConnectorRepository.php` (CRUD PG + chiffrement)
- `app/Gsc/GscIngestor.php` (appels API → lignes → insert CH + calcul `(anonyme)`)
- `app/Gsc/GscBackfillJob.php`, `GscSyncJob.php`, `GscDeleteJob.php` (logique des 3 jobs)
- `app/Http/Controllers/GscController.php` (endpoints OAuth + AJAX data)
- `app/bin/gsc-sync-scheduler.php` (cron quotidien)
- `web/search-analytics.php` (vue projet) + éventuel `web/gsc.php` (front-controller OAuth)
- clés i18n `gsc.*` dans `web/lang/en.json` / `fr.json`

**Modifier :**
- `crawler-go/internal/db/schema.sql` (4 tables `gsc_*`, sans `;` — attention loader CI)
- `scouter.php` (cases `gsc-backfill` / `gsc-sync` / `gsc-delete`)
- `app/bin/worker.php` (branches + `$env` forwardé, cf. §5.4)
- `app/AI/ClickHouseSqlExecutor.php` (`ALLOWED_BASE_TABLES`) + `app/Database/ChPdo.php` (injection `project_id` pour `gsc_*`) + `app/AI/SqlGenPrompt.php` (doc schéma)
- `app/Export/ExportService.php` (type d'export `gsc`)
- `web/project.php` (bouton Connecter / statut / lien vers la vue)
- `.env` / `.env.example` / `docker-compose*.yml` (`GOOGLE_OAUTH_*`)
- `Dockerfile` ou crontab supervisord (ligne cron `gsc-sync-scheduler.php`)

---

## 10. Découpage en phases

1. **Phase 0 — Fondations & OAuth**
   Env vars + `SecretCrypto` + `GoogleOAuthClient` + `SearchConsoleClient` + table `gsc_connectors` + endpoints `/gsc/connect|callback|select-property` + bouton page projet. Livrable : on connecte une property et on stocke le refresh token (pas encore de data).
2. **Phase 1 — Schéma CH & ingestion**
   4 tables `gsc_*` + `GscIngestor` (une journée, 4 dimensions, pagination) + calcul `(anonyme)`. Testé sur 1 jour.
3. **Phase 2 — Jobs**
   `gsc-backfill` (16 mois, curseur, throttle) + `gsc-sync` (fenêtre 7 j) + `gsc-delete` + cron. Câblage worker/scouter.php.
4. **Phase 3 — Vue Search Analytics**
   `web/search-analytics.php` : cards KPI + graphe + date-range + toggle mode + FilterBar + tableau + export. UX raccord charte.
5. **Phase 4 — SQL Explorer**
   Exposition des 4 tables + injection `project_id` + doc prompt IA + export CSV type `gsc`.
6. **Phase 5 — Finitions**
   États d'erreur (token révoqué → ré-auth), badges de statut (backfill en cours / dernière sync), i18n complet, garde-fous quota.

---

## 11. Points ouverts / gardés pour plus tard (analyse croisée)

- **Croisement crawl × GSC** (v2) : facilité par `gsc_page_daily` (total par URL déjà agrégé, pas de somme sur des milliers de mots-clés). Jointure clé = URL. On mappera l'URL GSC ↔ `pages.id` (hash 8 char) côté requête.
- **Types de recherche additionnels** (Image/Vidéo/Discover/News) : le schéma le supporte déjà (`search_type`) ; il suffira d'ajouter des passes d'ingestion.
- **Dimensions pays / device** : non stockées en v1 (multiplient le volume) ; ajout possible en nouvelles tables si besoin.
- **Vérification OAuth Google complète** : nécessaire seulement si ouverture à des utilisateurs externes larges (sinon « In production » non vérifié suffit).
```
