# Audit de sécurité Scouter

> Date : 2026-05-17
> Branche : `feat/link-position`
> Périmètre : application complète (PHP + Postgres + Docker + frontend)

## Résumé exécutif

Scouter présente une base de sécurité **honnête mais inégale**. Les bons réflexes sont là (PDO préparé partout, `password_hash`, `session_regenerate_id`, cookies `SameSite=Strict` + `HttpOnly`, sandbox CSP sur preview HTML, blocklist défensive du SQL Explorer, ownership checks systématiques sur les controllers). Le code est mieux protégé contre l'injection SQL classique et le XSS que la moyenne des apps PHP custom de cette taille.

En revanche, **trois vulnérabilités sérieuses** existent : (1) **absence totale de protection CSRF sur l'API** (seul filet : `SameSite=Strict`, sans defense-in-depth), (2) **bypass trivial du blocklist du SQL Explorer** — un user peut lire les credentials HTTP Basic Auth d'autres projets via `SELECT config FROM crawls`, et (3) **SSRF non mitigée** dans le crawler et `fetchSitemaps` (pas de blocage des IPs privées/loopback/AWS metadata).

## Note globale : **6.5 / 10**

Bon code pour un projet self-hosted custom, mais les 3 issues ci-dessus doivent être traitées avant un déploiement public multi-tenant.

---

## 🔴 CRITIQUE

### C1 — Bypass du blocklist SQL Explorer : lecture de secrets cross-projet

**Fichier** : `app/Http/Controllers/QueryController.php:113-122`

La blocklist actuelle (`pg_catalog|information_schema|pg_roles|pg_authid|pg_shadow|users`) bloque la table `users` et certaines vues système, mais **laisse passer toutes les tables applicatives sensibles** : `crawls`, `jobs`, `job_logs`, `project_shares`, `projects`, `crawl_schedules`, `user_saved_queries`.

**Exploitation concrète** :

```sql
SELECT config FROM crawls LIMIT 1000
```

La colonne `config` (JSONB) contient `http_auth.username` + `http_auth.password` **en clair** (cf `ProjectController.php:249`). Un user authentifié peut donc lire les credentials HTTP Basic Auth configurés par n'importe quel autre user pour crawler ses sites privés. Idem pour `custom_headers` qui peut contenir des tokens API.

Autres requêtes triviales :
```sql
SELECT * FROM user_saved_queries  -- requêtes SQL de tous les autres users
SELECT * FROM jobs                -- toute l'historique de jobs du système
SELECT * FROM crawl_schedules     -- configs de crawls programmés
```

**Fix recommandé** : passer d'une blocklist à une **whitelist** explicite des tables/vues autorisées (`pages`, `links`, `crawl_categories`, `page_schemas`, `duplicate_clusters`, `redirect_chains` + leurs partitions). Bloquer aussi `pg_*` complètement. Idéal : créer un rôle PostgreSQL séparé avec `GRANT SELECT` minimal pour la connexion utilisée par le SQL Explorer.

---

## 🟠 ÉLEVÉ

### H1 — Aucune protection CSRF sur l'API

**Fichiers** : `web/api/index.php` (toutes les routes), `app/Http/Router.php:193-206`

Le seul endroit où un token CSRF est validé est `web/login.php:34`. Toutes les routes API (POST/PUT/DELETE) ne vérifient **ni** token CSRF, **ni** header `Origin`/`Referer`, **ni** header custom. La seule défense est `SameSite=Strict` sur le cookie de session (`app/Auth/Auth.php:65`).

**Risque** : `SameSite=Strict` est solide dans les browsers modernes, mais (a) les anciens browsers ne le respectent pas tous, (b) un XSS sur un sous-domaine same-site peut fetch l'API avec cookies, (c) défense en profondeur manquante.

**Exploitations possibles** : `POST /api/projects`, `POST /api/crawls/start`, `DELETE /api/users/{id}` (sur un admin victime), `POST /api/projects/{id}/share`.

**Fix recommandé** : middleware dans `Router::applyAuth()` qui exige un header `X-CSRF-Token` validé contre `$_SESSION['csrf_token']` pour toute requête mutante (POST/PUT/DELETE). Token injecté dans le HTML rendu, lu côté JS.

