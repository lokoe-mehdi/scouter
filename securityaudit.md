# Audit de Securite -- Scouter

**Date :** 28 mars 2026
**Auditeur :** Claude (analyse statique du code source)
**Perimetre :** Code source complet (PHP, JS, Docker, Nginx, SQL)
**Methodologie :** Revue manuelle du code, analyse OWASP Top 10, analyse de configuration

---

## Note Globale de Securite

### 3.5 / 10 -- Securite INSUFFISANTE

| Aspect                        | Evaluation |
|-------------------------------|------------|
| Authentification              | Moyenne -- bcrypt utilise, mais politique de mots de passe faible, pas de rate limiting |
| Gestion des sessions          | Correcte -- SameSite=Strict, HttpOnly, mais flag `secure` conditionnel |
| Protection CSRF               | Faible -- Presente sur le login uniquement, absente sur toute l'API |
| Protection XSS                | Critique -- Multiples vecteurs XSS stockes via les donnees crawlees |
| Injection SQL                 | Critique -- SQL Explorer execute du SQL brut avec un blocklist bypassable |
| Controle d'acces              | Moyen -- Systeme de roles present mais incoherent sur certains endpoints |
| Configuration serveur         | Moyenne -- Headers de securite partiels, pas de CSP |
| Gestion des secrets           | Faible -- Credentials hardcodes, mots de passe en clair dans le HTML |
| Protection SSRF               | Absente -- Aucune validation des URLs internes/privees |

**Points positifs :**
- Utilisation de `password_hash()` / `password_verify()` avec bcrypt
- Requetes parametrees (PDO) dans la majorite du code
- Cookie de session avec `HttpOnly`, `SameSite=Strict`
- `X-Frame-Options`, `X-Content-Type-Options` configures dans Nginx
- Validation `filter_var(FILTER_VALIDATE_URL)` sur les URLs de crawl
- Pas de port PostgreSQL expose sur l'hote dans docker-compose.yml

**Points critiques :**
- Le SQL Explorer est une porte ouverte a l'exfiltration de donnees
- Les donnees crawlees (URLs, ancres, headings, schemas) sont injectees en HTML sans echappement
- Aucune protection CSRF sur les endpoints API
- Pas de Content-Security-Policy

---

## Vulnerabilites Identifiees

### CRITIQUE (4 vulnerabilites)

---

#### C1 -- Injection SQL via le SQL Explorer

| | |
|---|---|
| **Fichier** | `app/Http/Controllers/QueryController.php` (lignes 75-166) |
| **Type** | SQL Injection / Exfiltration de donnees |
| **OWASP** | A03:2021 -- Injection |

**Description :**
La methode `execute()` permet aux utilisateurs authentifies d'envoyer des requetes SQL arbitraires, filtrees uniquement par une approche **blocklist** (liste de mots-cles interdits). Cette approche est fondamentalement non-securisee :

- La requete utilisateur est passee directement a `$this->db->query($transformedQuery)` (ligne 166) sans parametrisation.
- Le blocklist peut etre contourne via : fonctions PostgreSQL non couvertes (`pg_read_file` via alias, `COPY ... TO STDOUT`), concatenation de chaines en SQL (`SE`||`LECT`), fonctions `CHR()`, dollar-quoting (`$$`), sous-requetes, etc.
- Les tables sensibles non bloquees sont accessibles : `jobs`, `crawls` (contient des configs avec credentials HTTP), `crawl_schedules`, `project_shares`, `projects`.

**Correction recommandee :**
1. Utiliser un **utilisateur PostgreSQL dedie en lecture seule** avec des droits limites aux tables de donnees de crawl uniquement.
2. Envelopper la requete dans un bloc transactionnel explicite : `BEGIN; SET TRANSACTION READ ONLY; ... COMMIT;`.
3. Remplacer le blocklist par un parser SQL ou un allowlist strict.

---

#### C2 -- Transaction READ ONLY non appliquee

