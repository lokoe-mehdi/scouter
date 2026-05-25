# Audit de sécurité — Scouter

**Date :** 2026-05-25
**Périmètre :** application PHP (web + API `/api/v1`), serveur MCP, crawler Go (`crawler-go`), service de rendu (`renderer`), couche de données ClickHouse + PostgreSQL, déploiement Docker.
**Nature :** revue **lecture seule**. Aucun fichier n'a été modifié. Ce document est un constat + recommandations, pas un correctif.

---

## 1. Synthèse exécutive

La base de code est globalement **saine** : le hachage des mots de passe, la génération/stockage des clés API, PKCE OAuth, le cloisonnement multi-tenant (IDOR) et la régénération de session sont faits correctement. L'injection SQL est bien maîtrisée (requêtes paramétrées partout, explorateur SQL en lecture seule avec liste blanche de tables).

Les vrais problèmes sont concentrés sur **5 axes** :

1. **Secrets de déploiement** — une clé de chiffrement maître par défaut est committée en clair dans `docker-compose.yml`.
2. **SSRF dans le crawler Go** — la validation anti-SSRF existe mais **n'est pas appliquée** sur 3 chemins de fetch (rendu JS, sitemaps, anti-rebinding DNS). Un crawl peut atteindre les services internes (ClickHouse, Postgres, l'API, le métadata cloud `169.254.169.254`).
3. **Durcissement ClickHouse** — l'explorateur SQL repose **uniquement** sur une liste noire regex, sans utilisateur ClickHouse en lecture seule côté serveur (pas de filet de séculté équivalent au `READ ONLY` de Postgres).
4. **API web (session)** — pas de protection CSRF sur les endpoints JSON, pas de rate-limiting sur le login.
5. **Hygiène déploiement** — script demo `demo/demo` admin, `diagnostic.php` exposé sans auth, pas de lockfile npm pour le MCP.

### Tableau récapitulatif