---

### H2 — SSRF non mitigée (crawler + fetchSitemaps + JsRenderer)

**Fichiers** :
- `app/Http/Controllers/CrawlController.php:352-401` (`fetchSitemaps`)
- `app/Core/DepthCrawler.php:101-119` (options curl)
- `app/Util/JsRenderer.php:50-100` (forward d'URL vers Puppeteer)
- `app/Analysis/RobotsTxt.php:41-64`

Aucun de ces points n'applique :
- **Filtrage d'IPs internes** : `127.0.0.0/8`, `10/8`, `172.16/12`, `192.168/16`, `169.254/16` (AWS metadata), `::1`, IPv6 link-local
- **Restriction de protocoles** : `CURLOPT_PROTOCOLS` / `CURLOPT_REDIR_PROTOCOLS` non définis → `file://`, `gopher://`, `dict://` accessibles selon la build libcurl
- **Résolution DNS de validation** avant le fetch → bypass via DNS rebinding

**Exploitation concrète** :

Un user avec droit `canCreate` (ou même simple authentifié pour `fetchSitemaps`) lance un crawl sur :
- `http://127.0.0.1:5432/` → scan du Postgres
- `http://postgres:5432/` → idem via service Docker
- `http://169.254.169.254/latest/meta-data/iam/security-credentials/` → vol de credentials AWS IAM
- `http://localhost:8080/api/users` → l'app elle-même (contournement auth potentiel)

`fetchSitemaps` est le plus simple à exploiter : GET, auth utilisateur basique, `CURLOPT_FOLLOWLOCATION=true`, donc une URL `https://attacker.com` qui renvoie `302 → http://169.254.169.254/...` fait fuir les credentials.

**Fix recommandé** : créer un helper `SafeHttp` qui :
1. Résout le hostname côté PHP
2. Refuse toute IP privée/loopback/link-local/multicast
3. Restreint à `http(s)://` via `CURLOPT_PROTOCOLS`
4. Désactive `CURLOPT_FOLLOWLOCATION` OU valide manuellement chaque redirect

Le réutiliser partout (`fetchSitemaps`, `DepthCrawler`, `JsRenderer`, `RobotsTxt`).

---

### H3 — `MonitorController::launchTestCrawls` : DoS amplifié

**Fichier** : `app/Http/Controllers/MonitorController.php:257-330`

Endpoint réservé admin (OK), mais sans CSRF (cf H1) un admin peut être tricked en lançant 20 crawls vers n'importe quelle URL fournie par l'attaquant. Combiné à H1 + H2, c'est un vecteur SSRF amplifié.

**Fix** : appliquer le CSRF de H1, valider l'URL avec le helper SafeHttp de H2.

---

## 🟡 MOYEN

### M1 — Validation laxiste du `redirect_to` au login

**Fichier** : `web/login.php:19-22`

```php
$redirect = (!empty($redirectRaw) && !preg_match('#^https?://#i', $redirectRaw) && !str_starts_with($redirectRaw, '//'))
    ? $redirectRaw : '';
```

Bloque `http://` / `//` mais autorise des chemins exotiques (`/\evil.com`, `\\evil.com`). Impact réel limité (le `Location:` HTTP ne déclenche pas de JS), mais autant tightener.

**Fix** : `$redirect = (preg_match('#^/[^/\\\\]#', $redirectRaw)) ? $redirectRaw : '';`

---

### M2 — Pas de rate-limiting / lockout sur `/login`

**Fichier** : `web/login.php`

Aucun throttling. Brute-force sur `password_hash` est lent (bcrypt) mais possible si l'app est exposée publiquement.

**Fix** : table `login_attempts(ip, email, ts)` + délai exponentiel ou lockout après N échecs.

---

### M3 — Politique de mot de passe trop faible

**Fichiers** : `web/login.php:68`, `app/Http/Controllers/UserController.php:83,147,250`

Minimum 6 caractères, aucune contrainte de complexité. OK self-hosted, insuffisant en déploiement public.

**Fix** : minimum 10-12 caractères.

---

### M4 — XSS stocké théorique dans Monitor preview

**Fichier** : `app/Http/Controllers/MonitorController.php:340-355`

`{$pageUrl}` interpolé tel quel dans du HTML (attribut `href` et texte). URLs malformées contenant `"`/`>` → injection HTML. **Mitigé** par la CSP `sandbox` ligne 117 qui empêche l'exécution JS, mais HTML-injection (form vers attaquant) reste possible théoriquement.

**Fix** : `htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8')` dans `generateInfoBar`.

---

## 🟢 FAIBLE / INFO

- **F1** — `CURLOPT_SSL_VERIFYPEER => false` dans le crawler. Acceptable pour un crawler (sites avec certs cassés), à documenter.
- **F2** — `error_log()` dans `Router::executeHandler` log la stack trace complète. Aucune exposition au client (message générique côté HTTP), mais attention aux logs côté ops.
- **F3** — `docker-compose.local.yml` expose Postgres sur `0.0.0.0:5432` avec mdp `scouter:scouter`. Dev-only mais ne pas confondre avec prod.
- **F4** — `Dockerfile:64` : `chown www-data` sur tout `/app` → l'app PHP peut écrire dans son propre code. Théorique RCE-persist si autre bug. Préférer code en read-only.
- **F5** — `exec("kill -9 " . intval($job->pid))` : `intval` protège correctement, OK.
- **F6** — `Spyc::YAMLLoadString` (CategorizationService) : parseur pur PHP sans tags custom, pas de désérialisation dangereuse (pas comme PyYAML).
- **F7** — Header `Strict-Transport-Security` absent dans nginx.conf. À ajouter pour déploiement HTTPS public.

---

## ✅ Non-issues (vérifiées, OK)

- **Injection SQL classique** : tous les `*Repository.php` + controllers utilisent `prepare()` + placeholders nommés. `ExportController::buildFilterConditions` whiteliste les colonnes via regex (l. 280). RAS.
- **SQL Explorer — modification de données** : `SET TRANSACTION READ ONLY` + `statement_timeout=10s` + blocklist mots-clés + blocklist fonctions (`pg_sleep`, `pg_read_file`, `dblink`...). Solide contre `INSERT/UPDATE/DELETE/COPY TO PROGRAM`. Seul vrai trou = C1 (lecture cross-table).
- **Hash de mot de passe** : `password_hash` avec `PASSWORD_DEFAULT` (bcrypt) + `password_verify`. RAS.
- **Session fixation** : `session_regenerate_id(true)` après login (`Auth.php:91`). RAS.
- **Path traversal sur logs** : `basename($projectDir)` dans `JobController.php:125` et `CrawlController.php:226`. RAS.
- **Authorization** : tous les controllers appellent `requireCrawlAccess*`, `requireProjectManagement`, `requireAdmin` avant les opérations sensibles. Les ownership checks dans `SavedQueryController::update/delete` sont stricts. `ProjectController::saveSchedule` valide le `template_crawl_id` appartient au projet (l. 717). RAS.
- **XSS dans templates** : `htmlspecialchars` partout dans `admin.php` et autres pages. Les `<?=` non échappés sont tous des constantes i18n ou des champs typés (id int, role validé). RAS.
- **Validation `start_url`** : `filter_var(FILTER_VALIDATE_URL)` + `parse_url` dans `ProjectController.php:193-201`. OK contre URLs malformées (mais ne protège pas du SSRF — cf H2).
- **Cookies session** : `HttpOnly` + `SameSite=Strict` + `Secure` conditionnel sur HTTPS.
- **Désérialisation** : aucun `unserialize` sur input utilisateur. RAS.

---

## Recommandations par ordre de priorité

1. **C1** — whitelister les tables/vues dans `QueryController::execute` *(URGENT : fuite de credentials cross-tenant possible avec un user simple)*
2. **H1** — middleware CSRF token dans `Router::applyAuth`
3. **H2** — helper `SafeHttp` à utiliser dans `fetchSitemaps`, `DepthCrawler`, `JsRenderer`, `RobotsTxt`
4. **M2/M3** — rate-limit login + politique mot de passe
5. **M4** — `htmlspecialchars` dans `generateInfoBar`