| | |
|---|---|
| **Fichier** | `app/Http/Controllers/QueryController.php` (lignes 164-166) |
| **Type** | Contournement de controle d'acces |
| **OWASP** | A01:2021 -- Broken Access Control |

**Description :**
Le code execute `SET TRANSACTION READ ONLY` puis `$this->db->query()` comme des statements separes. Avec le mode autocommit de PDO (actif par defaut), chaque statement tourne dans sa propre transaction implicite. Le `READ ONLY` s'applique a la transaction courante, mais la requete utilisateur s'execute dans une **nouvelle** transaction auto-committee. La contrainte lecture seule n'est donc **pas garantie**.

**Correction recommandee :**
Appeler `$this->db->beginTransaction()` avant `SET TRANSACTION READ ONLY`, executer la requete dans cette transaction, puis `$this->db->rollBack()`.

---

#### C3 -- XSS stocke via les donnees crawlees (url-details-modal)

| | |
|---|---|
| **Fichier** | `web/components/url-details-modal.php` (multiples lignes) |
| **Type** | Stored XSS |
| **OWASP** | A03:2021 -- Injection |

**Description :**
Le modal de details d'URL injecte des donnees crawlees directement dans le HTML via des template literals JavaScript **sans echappement** :

| Donnee | Ligne | Contexte |
|--------|-------|----------|
| `link.url` | 1349-1358 | Contenu HTML + attribut `data-url` |
| `link.anchor` | 1358 | Contenu HTML |
| `heading.text` | 1164 | Contenu HTML |
| `extract.label` / `extract.value` | 1281-1286 | Contenu HTML |
| `url.redirect_to` | 996-997 | Contenu HTML via innerHTML |
| `url.schemas[]` | 1218-1219 | Attribut href + contenu HTML |

Un attaquant peut creer un site avec des URLs, ancres, headings ou schemas malveillants. Quand un utilisateur Scouter crawle ce site et consulte les resultats dans le modal, le JavaScript de l'attaquant s'execute dans la session authentifiee, pouvant mener a une **prise de controle totale du compte**.

**Correction recommandee :**
Creer une fonction `escapeHtml()` en JavaScript et l'appliquer systematiquement a toute donnee provenant du crawl avant insertion dans le DOM. Exemple :
```javascript
function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
```

---

#### C4 -- Absence de protection CSRF sur l'API

| | |
|---|---|
| **Fichier** | `web/api/index.php` (toutes les routes POST/PUT/DELETE) |
| **Type** | Cross-Site Request Forgery |
| **OWASP** | A01:2021 -- Broken Access Control |

**Description :**
Le formulaire de login valide correctement un token CSRF, mais **aucun endpoint de l'API REST** ne verifie de token CSRF. Tous les endpoints a effet de bord (demarrer/arreter/supprimer des crawls, creer des utilisateurs admin, executer du SQL, supprimer des projets) reposent uniquement sur le cookie de session.

Le cookie `SameSite=Strict` offre une protection dans les navigateurs modernes, mais les navigateurs anciens ne supportant pas `SameSite` restent vulnerables.

**Correction recommandee :**
1. Generer un token CSRF en session et l'envoyer dans un header custom (`X-CSRF-Token`) pour chaque requete API.
2. Alternative : exiger un header custom (ex: `X-Requested-With: XMLHttpRequest`) qui declenche un preflight CORS.

---

### HAUTE (7 vulnerabilites)

---

#### H1 -- Open Redirect avec contournement possible

| | |
|---|---|
| **Fichier** | `web/login.php` (lignes 19-22) |
| **Type** | Open Redirect / Header Injection |
| **OWASP** | A01:2021 -- Broken Access Control |

**Description :**
Le filtre de redirection bloque `http://`, `https://` et `//`, mais ne bloque pas :
- `javascript:` URIs
- `data:` URIs
- `\/\/evil.com` (normalise en `//evil.com` par certains navigateurs)
- Caracteres `\r` / `\n` (HTTP header injection / response splitting)