| # | Sévérité | Zone | Problème |
|---|----------|------|----------|
| C1 | 🔴 CRITIQUE | Déploiement | Clé de chiffrement maître par défaut en dur (`SCOUTER_ENCRYPTION_KEY`) committée |
| C2 | 🔴 CRITIQUE | Crawler Go | SSRF non bloqué sur le rendu JavaScript → accès réseau interne + Chrome |
| C3 | 🔴 CRITIQUE | Crawler Go | SSRF non bloqué sur le fetch des sitemaps (`<loc>` contrôlé par le site crawlé) |
| H1 | 🟠 ÉLEVÉ | Hygiène | Script `create-demo-user.php` crée un admin `demo/demo` |
| H2 | 🟠 ÉLEVÉ | API web | Aucune protection CSRF sur l'API JSON authentifiée par cookie |
| H3 | 🟠 ÉLEVÉ | API web | Aucun rate-limiting / lockout sur le login |
| H4 | 🟠 ÉLEVÉ | Crawler Go | Anti-rebinding DNS mort (`ValidateIPString` jamais appelé) + TOCTOU |
| M1 | 🟡 MOYEN | ClickHouse | Lecture seule SQL garantie uniquement par liste noire regex (pas de user CH readonly) |
| M2 | 🟡 MOYEN | Web | `diagnostic.php` accessible sans authentification (divulgation d'infos) |
| M3 | 🟡 MOYEN | MCP | Pas de lockfile npm + `npm install` (build non reproductible / supply-chain) |
| M4 | 🟡 MOYEN | Renderer | Service de rendu non authentifié, Chrome en `--no-sandbox` |
| M5 | 🟡 MOYEN | Web | Cookie de session sans flag `Secure` derrière reverse-proxy |
| M6 | 🟡 MOYEN | OAuth | Issuer dérivé de headers `Host`/`X-Forwarded-Host` non validés |
| M7 | 🟡 MOYEN | Crawler Go | `SCOUTER_ALLOW_PRIVATE_IPS` désactive TOUT le filtrage SSRF globalement |
| L1-L8 | 🔵 FAIBLE | Divers | TLS non vérifié (par design crawler), erreurs DB renvoyées, MCP en root, headers manquants, etc. |

---

## 2. CRITIQUE

### C1 — Clé de chiffrement maître par défaut committée en clair
**Fichiers :** `docker-compose.yml:71` et `:128`

```yaml
- SCOUTER_ENCRYPTION_KEY=${SCOUTER_ENCRYPTION_KEY:-868ace624e48dc6da990d2512c380a9ec8b168e11c0fb4aff8393bb6a1d22809}
```

Cette clé de 32 octets est le **secret maître** qui chiffre (AES-256-GCM, voir `app/Settings/AppSettings.php`) les secrets stockés en base : clé API OpenRouter, et tout futur secret. Si un déploiement ne surcharge **pas** cette variable, tous les secrets chiffrés en base le sont sous une clé **publiquement connue** (présente dans le dépôt Git). N'importe qui ayant accès au dump de la base + au dépôt peut déchiffrer ces secrets.

> Note : le mécanisme de chiffrement lui-même (`AppSettings::encrypt/decrypt`) est **correct** — AES-256-GCM, IV aléatoire de 12 octets par message, tag d'authentification GCM, dérivation SHA-256 de la clé. Le seul défaut est la valeur **par défaut publique**.

**Correctif :** retirer la valeur par défaut, exiger la variable (échec au démarrage si absente — *fail closed*). Idem pour `POSTGRES_PASSWORD`/`CLICKHOUSE_PASSWORD` qui ont `CHANGEME_*` dans `.env.example` (acceptable car non committé en valeur réelle). Générer la clé à l'install (`openssl rand -hex 32`) et la documenter comme à sauvegarder (sa perte rend les secrets irrécupérables).

---

### C2 — SSRF non bloqué sur le chemin de rendu JavaScript
**Fichiers :** `crawler-go/internal/crawl/process.go:54` (`processJavascript`), `crawler-go/internal/crawl/renderer.go:134` (`postRenderBatch`)

Le crawler dispose d'une protection SSRF correcte (`analysis.ValidateURL` + `IsPrivateIP` dans `safehttp.go`), appliquée sur le chemin classique (`engine.go:172`). **Mais le chemin de rendu JavaScript ne l'appelle jamais** : les URLs (de configuration ou découvertes) sont envoyées directement au renderer, qui pilote Chrome headless vers **n'importe quelle URL**.

En mode `crawl_mode=javascript` (sélectionnable par l'utilisateur dans la config de crawl), c'est une primitive SSRF complète contre le réseau Docker interne :
- `http://169.254.169.254/latest/meta-data/` (métadonnées cloud / credentials IAM),
- `http://postgres:5432`, `http://clickhouse:8123`, `http://scouter:8080` (l'API elle-même),
- avec **exécution de JavaScript** dans un Chrome privilégié.

**Correctif :** appeler `analysis.ValidateURL` sur chaque URL dans `processJavascript` avant le batch (rejeter les échecs comme `fetchOne` le fait, code 0). Idéalement valider aussi côté renderer (voir M4).

---

### C3 — SSRF non bloqué sur le fetch des sitemaps
**Fichier :** `crawler-go/internal/analysis/sitemap.go:196` (`SitemapParser.fetch`)

`fetch()` fait `http.NewRequest`/`client.Do` **sans** `ValidateURL`. Or les URLs de sitemap viennent (a) de la config utilisateur (`advanced.sitemap_urls`) **et** (b) des balises `<loc>` contenues dans les fichiers sitemap-index récupérés (`sitemap.go:126-131`) — donc **entièrement contrôlées par le site crawlé** s'il est hostile. Un sitemap-index peut pointer un `<loc>` vers `http://169.254.169.254/...` et le parser ira le chercher (jusqu'à 50 sous-sitemaps, profondeur 2), les corps de réponse étant ensuite stockés/exposés → exfiltration possible.

**Correctif :** ajouter `analysis.ValidateURL(url)` en tête de `SitemapParser.fetch`, comme c'est déjà fait dans `robots.go:163`.

---

## 3. ÉLEVÉ

### H1 — Compte admin `demo / demo`
**Fichier :** `scripts/create-demo-user.php:23`

```php
$userId = $users->create('demo@scouter.local', 'demo', 'admin');
```

Crée un compte **admin** avec un mot de passe de 4 caractères publiquement connu (et qui contourne la règle `strlen < 6` appliquée ailleurs). `web/diagnostic.php:105` recommande même de lancer ce script. Si exécuté en production (ou par un bootstrap de conteneur), compromission totale immédiate.

**Correctif :** refuser de s'exécuter si `APP_ENV=production`, générer un mot de passe aléatoire affiché une seule fois, ne pas attribuer `admin` par défaut, et retirer la suggestion dans `diagnostic.php`.

### H2 — Absence de protection CSRF sur l'API JSON authentifiée par session
**Fichiers :** `web/api/index.php` (toutes les routes `['auth'=>true]`), `app/Http/Router.php:208-243`

Les endpoints qui modifient l'état — `POST /projects`, `DELETE /projects`, `POST /projects/{id}/share`, `PUT /users/{id}` (changement de rôle admin), `POST /crawls/start|stop|delete`, `POST /settings` — sont authentifiés **uniquement par le cookie de session**. Aucun token CSRF n'est vérifié. La seule barrière est `SameSite=Strict` sur le cookie (fragile, cf. M5). Combiné à H2, un CSRF contre un admin pourrait changer le rôle/mot de passe de n'importe quel utilisateur.

**Correctif :** exiger un token CSRF (ou un header custom type `X-Requested-With` vérifié côté serveur) sur toutes les routes non-GET en session.

> Note : l'API par **clé API** (`Bearer sctr_…`) n'est pas concernée par le CSRF (pas de credential ambiant) — c'est le chemin session/cookie qui est exposé.

### H3 — Aucun rate-limiting / lockout sur le login
**Fichiers :** `web/login.php:40-52`, `app/Auth/Auth.php:78-98`

`login()` exécute un `password_verify` par requête sans aucun compteur de tentatives, délai, lockout ou CAPTCHA. Brute-force / credential-stuffing en ligne illimité. (Le chemin clé API a bien un `RATE_PER_MIN`, mais pas le login.)

**Correctif :** throttling par IP + par compte (compteur APCu/Redis, comme `ApiKeyService::rateLimit`), backoff exponentiel, lockout après N échecs.

### H4 — Anti-rebinding DNS mort + TOCTOU
**Fichiers :** `crawler-go/internal/analysis/safehttp.go:51` (`ValidateIPString`, **0 appelant** en prod), `safehttp.go:25-47` (`ValidateURL`), `crawler-go/internal/crawl/utls.go:56`

`ValidateURL` résout le hostname et vérifie les IP, mais la connexion réelle (`dialTLS`/`client.Do`) refait une résolution DNS **indépendante** → fenêtre de DNS-rebinding (TOCTOU) : un serveur DNS hostile renvoie une IP publique à la validation, une IP privée (`169.254.169.254`) au dial. La fonction de contrôle post-redirection / IP finale (`ValidateIPString`, portage du `validateFinalIp` de la version PHP) existe mais **n'est jamais appelée**.

**Correctif :** résoudre une seule fois puis dialer l'IP validée (DialContext qui épingle l'IP vérifiée), ou revalider `conn.RemoteAddr()` via `ValidateIPString`. Critique surtout pour le chemin renderer/JS où Chrome suit les redirections en interne.

---

## 4. MOYEN

### M1 — Lecture seule SQL ClickHouse garantie uniquement par liste noire regex
**Fichiers :** `app/AI/ClickHouseSqlExecutor.php:134-238`, `docker-compose.yml:31`, `app/Database/ClickHouseDatabase.php:42`

L'explorateur SQL / l'agent IA / le `run_sql` MCP empêchent les écritures via un validateur multi-couches : SELECT/WITH uniquement, blocage multi-statement, liste noire de mots-clés (`INSERT/UPDATE/DELETE/DROP/ALTER/SYSTEM/SET…`), blocage des fonctions CH dangereuses (`file/url/remote/s3/mysql/postgresql/…`), blocage de `system.`/`information_schema.`, liste blanche de tables, et réécriture forcée en sous-requêtes scopées au `crawl_id` autorisé.

**Mais** : la connexion ClickHouse utilise l'utilisateur **complet** `scouter` (celui qui écrit/DROP les partitions), sans `readonly=1` ni rôle CH dédié. Contrairement au chemin Postgres (qui a un vrai `SET TRANSACTION READ ONLY` + `statement_timeout` comme filet, `SqlExecutor::runReadOnly`), **la prévention d'écriture côté CH repose entièrement sur la liste noire regex**. Tout contournement de cette regex (surface fonctionnelle CH énorme, évasion par commentaires/quoting) n'a aucune seconde ligne de défense.

**Correctif :** créer un utilisateur ClickHouse dédié en lecture seule (profil `readonly=1` ou `2`, `allow_ddl=0`, `allow_introspection_functions=0`) pour l'explorateur, et/ou ajouter `SETTINGS readonly = 1` dans `withSettings()`. La liste noire devient alors défense-en-profondeur au lieu d'être l'unique contrôle.

> **Bonne nouvelle multi-tenant :** le cloisonnement est correct. Chaque endpoint vérifie l'accès (`resolveAccessibleCrawl` → `requireProjectAccess`), toutes les tables sont réécrites en `crawl_id IN (crawl courant)`, les références cross-crawl `table@<id>` sont validées comme appartenant au **même projet**, et `crawl_categories` est scopé par projet avec rejet du shadow-CTE. Pas d'IDOR trouvé.

### M2 — `diagnostic.php` accessible sans authentification
**Fichier :** `web/diagnostic.php`

Contrairement à `index.php` (qui inclut `init.php` faisant la vérif d'auth), `diagnostic.php` **n'inclut aucun bootstrap d'authentification**. Il est directement exécutable via nginx (`location ~ \.php$`). Accessible publiquement à `/diagnostic.php`, il divulgue : version PHP + extensions, **état de connexion à la base**, existence des tables, **nombre d'admins**, droits d'écriture des répertoires — et peut créer des répertoires (`mkdir`). Reconnaissance précieuse pour un attaquant.

**Correctif :** ajouter une garde `php_sapi_name() === 'cli'` en tête (le rendre CLI-only), ou exiger une session admin, ou le bloquer dans nginx. Vérifier de même les autres `.php` du dossier `web/` racine (`dashboard.php`, `init.php`, etc.) : `init.php` redirige vers le login s'il est inclus, mais s'assurer qu'aucun fichier sensible n'est atteignable sans auth.

### M3 — MCP : pas de lockfile, build non reproductible (supply-chain)
**Fichiers :** `mcp/Dockerfile:7`, `mcp/.gitignore:3`

`package-lock.json` est ignoré (absent du dépôt) et le Dockerfile fait `npm install --omit=dev` avec des plages caret (`^1.12.0`, `^4.21.2`). Chaque build résout les dépendances transitives à frais nouveaux → builds non reproductibles + exposition à un patch malveillant de n'importe quelle dépendance transitive, sans épinglage ni vérification d'intégrité.

**Correctif :** committer `package-lock.json` (le retirer du `.gitignore`) et utiliser `RUN npm ci --omit=dev` (qui exige le lockfile et vérifie les hashes d'intégrité).

> Le serveur MCP lui-même est un **passthrough propre et minimal** : il ne détient aucun credential, transfère verbatim le `Authorization: Bearer sctr_…` à l'API, rejette les requêtes sans token (401), et **ne peut rien faire que la clé API de l'appelant ne puisse déjà faire**. La vraie validation (token, autorisation projet, lecture seule SQL) est dans l'API PHP et a été vérifiée. Aucun bypass d'auth, aucune injection, aucun secret committé dans `mcp/`.

### M4 — Renderer non authentifié, Chrome en `--no-sandbox`
**Fichier :** `renderer/main.go:433-439`, flags `:115-116`

`/render`, `/render-batch`, `/health` n'ont aucune authentification, aucune allowlist, aucun contrôle SSRF, et lancent Chrome avec `--no-sandbox --disable-setuid-sandbox`. Atténuation : le service est en `expose: 3000` (réseau Docker interne uniquement, **pas** publié sur l'hôte). L'exposition réelle vient de C2 (tout crawl JS peut le piloter). `--no-sandbox` signifie qu'un RCE du process renderer (page malveillante exploitant une 0-day Chrome) s'exécute sans la barrière sandbox.

**Correctif :** ajouter un secret partagé (header) sur les endpoints du renderer, valider les URLs côté renderer, et si possible retirer `--no-sandbox` (exécuter en non-root avec sandbox user-namespace).

### M5 — Cookie de session sans `Secure` derrière reverse-proxy
**Fichier :** `app/Auth/Auth.php:62-66`

```php
'secure' => isset($_SERVER['HTTPS']),
```

L'app tourne derrière un proxy (le code OAuth utilise `HTTP_X_FORWARDED_PROTO`). Dans cette topologie `$_SERVER['HTTPS']` est souvent absent → le cookie de session est envoyé **sans le flag `Secure`** même en HTTPS. De plus `isset()` est vrai même si `HTTPS='off'`.

**Correctif :** dériver `secure` de `($_SERVER['HTTPS'] ?? '') !== '' && !== 'off'` **ou** `($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'` ; idéalement forcer `secure => true` en production.

### M6 — Issuer OAuth dérivé de headers non validés
**Fichier :** `web/oauth.php:24-27`

```php
$host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
$issuer = $proto . '://' . $host;
```

L'`issuer` (utilisé pour construire toutes les URLs de découverte/endpoints) fait confiance à `X-Forwarded-Host`/`Host` non validés. Un header forgé peut faire annoncer par les métadonnées de découverte un endpoint d'autorisation/token contrôlé par l'attaquant → empoisonnement de métadonnées / phishing. Impact borné car le `redirect_uri` réel reste en liste blanche stricte (vérifié, `OAuthServer::redirectUriAllowed`, `in_array(..., true)`).

**Correctif :** épingler l'issuer/host sur une allowlist configurée (variable d'env) plutôt que sur les headers de requête.

### M7 — `SCOUTER_ALLOW_PRIVATE_IPS` : interrupteur global tout-ou-rien
**Fichiers :** `crawler-go/internal/analysis/safehttp.go:18-21`, `app/Util/SafeHttp.php:183`

Quand `SCOUTER_ALLOW_PRIVATE_IPS=true/1`, **toutes** les vérifications SSRF sont sautées pour tout le process. Sur un déploiement partagé/multi-tenant, activer ce toggle transforme chaque crawl en vecteur SSRF. Footgun de configuration plutôt que bug.

**Correctif :** documenter fortement le risque ; envisager une allowlist de CIDR privés explicites au lieu d'un bypass global.

---

## 5. FAIBLE / INFORMATIF

- **L1 — TLS non vérifié dans le crawler.** `crawler-go/internal/crawl/utls.go:52` : `InsecureSkipVerify: true`. Intentionnel (un crawler récupère ce qui est servi, comme l'ancien `CURLOPT_SSL_VERIFYPEER=false`). Risque accepté, mais : MITM indétectable, et les credentials HTTP basic-auth de crawl (`engine.go:187`) sont envoyés sur une connexion sans vérification de certificat (**L2**).
- **L3 — Erreurs DB/API renvoyées verbatim** à l'appelant (`ClickHouseSqlExecutor.php:84,117`, `mcp/server.js:122`). Divulgation d'infos faible (l'appelant est déjà authentifié sur ses propres données), mais à assainir.
- **L4 — Conteneur MCP en root.** `mcp/Dockerfile` ne fait pas `USER node`. Ajouter le drop de privilèges.
- **L5 — Headers de sécurité incomplets.** `docker/nginx.conf` a `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, mais **pas de CSP ni HSTS** ; `X-XSS-Protection` est déprécié. Ajouter `Content-Security-Policy` et `Strict-Transport-Security`.
- **L6 — `/oauth/register` sans rate-limiting** (`web/oauth.php:77-84`) : n'importe qui peut créer en masse des `oauth_clients` (DoS par remplissage de table). Limiter par IP, plafonner, purger les clients inutilisés.
- **L7 — Reflet de `X-Forwarded-Host` dans le `WWW-Authenticate` MCP** (`mcp/server.js:93-106`) : n'affecte que la réponse de découverte non authentifiée. Épingler l'origine via env.
- **L8 — Minimum mot de passe à 6 caractères** pour les admins (`web/login.php:68`, `UserController::create/update`). Relever à 12+.

### Points vérifiés et CORRECTS (pas d'action)
- Hachage mots de passe : `password_hash(PASSWORD_DEFAULT)` + `password_verify` (pas de md5/sha1).
- Clés API : `random_bytes(32)` (CSPRNG 256 bits), stockées en SHA-256, comparées via `hash_equals`, retournées en clair une seule fois.
- OAuth : PKCE S256 imposé, `redirect_uri` en liste blanche stricte exacte, `state` propagé, code TTL 300s usage unique, `hash_equals`.
- Session : `session_regenerate_id(true)` au login (anti-fixation), purge complète au logout, `HttpOnly` + `SameSite=Strict`.
- Cloisonnement multi-tenant (IDOR) : ressources crawl/projet scopées au propriétaire sur tous les chemins (API v1, contrôleurs session, explorateur SQL). Pas d'IDOR trouvé.
- Injection SQL : requêtes paramétrées partout (PHP + Go), seuls des entiers (`crawl_id`/`project_id`) ou des noms de colonnes issus d'allowlists sont interpolés.
- Chiffrement des secrets : AES-256-GCM correct (IV aléatoire, tag GCM).
- Injection de commande : aucune (pas d'`exec`/shell avec données de crawl ni dans le Go ni dans le renderer).
- Path traversal : `filepath.Base()` neutralise le nom de répertoire projet pour les logs.
- Bombes de décompression : `io.LimitReader` borne la sortie décompressée (16 Mo crawl, 50 Mo sitemap).
- ClickHouse / Postgres / renderer / MCP : tous en `expose` (réseau Docker interne), non publiés sur l'hôte en production.

---

## 6. Plan d'action priorisé

### À faire avant toute mise en production (bloquant)
1. **C1** — Retirer la clé `SCOUTER_ENCRYPTION_KEY` par défaut ; exiger la variable (fail closed). Régénérer toute clé déjà déployée sous la valeur par défaut + re-chiffrer les secrets.
2. **C2 + C3** — Câbler `analysis.ValidateURL` sur `processJavascript` (rendu JS) et `SitemapParser.fetch` (sitemaps).
3. **H1** — Neutraliser / sécuriser `create-demo-user.php` (pas d'admin `demo/demo` en prod).
4. **M2** — Rendre `diagnostic.php` CLI-only ou le protéger par auth.

### Court terme (sécurité importante)
5. **H4** — Épingler l'IP résolue au dial + revalider l'IP finale (anti-rebinding DNS).
6. **M1** — Utilisateur ClickHouse dédié en lecture seule pour l'explorateur SQL.
7. **H2** — Protection CSRF sur l'API JSON en session.
8. **H3** — Rate-limiting / lockout sur le login.
9. **M4** — Authentifier le renderer (secret partagé) + valider les URLs côté renderer.

### Durcissement (moyen terme)
10. **M5** — Flag `Secure` du cookie correct derrière proxy.
11. **M6 / L7** — Épingler l'issuer/host OAuth+MCP sur une allowlist d'env.
12. **M3** — Lockfile npm + `npm ci` pour le MCP.
13. **M7** — Documenter / restreindre `SCOUTER_ALLOW_PRIVATE_IPS`.
14. **L3–L8** — Assainir les erreurs renvoyées, `USER node` MCP, CSP/HSTS nginx, rate-limit `/oauth/register`, minimum mot de passe 12+.

---

*Fin de l'audit. Aucun fichier de l'application n'a été modifié ; seul ce rapport a été créé.*