**Correction :**
Valider que `$redirect` commence par `/` (un seul slash), ne contient pas `://`, `\r`, `\n`, et verifier avec `parse_url()` qu'aucun scheme n'est present.

---

#### H2 -- Tables sensibles accessibles via le SQL Explorer

| | |
|---|---|
| **Fichier** | `app/Http/Controllers/QueryController.php` (lignes 113-117) |
| **Type** | Broken Access Control / Information Disclosure |
| **OWASP** | A01:2021 -- Broken Access Control |

**Description :**
Le blocklist ne couvre que `users`, `pg_catalog`, `information_schema`, `pg_roles`, `pg_authid`, `pg_shadow`. Les tables applicatives sensibles sont totalement accessibles :
- `crawls` -- contient les configs de crawl avec potentiellement des **credentials HTTP auth en clair**
- `jobs` -- contient les PIDs, chemins de projets
- `project_shares` -- expose les partages entre utilisateurs
- `crawl_schedules` -- contient les configs de planification

**Correction :**
Utiliser un utilisateur PostgreSQL dedie avec des `GRANT SELECT` uniquement sur les tables partitionnees de donnees de crawl.

---

#### H3 -- XSS stocke via le preview HTML (MonitorController)

| | |
|---|---|
| **Fichier** | `app/Http/Controllers/MonitorController.php` (lignes 102-117) |
| **Type** | Stored XSS |
| **OWASP** | A03:2021 -- Injection |

**Description :**
La methode `preview()` sert du HTML crawle directement au navigateur en `text/html`. Le header CSP `sandbox` est applique, mais inclut `allow-same-origin`, ce qui signifie que la page partage la meme origine que l'application principale. Des mecanismes non-JS (exfiltration CSS, `<meta http-equiv="refresh">`) restent possibles.

**Correction :**
Retirer `allow-same-origin` du sandbox, ou servir le preview depuis un domaine/origine distinct.

---

#### H4 -- Headers de securite Nginx perdus sur les fichiers statiques

| | |
|---|---|
| **Fichier** | `docker/nginx.conf` (lignes 49-52) |
| **Type** | Security Misconfiguration |
| **OWASP** | A05:2021 -- Security Misconfiguration |

**Description :**
Le bloc `location` pour les fichiers statiques utilise `add_header Cache-Control`. Dans Nginx, `add_header` dans un bloc enfant **remplace** (et n'herite pas) les directives `add_header` du bloc parent. Les headers `X-Frame-Options`, `X-Content-Type-Options`, `X-XSS-Protection`, `Referrer-Policy` ne sont donc **pas appliques** aux fichiers CSS, JS, images.

**Correction :**
Repeter les headers de securite dans le bloc static, ou utiliser le module `ngx_http_headers_more` avec `more_set_headers`.

---

#### H5 -- Absence de Content-Security-Policy

| | |
|---|---|
| **Fichier** | `docker/nginx.conf` |
| **Type** | Missing Security Header |
| **OWASP** | A05:2021 -- Security Misconfiguration |

**Description :**
Aucun header `Content-Security-Policy` n'est defini pour l'application principale. Sans CSP, toute vulnerabilite XSS devient trivialement exploitable avec des scripts inline, chargement de scripts externes, etc.

**Correction :**
Ajouter au minimum : `Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:;`

---

#### H6 -- Credentials hardcodes dans les fichiers commites

| | |
|---|---|
| **Fichiers** | `docker-compose.local.yml` (lignes 11, 32, 49), `scripts/create-demo-user.php` (ligne 23), `phpunit.xml` (ligne 17) |
| **Type** | Sensitive Data Exposure |
| **OWASP** | A02:2021 -- Cryptographic Failures |

**Description :**
- `docker-compose.local.yml` : `POSTGRES_PASSWORD=scouter` en dur
- `create-demo-user.php` : cree un admin avec `demo@scouter.local` / `demo`
- `phpunit.xml` : `postgresql://scouter:scouter@localhost:5432/scouter`

**Correction :**
Utiliser des references `.env` partout. Le script demo doit exiger les credentials en arguments CLI. Utiliser des credentials de test evidemment faux (`test_user:test_pass_not_for_prod`).

---

#### H7 -- Mot de passe HTTP Auth expose dans le HTML source

| | |
|---|---|
| **Fichier** | `web/pages/config.php` (ligne 385) |
| **Type** | Sensitive Data Exposure |
| **OWASP** | A02:2021 -- Cryptographic Failures |

**Description :**
Le mot de passe HTTP Auth pour le crawl est embarque dans un attribut `data-password` du HTML. Visuellement masque par des points, le mot de passe est visible dans le code source de la page et le DOM. Toute extension navigateur ou vulnerabilite XSS peut le lire.

**Correction :**
Ne jamais embarquer le mot de passe dans le HTML. Le recuperer via un appel AJAX authentifie uniquement quand l'utilisateur clique sur "afficher".

---

### MOYENNE (9 vulnerabilites)

---

#### M1 -- SSRF via les URLs de crawl

| | |
|---|---|
| **Fichier** | `app/Http/Controllers/ProjectController.php` (lignes 142-208) |
| **Type** | Server-Side Request Forgery |
| **OWASP** | A10:2021 -- SSRF |

**Description :**
`filter_var(FILTER_VALIDATE_URL)` autorise les URLs internes : `http://localhost`, `http://127.0.0.1`, `http://169.254.169.254` (metadata AWS), `http://postgres:5432` (hostname Docker interne). En mode liste, aucune validation d'URL n'est effectuee au-dela du prefixe `http(s)://`.

**Correction :**
Valider que les URLs resolvent vers des adresses IP non-privees. Bloquer les plages RFC 1918, loopback, link-local et les endpoints metadata cloud.

---

#### M2 -- Flag `secure` du cookie de session conditionnel

| | |
|---|---|
| **Fichier** | `app/Auth/Auth.php` (ligne 63) |
| **Type** | Insecure Cookie Configuration |
| **OWASP** | A02:2021 -- Cryptographic Failures |

**Description :**
`'secure' => isset($_SERVER['HTTPS'])` -- derriere un reverse proxy (nginx/Docker) qui termine TLS, `$_SERVER['HTTPS']` n'est generalement pas defini. Le cookie sera envoye en HTTP non-chiffre.

**Correction :**
Verifier aussi `$_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'`, ou forcer `secure => true` derriere un proxy TLS connu.

---

#### M3 -- Pas de rate limiting sur le login

| | |
|---|---|
| **Fichier** | `web/login.php` (lignes 40-53) |
| **Type** | Brute Force |
| **OWASP** | A07:2021 -- Identification and Authentication Failures |

**Description :**
Aucun rate limiting, verrouillage de compte ou CAPTCHA sur le formulaire de login. Un attaquant peut tester un nombre illimite de mots de passe.

**Correction :**
Implementer un rate limiting (ex: max 5 tentatives par IP par minute), verrouillage temporaire apres N echecs.

---

#### M4 -- Politique de mots de passe faible

| | |
|---|---|
| **Fichier** | `web/login.php` (ligne 69) |
| **Type** | Weak Authentication |
| **OWASP** | A07:2021 -- Identification and Authentication Failures |

**Description :**
Seule exigence : `strlen($password) < 6`. Pas de complexite requise. 6 caracteres est tres faible.

**Correction :**
Exiger minimum 8-12 caracteres avec des regles de complexite ou verification contre une liste de mots de passe compromis.

---

#### M5 -- XSS dans la barre d'info du monitor

| | |
|---|---|
| **Fichier** | `app/Http/Controllers/MonitorController.php` (lignes 340-352) |
| **Type** | Stored XSS |
| **OWASP** | A03:2021 -- Injection |

**Description :**
`$pageUrl` et `$dateFormatted` sont interpoles directement dans le HTML de `generateInfoBar()` sans echappement.

**Correction :**
Appliquer `htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8')`.

---

#### M6 -- Injection de noms de colonnes dans les exports

| | |
|---|---|
| **Fichier** | `app/Http/Controllers/ExportController.php` (lignes 74, 279-316) |
| **Type** | SQL Injection (limitee) |
| **OWASP** | A03:2021 -- Injection |

**Description :**
La regex `/^[a-z_][a-z0-9_]*$/i` autorise n'importe quel nom ressemblant a une colonne, pas uniquement les colonnes reelles de la table `pages`. Les `$selectedColumns` du JSON utilisateur ne sont pas validees du tout.

**Correction :**
Valider `$field` et `$selectedColumns` contre une allowlist explicite des colonnes connues.

---

#### M7 -- Endpoint de test crawls sans controle d'autorisation

| | |
|---|---|
| **Fichier** | `app/Http/Controllers/MonitorController.php` (lignes 255-328) |
| **Type** | Broken Access Control |
| **OWASP** | A01:2021 -- Broken Access Control |

**Description :**
`launchTestCrawls()` permet a tout utilisateur authentifie de lancer jusqu'a 20 crawls de test contre n'importe quelle URL. Aucune verification du role admin.

**Correction :**
Restreindre cet endpoint aux administrateurs uniquement, ou le supprimer en production.

---

#### M8 -- Credentials HTTP Auth stockes en clair en base

| | |
|---|---|
| **Fichier** | `app/Http/Controllers/ProjectController.php` (ligne 248) |
| **Type** | Sensitive Data Exposure |
| **OWASP** | A02:2021 -- Cryptographic Failures |

**Description :**
Les credentials `http_auth` sont stockes en clair dans le JSON de configuration en base. Ils sont accessibles via le SQL Explorer (voir C1/H2) et copies tels quels par la fonction `duplicate()`.

**Correction :**
Chiffrer les champs sensibles avant stockage. Au minimum, empecher l'acces via le SQL Explorer.

---

#### M9 -- Contournement du blocage multi-statements

| | |
|---|---|
| **Fichier** | `app/Http/Controllers/QueryController.php` (lignes 85-87) |
| **Type** | SQL Injection |
| **OWASP** | A03:2021 -- Injection |

**Description :**
La verification multi-statements ne bloque que les points-virgules suivis de certains mots-cles specifiques. La protection depend du comportement du driver PDO (`ATTR_EMULATE_PREPARES => false`) plutot que d'une validation explicite.

**Correction :**
Rejeter toute requete contenant un point-virgule (en dehors des chaines literales).

---

### BASSE (7 vulnerabilites)

---

#### B1 -- Parametre `$page` non valide contre une allowlist

| | |
|---|---|
| **Fichier** | `web/dashboard.php` (ligne 148) |
| **Type** | Input Validation |

**Description :** `$_GET['page']` est utilise dans un switch/case (safe) mais n'est jamais valide contre la liste des pages autorisees. Design fragile.

**Correction :** Valider contre un tableau explicite de noms de pages autorisees.

---

#### B2 -- Fuite d'informations dans les messages d'erreur

| | |
|---|---|
| **Fichier** | `web/login.php` (ligne 78) |
| **Type** | Information Disclosure |

**Description :** `$e->getMessage()` peut exposer des erreurs internes (DB, stack trace) a l'utilisateur.

**Correction :** Logger l'exception cote serveur, afficher un message generique.

---

#### B3 -- Timeout FastCGI excessif (1 heure)

| | |
|---|---|
| **Fichier** | `docker/nginx.conf` (lignes 39-40) |
| **Type** | Denial of Service |

**Description :** `fastcgi_read_timeout 3600` permet des scripts PHP de tenir des connexions ouvertes pendant 1 heure, pouvant epuiser les workers PHP-FPM.

**Correction :** Reduire a 300s pour la plupart des endpoints.

---

#### B4 -- Header `X-XSS-Protection` deprecie

| | |
|---|---|
| **Fichier** | `docker/nginx.conf` (ligne 11) |
| **Type** | Security Misconfiguration |

**Description :** `X-XSS-Protection: 1; mode=block` est deprecie et supprime des navigateurs modernes. Il peut introduire des vulnerabilites dans les anciens navigateurs.

**Correction :** Supprimer ce header et s'appuyer sur CSP.

---

#### B5 -- Logout accessible via GET

| | |
|---|---|
| **Fichier** | `web/api/index.php` (lignes 55-56) |
| **Type** | Session Management |

**Description :** Le logout est accessible via GET, permettant une deconnexion forcee via une balise `<img>`.

**Correction :** N'autoriser le logout que via POST avec validation CSRF.

---

#### B6 -- Exposition du timestamp serveur

| | |
|---|---|
| **Fichier** | `web/dashboard.php` (lignes 301-307) |
| **Type** | Information Disclosure |

**Description :** `?v=<?= time() ?>` sur les URLs CSS/JS expose le timestamp Unix du serveur.

**Correction :** Utiliser un hash du contenu ou un numero de version statique.

---

#### B7 -- Consommation memoire non bornee dans les exports

| | |
|---|---|
| **Fichier** | `app/Http/Controllers/ExportController.php` (lignes 104, 159-174) |
| **Type** | Denial of Service |

**Description :** Les exports CSV n'ont aucune limite de lignes. Un crawl de millions de pages pourrait epuiser la memoire du serveur.

**Correction :** Ajouter une limite configurable ou implementer un export par streaming/chunks.

---

## Resume

| Severite | Nombre | Exemples cles |
|----------|--------|---------------|
| **Critique** | 4 | SQL Explorer (injection + read-only non garanti), XSS stockes via donnees crawlees, absence CSRF sur l'API |
| **Haute** | 7 | Open redirect, tables sensibles accessibles, XSS preview HTML, headers Nginx, pas de CSP, credentials hardcodes, password en HTML |
| **Moyenne** | 9 | SSRF, cookie secure conditionnel, pas de rate limiting, password policy faible, XSS info bar, colonnes non validees, endpoint test non restreint, credentials en clair, multi-statement bypass |
| **Basse** | 7 | Page param non valide, fuite erreurs, timeout excessif, header deprecie, logout GET, timestamp expose, exports non bornes |
| **TOTAL** | **27** | |

---

## Plan de Correction Prioritaire

### Phase 1 -- Urgente (a traiter immediatement)

1. **SQL Explorer** : utiliser un user PostgreSQL read-only dedie + transaction explicite + bloquer les tables sensibles
2. **XSS stockes** : implementer `escapeHtml()` sur toutes les donnees crawlees dans `url-details-modal.php`
3. **CSRF** : ajouter un token ou header custom sur tous les endpoints POST/PUT/DELETE
4. **CSP** : ajouter un header `Content-Security-Policy` restrictif

### Phase 2 -- Importante (sous 2 semaines)

5. Corriger l'open redirect avec une validation stricte (allowlist `/` prefix)
6. Retirer `allow-same-origin` du sandbox preview
7. Corriger les headers Nginx pour les fichiers statiques
8. Retirer les credentials hardcodes des fichiers commites
9. Ne pas exposer le mot de passe HTTP Auth dans le HTML
10. Ajouter une validation SSRF sur les URLs de crawl

### Phase 3 -- Ameliorations (sous 1 mois)

11. Rate limiting sur le login
12. Politique de mots de passe renforcee (min 12 chars)
13. Cookie `secure` : detecter le proxy TLS correctement
14. Restreindre `launchTestCrawls` aux admins
15. Chiffrer les credentials HTTP Auth en base
16. Valider les colonnes d'export contre une allowlist

### Phase 4 -- Maintenance

17. Corriger les vulnerabilites de severite basse (B1-B7)
18. Mettre en place des tests de securite automatises dans la CI
19. Ajouter un scan de dependances (Composer audit)

---

*Ce rapport est base sur une analyse statique du code source. Un test d'intrusion dynamique (pentest) est recommande pour valider ces findings et identifier d'eventuelles vulnerabilites supplementaires.*
