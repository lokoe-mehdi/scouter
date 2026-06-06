# Plan de migration htmx — navigation « web app » sans rechargement

> But : une fois logué, naviguer dans un crawl (rapports, segments, explorers, liens)
> sans **jamais** recharger intégralement la page. Ressenti SPA, **sans** réécriture
> Nuxt/Vue. On garde le PHP server-rendered tel quel et on n'échange que la zone de
> contenu.
>
> **Statut : DASHBOARD 100 % SPA (rapports, comparaisons, explorers, segments, config).**
> Hub (Phase 2) : profile + monitor en SPA ; index/project/settings en nav pleine
> (raisons documentées §10 / statut ci-dessous). Vérifié Pest + Playwright.

---

## ✅ Statut d'implémentation (mis à jour)

**Phase 0 — Socle : FAIT**
- htmx 2.0.9 vendorisé : `web/assets/vendor/htmx/htmx.min.js`.
- `web/assets/htmx-bootstrap.js` : config htmx (back/forward = reload plein), registre
  de teardown, ré-init chrome (tooltips + item actif sidebar).
- `web/partials/head-assets.php` : include `<head>` partagé (htmx + bootstrap).

**Phase 1 — Navigation dashboard (Zone A) : FAIT — TOUT le dashboard est SPA**
- `web/partials/main-content.php` : contenu de `#main-content` extrait (source unique).
- `dashboard.php` : branche `HX-Request` (rend le fragment seul), `<main id="main-content">`.
- `sidebar-navigation.php` : `hx-boost` → `#main-content` sur TOUS les liens (plus aucune
  exclusion). Rapports, comparaisons, explorers, segments, config : tout en SPA.
- **Swap-safety des composants de contenu** (anti-empilement + garde null) :
  `url-table.php` (corrige un throw `button.contains` latent), `link-table.php`,
  `redirect-table.php`, `chart.php`, `comparison-bar.php`.
- **Pages JS-lourdes rendues swap-safe** (url/link/sql-explorer, categorize, duplication
  + composants batch-job-notification, bulk-ai-modal) via une transformation mécanique :
  - `DOMContentLoaded` → `htmxOnReady()` (helper du bootstrap : exécute l'init au swap,
    où DOMContentLoaded ne refire pas).
  - `document/window.addEventListener` → `htmxPageListener()` (retiré au prochain swap →
    pas d'empilement ni de handler fantôme).
  - `const`/`let` top-level → `var` (ré-exécution du script inline au swap sans collision
    « already declared » ; évite aussi les collisions entre pages).
  - CodeMirror (sql-explorer) : recréé sur le nouveau textarea à chaque swap, instance
    unique vérifiée.
- **Socle** : `htmx-bootstrap.js` chargé en **synchrone** (avant htmx, sans `defer`) pour
  que `htmxOnReady`/`htmxPageListener` existent quand les scripts inline du body tournent
  (sinon cassé au chargement direct d'un explorer).

**Phase 2 — Hub (Zone B) : PARTIEL (sûr)**
- `profile.php` + `pages/monitor.php` : `hx-boost` (propres, dc=0/dl=0). Navigation hub
  sans rechargement, cloche/téléchargements **préservés** via `hx-preserve` (+ gardes
  d'idempotence dans `notifications.js`/`downloads.js`).
- `index.php`, `project.php`, `pages/settings.php` : **gardés en nav pleine** (liens
  `hx-boost="false"`). Raisons : index/project = init `DOMContentLoaded`+`window load`
  lourde (surgery risquée, flux création/démarrage non testables) ; settings = garde
  `beforeunload` (modifs non sauvegardées) que le boost contournerait.

**Vérifications passées** (app live en conteneur, session admin, Playwright + Pest) :
- 394 tests **Pest** ✓ (aucune régression serveur).
- **33/33** pages rapport/comparaison : SPA, 0 erreur console.
- **Explorers + segments** : navigation SPA, contenu rendu, CodeMirror OK, séquence de 10
  nav entrelacées → 0 erreur, interactions (popover filtre, recherche, éditeur SQL) OK.
- **Chargement direct** (refresh/bookmark) des explorers : helpers définis, 0 erreur.
- **Hub** profile↔monitor : boost, cloche préservée (même nœud DOM), 0 erreur ; retour
  accueil/projet = reload plein (attendu).
- **Stress** : nav rapides → charts bornés (teardown OK), registres de listeners bornés.

**Phase 3 (interne tables/filtres en htmx) & 4 (formulaires) : NON FAITES** (optionnelles).
**Reste hub** : index/project/settings en nav pleine (voir §10).

---

> **Plan d'origine ci-dessous.**

---

## 0. Décisions verrouillées (le « pourquoi »)

- **htmx, pas Nuxt.** L'app rend déjà du HTML côté serveur (PHP vanilla) et fait déjà
  du « fetch fragment + swap DOM » à la main (`data-table.js`). htmx parle HTML, donc
  il *remplace* un pattern existant au lieu d'imposer une couche JSON + une réécriture
  front. Nuxt = double travail (réécrire le front **et** transformer chaque rapport
  server-side en API JSON). Rejeté.
- **htmx vendorisé en local.** Aucune dépendance CDN au runtime.
  - Fichier : `web/assets/vendor/htmx/htmx.min.js`
  - Version : **2.0.9** (dernière stable de la branche 2.x ; les `v4.0.0-betaX` sont des
    préversions, écartées).
  - Taille : 51 Ko. SHA-256 : `57d9191515339922bd1356d7b2d80b1ee3b29f1b3a2c65a078bb8b2e8fd9ae5f`
  - ✅ déjà téléchargé.

---

## 1. Périmètre : deux zones navigables + une passerelle

Il n'y a **pas** de layout unique, mais **toutes** les pages loguées incluent le **même
`top-header.php`**. On exploite ça pour couvrir *tout* l'espace logué, pas seulement le
dashboard. Carte exacte de la chrome (vérifiée) :

| Page | URL | header | sélecteur crawl | crawl-panel | sidebar / layout corps |
|------|-----|:--:|:--:|:--:|---|
| `dashboard.php` | `dashboard.php?crawl=X&page=Y` | ✅ | ✅ | ✅ | sidebar **rapports** + Dr.Brief ; `.main-content` |
| `index.php` | `index.php` | ✅ | ❌ | ✅ | sidebar **catégories** ; `.main-content-area` |
| `project.php` | `project.php?id=N` | ✅ | ❌ | ✅ | aucune |
| `profile.php` | `profile.php` | ✅ | ❌ | ❌ | aucune |
| `pages/monitor.php` | `pages/monitor.php` | ✅ (`$isInSubfolder`) | ❌ | ❌ | aucune |
| `pages/settings.php` | `pages/settings.php?tab=` | ✅ (`$isInSubfolder`) | ❌ | ❌ | aucune |
| `login.php`, `oauth.php` | — | ❌ | — | — | aucune (jamais htmx) |

Deux constats qui pilotent l'archi :

- **Le header des 5 pages hors-dashboard est structurellement identique** : le sélecteur
  de crawl (`top-header.php:64-128`) ne s'affiche qu'en contexte `dashboard`. Donc
  index / project / profile / monitor / settings ont la **même** barre du haut → elles
  forment une vraie **2ᵉ zone navigable** sans rechargement.
- **Mais le layout du corps diffère** d'une page à l'autre (index a sa sidebar catégories,
  les autres non). On **ne peut pas** y faire le simple swap de `#main-content` du
  dashboard.

### Les deux zones

- **Zone A — Dashboard d'un crawl** (`dashboard.php`). Rapports, segments, explorers,
  liens de la sidebar : navigation **très fréquente**, chrome 100 % stable (header +
  sidebar rapports + Dr.Brief ne bougent pas). → swap ciblé de `#main-content` (§4).
- **Zone B — Hub** (`index`, `project`, `profile`, `monitor`, `settings`). Header commun,
  corps différent. Navigation **moins fréquente**. → `hx-boost` (swap du `<body>`, fusion
  du `<head>`) **+ `hx-preserve`** sur les widgets vivants pour qu'ils ne se
  réinitialisent pas (§4bis).

### La passerelle A ↔ B (entrer / quitter un crawl)
Traversée gérée par le **même `hx-boost`** que la zone B. Le header reste, le corps change
(la sidebar rapports + Dr.Brief apparaissent/disparaissent — UX correcte). **Point de
vigilance verrouillé : on ne préserve pas le header en entier** — il porte le sélecteur de
crawl en contexte dashboard, qui resterait « collé » en sortant. On préserve **par id** les
seuls sous-widgets stateful (cf. §4bis), le header contextuel se re-rend correctement.

---

## 2. État des lieux (références fichiers)

### Architecture de `dashboard.php`
```
init.php (auth + accès crawl)            ← web/init.php:1-57, require en tête de chaque page
  ↓
bootstrap dashboard                       ← dashboard.php:77-162 (charge $crawl, catégories, compare)
  ↓
$page = $_GET['page'] ?? 'home'           ← dashboard.php:178
  ↓
<!DOCTYPE> … <head> (CSS/JS)              ← dashboard.php:338-379
<body>
  include top-header.php                  ← dashboard.php:382   [CHROME persistante]
  <div class="dashboard-layout">
    include sidebar-navigation.php        ← dashboard.php:409   [CHROME persistante]
    <main class="main-content">           ← dashboard.php:412   ★ CIBLE DU SWAP ★
       switch($page){ include pages/X.php }← dashboard.php:437-564  [CONTENU]
    </main>
  </div>
  include modals / quick-search / crawl-panel ← dashboard.php:576-581 [CHROME persistante]
  include dr-brief-widget.php             ← dashboard.php:580   [CHROME persistante]
</body>
```

- **Navigation** : liens `<a href="?crawl=X&page=Y">` (query-string), pas de routing
  fichier. Page active = classe `.active` calculée sur `$page`
  (`sidebar-navigation.php`).
- **Pages de contenu** (`pages/*.php`) : **déjà sans `<!DOCTYPE>` ni `<head>`** — pur
  contenu destiné à être inclus dans `<main>`. C'est un cadeau : elles sont déjà des
  « fragments » naturels.
- **Pas de front controller** : chaque `.php` est sa propre entrée.

### Mécanique « partial » existante (à connaître pour ne pas la casser)
- `data-table.js:272-314` (`_fetchAndUpdate`) : `fetch(url, {headers:{X-Requested-With}})`
  → `response.text()` → `DOMParser` → extrait `#tableContainer_<id>` ou `#tableCard_<id>`
  → remplace `innerHTML`. **Réponse = HTML, pas JSON.**
- Côté serveur, **aucune** détection d'AJAX générique : la page se re-rend en entier et
  le JS jette tout sauf le bloc table. Seuls cas ad hoc : `index.php` (`?partial=projects`)
  et l'historique de crawl dans `project.php`. Le flag `embedMode`
  (`url-table.php:43,894`) est piloté par config, pas par la requête → aujourd'hui chaque
  fetch de table renvoie aussi les `<style>`/`<script>` en double (inefficace mais
  fonctionnel).
- **API JSON** (`web/api/index.php`) : sert l'état (crawls running, notifications,
  exports, jobs, dr-brief). **Ne renvoie pas** le HTML des rapports. Pas de chevauchement
  à craindre.

---

## 3. Le principe directeur : coquille persistante vs zone échangée

> **Règle d'or unique dont découle tout le reste : on ne swappe JAMAIS la chrome.
> On ne remplace que `#main-content`. Tout ce qui tient un état ou poll vit dans la
> chrome et n'est initialisé qu'UNE fois.**

Ça transforme le problème « tout le JS est à risque à chaque navigation » en « seules les
pages de contenu se ré-initialisent ; la chrome statique ne bouge jamais ».

Concrètement, **restent dans la coquille (init une seule fois, survivent à toutes les
navs)** :

- **Dr. Brief / chatbot** (`dr-brief-widget.php`, inclus `dashboard.php:580`) — streaming
  `fetch().getReader()` sur `/api/dr-brief/chat`, conversation **en mémoire**, script
  inline `:803`. Hors de `<main>` → **sa conversation et son flux survivent**. C'est *la*
  raison pour laquelle cette archi marche.
- **crawl-panel** (`crawl-panel.js`) — polling 2 s/500 ms, état localStorage/sessionStorage.
- **notifications** (`notifications.js`, poll 10 s) et **downloads** (`downloads.js`, poll
  5 s).
- **top-header**, **sidebar**, **modals**, **quick-search**, **confirm-modal**,
  **global-status**, **tooltip** (MutationObserver), **url-modal-handler** (délégation sur
  `document`).

Doivent **se ré-initialiser à chaque swap** (ils vivent DANS `#main-content`) :

- **DataTable** (`data-table.js`) — instance par table, handlers de scroll.
- **FilterBar** (`filter-bar.js`) — instance, état dans `filterGroups`.
- **Highcharts** + modules (sankey/treemap/exporting) — graphes instanciés par scripts
  inline.
- **CodeMirror** (SQL explorer) — instance liée à un `<textarea>`.

---

## 4. Mécanique « full page vs fragment »

### 4.1 Détection serveur : l'en-tête `HX-Request`
htmx envoie automatiquement `HX-Request: true` sur chaque requête. Point d'injection
unique, dans `dashboard.php`, **après** le bootstrap (donc `$crawl`, `$page`, catégories
déjà chargés — le contenu en a besoin) et **avant** le `<!DOCTYPE>` :

```php
// dashboard.php, après dashboard.php:178 ($page résolu)
$isFragment = !empty($_SERVER['HTTP_HX_REQUEST']) && empty($_SERVER['HTTP_HX_HISTORY_RESTORE_REQUEST']);

if ($isFragment) {
    // 1) le contenu seul (le MÊME switch que la full page, factorisé)
    include __DIR__ . '/partials/main-content.php';   // = le switch($page){…} extrait
    // 2) màj OOB de la chrome qui doit suivre la nav (titre + item actif sidebar)
    include __DIR__ . '/partials/nav-oob.php';
    exit;   // ne rend NI doctype, NI header, NI sidebar, NI widgets
}
// sinon : full page comme aujourd'hui
```

- On **n'utilise pas `hx-select`** : il forcerait le serveur à rendre la page entière
  (chrome + SQL chrome) pour que htmx en jette 90 %. Le branchement `HX-Request` rend
  *exactement* le fragment → moins de SQL, réponse minimale, et au passage ça **supprime
  le doublon `<style>/<script>`** des fetch de table actuels.
- **Refactor préalable obligatoire** : extraire le `switch($page){ include pages/… }`
  (`dashboard.php:437-564`) dans `partials/main-content.php`, inclus **aux deux endroits**
  (full page ET fragment). Source unique, zéro divergence.
- `HX-History-Restore-Request` : quand htmx restaure une page depuis l'historique sans
  cache, il refait la requête — on veut alors la **page pleine**, d'où l'exclusion.

### 4.2 Les liens : progressive enhancement
La sidebar et les liens internes gardent leur `href` réel (dégradation gracieuse : sans
JS ou si htmx échoue, c'est une navigation normale). On ajoute les attributs htmx, posés
**une fois sur le conteneur de nav** et hérités :

```html
<nav class="sidebar-panel"
     hx-target="#main-content"
     hx-swap="innerHTML show:window:top"
     hx-push-url="true">
  <a href="?crawl=123&page=depth" hx-get="dashboard.php?crawl=123&page=depth">Profondeur</a>
  ...
</nav>
```

- `hx-push-url="true"` → l'URL change, back/forward et partage de lien fonctionnent.
- `href` conservé → **dégradation gracieuse**.
- Accès direct / refresh sur `?page=depth` → pas de `HX-Request` → full page. ✅

### 4.3 Mise à jour de la chrome qui suit la nav (OOB)
Le fragment renvoie aussi, via `hx-swap-oob`, les rares bouts de chrome qui changent :

```html
<!-- partials/nav-oob.php -->
<title hx-swap-oob="true"><?= $pageTitle ?> — Scouter</title>
```

Pour l'**item actif de la sidebar**, ne pas re-rendre la sidebar (elle est persistante) :
un mini-handler `htmx:afterSettle` lit l'URL poussée et déplace la classe `.active`. Moins
de churn serveur, sidebar jamais touchée.

---

## 4bis. Mécanique de la Zone B (hub) + passerelle : `hx-boost` + `hx-preserve`

La zone B ne peut pas viser un conteneur commun (layouts différents). On échange donc le
`<body>` et on **épingle les nœuds vivants par id** pour qu'htmx garde le **nœud DOM
existant** au lieu de le recréer.

```html
<!-- <body> des pages de la zone B (et de dashboard, pour la passerelle) -->
<body hx-boost="true">
  ...
  <!-- widgets stateful : préservés à travers la nav, par id stable -->
  <div id="notifCenter"    hx-preserve="true"> … </div>   <!-- poll 10 s, dans le header -->
  <div id="downloadCenter" hx-preserve="true"> … </div>   <!-- poll 5 s,  dans le header -->
  <div id="crawlPanelRoot" hx-preserve="true"> … </div>   <!-- monitoring temps réel -->
</body>
```

- **`hx-boost`** transforme `<a>`/forms en requêtes AJAX : htmx récupère la page,
  **remplace le `<body>`** et **fusionne le `<head>`** (ajoute le CSS/titre de la
  destination, ne re-télécharge pas les assets déjà présents → `app.js`, `crawl-panel.js`…
  **ne se ré-exécutent pas**).
- **`hx-preserve="true"`** : htmx réinjecte le **nœud vivant existant** (avec son état JS,
  ses intervals, son flux) à la place de celui rendu par le serveur. → cloche, centre de
  téléchargements et crawl-panel **ne perdent ni polling ni état** en naviguant dans la
  zone B.
- **On ne préserve PAS `<header>` en entier** : il contient le sélecteur de crawl en
  contexte dashboard. Préserver tout le header le figerait. On préserve uniquement les
  sous-blocs stateful ci-dessus ; le header se re-rend pour le bon contexte (identique
  entre toutes les pages de B, donc aucun flicker).
- **Dégradation** : `hx-boost` garde le `href`. JS coupé → navigation normale.

### Points de vigilance spécifiques zone B

- **Chemins relatifs sous-dossier.** `monitor.php` / `settings.php` vivent dans `pages/`
  (`$isInSubfolder`, assets en `../`). `hx-boost` suit le `href` tel quel ; la fusion de
  `<head>` charge le bon CSS de destination. À **tester explicitement** : aller-retour
  top-level ↔ `pages/` (ex. `index.php` → `pages/settings.php` → retour) sans CSS cassé.
- **`hx-preserve` exige l'id dans les deux pages.** `crawlPanelRoot` est présent dans
  index/project/dashboard mais **pas** dans profile/monitor/settings : en y allant, le
  nœud n'est pas réinjecté (normal), et au retour il se réinitialise — mais son état vit
  en localStorage, donc il **reprend** son monitoring. Acceptable (pas de perte).
- **Scripts inline en bas de `<body>`** (`index.php`, `project.php` ont de la logique de
  formulaire/modale inline) : ils se ré-exécutent à chaque boost de cette page. Invariant
  §5.2(1) — **aucun** `addEventListener` sur `document`/`body` ni `setInterval` dans ces
  blocs, sinon empilement. À auditer avant de booster ces pages.
- **Dr.Brief** n'existe qu'en dashboard (zone A, swap ciblé) → jamais concerné par le
  boost ; sa conversation/flux reste intact tant qu'on est dans le dashboard. En
  **quittant** le crawl (passerelle A→B), il disparaît légitimement.

---

## 5. Stratégie anti-régression du JS (le cœur du risque)

### 5.1 Inventaire & classement

| Module | Rôle | Init | Vit dans | Risque au swap | Action |
|---|---|---|---|---|---|
| `crawl-panel.js` | monitoring temps réel, polling, localStorage | DOMContentLoaded | **chrome** | — (jamais swappé) | aucune |
| `dr-brief-widget` (chatbot) | chat streaming, convo en mémoire | inline `:803` | **chrome** | — | aucune |
| `notifications.js` | cloche, poll 10 s | IIFE | **chrome** | — | aucune |
| `downloads.js` | centre DL, poll 5 s | IIFE | **chrome** | — | aucune |
| `tooltip.js` | tooltips + MutationObserver | IIFE | **chrome** | observer voit-il le nouveau contenu ? | observer sur `body` (persistant) → OK ; appeler `initTooltips()` en `afterSettle` par sécurité |
| `confirm-modal.js`, `global-status.js`, `url-modal-handler.js` | utilitaires, délégation `document` | top-level | **chrome** | — (délégation survit) | aucune |
| `i18n.js` | traductions | idempotent | **chrome** | — | charger 1× dans le shell ; **retirer** `ScouterI18n.init()` des fragments |
| **`data-table.js`** | pagination/tri/scroll par table | `new DataTable()` inline | **`#main-content`** | **HAUT** : instance + handlers scroll perdus, fuite | teardown au `beforeSwap` |
| **`filter-bar.js`** | UI filtres | `new FilterBar()` inline | **`#main-content`** | **HAUT** : état `filterGroups` perdu | teardown + recréée par le script inline |
| **Highcharts** + sankey/treemap/exporting | graphes | scripts inline | **`#main-content`** | **HAUT** : instances orphelines | détruire les charts du sous-arbre au `beforeSwap` |
| **CodeMirror** (SQL explorer) | éditeur | inline | **`#main-content`** | **HAUT** : lié au textarea | `.toTextArea()` au teardown |

### 5.2 Les deux invariants à tenir

1. **Aucun listener sur `document`/`body`, aucun `setInterval`, aucun singleton d'état ne
   doit vivre dans un script inline de `pages/*.php`.** Sinon il s'empile à chaque swap
   (double-fire, fuite mémoire). Tout ce qui est global → fichier de shell chargé une
   fois. *À auditer page par page avant de htmx-ifier.*

2. **htmx exécute les `<script>` du contenu injecté** (innerHTML, `htmx.config.allowScriptTags`
   par défaut). Donc les `new DataTable(...)` / `new FilterBar(...)` / init de charts
   **se relancent tout seuls** au swap. Le seul travail manquant = **détruire l'ancienne
   instance** pour éviter fuites et handlers fantômes.

### 5.3 Convention de teardown (registre)
Dans un fichier de shell chargé une fois (ex. `assets/htmx-bootstrap.js`) :

```js
window.__pageTeardown = [];                       // les pages y poussent leur nettoyage

document.body.addEventListener('htmx:beforeSwap', (e) => {
  if (e.detail.target.id !== 'main-content') return;
  // 1) nettoyages enregistrés par la page sortante
  window.__pageTeardown.forEach(fn => { try { fn(); } catch(_){} });
  window.__pageTeardown = [];
  // 2) filet de sécurité : tuer les charts Highcharts du sous-arbre qui part
  if (window.Highcharts) {
    Highcharts.charts.filter(c => c && !document.body.contains(c.renderTo))
                     .forEach(c => c.destroy());
  }
});

document.body.addEventListener('htmx:afterSettle', (e) => {
  if (e.detail.target.id !== 'main-content') return;
  if (window.initTooltips) initTooltips();        // re-scan tooltips
  setActiveSidebarFromUrl();                       // item actif sidebar
});
```

Chaque script inline de page enregistre son nettoyage :
```js
// fin de pages/url-explorer.php (script inline)
const dt = new DataTable(...); const fb = new FilterBar(...);
window.__pageTeardown.push(() => { dt.destroy?.(); fb.destroy?.(); });
```
→ il faudra ajouter une petite méthode `destroy()` à `DataTable`/`FilterBar` (retrait des
listeners de scroll, des popovers). C'est le seul vrai code à écrire côté libs existantes.

---

## 6. Plan d'action par phases (incrémental, réversible)

> Chaque phase est livrable seule, testable, et **n'élargit la surface htmx que d'un cran**.
> Si une phase régresse, on retire ses attributs `hx-*` et on retombe sur le `href` natif.

**Phase 0 — Socle (faible risque)** ✅ htmx vendorisé.
- Créer un **include `<head>` partagé** (ex. `partials/head-assets.php`) qui charge
  `assets/vendor/htmx/htmx.min.js` (defer) + `assets/htmx-bootstrap.js`, et l'inclure dans
  le `<head>` de **toutes** les pages loguées (dashboard, index, project, profile,
  monitor, settings). Mutualise aussi la version htmx en un seul endroit.
- `assets/htmx-bootstrap.js` = registre teardown + hooks `beforeSwap`/`afterSettle` (§5.3).
- Vérifier la CSP (cf. §7). Aucun lien encore htmx-ifié → comportement strictement
  identique.

**Phase 1 — Zone A : navigation du dashboard (le gros du gain)**
- Extraire le `switch($page)` → `partials/main-content.php`.
- Brancher `HX-Request` dans `dashboard.php` (§4.1) + `partials/nav-oob.php`.
- Donner l'id `#main-content` au `<main>` (`dashboard.php:412`).
- Poser `hx-get`/`hx-target`/`hx-push-url` sur les liens de la **sidebar rapports** + le
  sélecteur de crawl du header (vers `?page=…`).
- Ajouter `destroy()` à DataTable/FilterBar + enregistrement teardown dans les pages
  concernées (url-explorer, link-explorer, sql-explorer, pages à charts).
- **Critère** : chaque rapport ne recharge plus la page ; Dr.Brief, crawl-panel, polling,
  URL/back/forward intacts ; graphes et tables OK après nav.

**Phase 2 — Zone B (hub) + passerelle A↔B**
- Poser `hx-boost="true"` sur le `<body>` des pages de la zone B **et** du dashboard (pour
  la passerelle).
- Donner des id stables + `hx-preserve="true"` aux widgets vivants : centre notifications,
  centre téléchargements, `crawl-panel` (§4bis).
- **Audit préalable obligatoire** des scripts inline en bas de `<body>` d'`index.php` /
  `project.php` (invariant §5.2(1) : pas de listener `document` ni `setInterval` inline).
- Tester la passerelle : entrer dans un crawl depuis `index`/`project` puis revenir → le
  header et les widgets préservés ne bronchent pas ; sidebar rapports apparaît/disparaît.
- Tester l'aller-retour sous-dossier (`pages/settings.php`) : pas de CSS cassé.
- **Critère** : naviguer index ↔ project ↔ profile ↔ monitor ↔ settings sans rechargement
  complet ; polling cloche/downloads ininterrompu.

**Phase 3 — Intérieur des tables/filtres (après stabilisation P1/P2)**
- Remplacer le `fetch+DOMParser` de `data-table.js` par `hx-get` + `hx-target` sur le
  conteneur de table (pagination/tri/colonnes), et le `onApply` de `filter-bar.js` par un
  `hx-get` déclenché. Le serveur sert déjà ces fragments.
- Permet de **supprimer** `_fetchAndUpdate` et le doublon `<style>/<script>` (`embedMode`).
- Les tables marchent aujourd'hui : pas de précipitation.

**Phase 4 — Formulaires / actions (sélectif)**
- Catégorisation (segments), config crawl, création projet, settings : passer les POST en
  `hx-post` au cas par cas, retour de fragment + toast `global-status`. Laisser tel quel ce
  qui fonctionne déjà bien.

---

## 7. Garde-fous & points de vigilance

- **Dégradation gracieuse** : tout lien `hx-get` garde son `href`. Sans JS / si htmx
  plante / si erreur réseau → navigation normale. Jamais de cul-de-sac.
- **CSP** : vérifier les en-têtes. htmx exécute des scripts inline dans le contenu
  injecté ; une CSP stricte (`script-src` sans `unsafe-inline`) les bloquerait. Auditer
  avant Phase 1. (Chercher `Content-Security-Policy` dans la config serveur / headers PHP.)
- **Double-soumission / listeners empilés** : invariant §5.2(1). Audit obligatoire des
  `<script>` inline de chaque `pages/*.php` avant de la passer en htmx.
- **Restauration historique** : exclure `HX-History-Restore-Request` du branchement
  fragment (§4.1) pour éviter une page tronquée au back-button hors cache.
- **i18n** : charger une fois dans le shell, retirer `ScouterI18n.init()` des fragments
  (sinon ré-exécuté inutilement).
- **Indicateur de chargement** : prévoir un `.htmx-indicator` discret sur `#main-content`
  (htmx ajoute la classe `htmx-request` pendant le vol) pour le ressenti.
- **Réversibilité** : aucune suppression de code en P1 (on ajoute des attributs). Le
  rollback = retirer les `hx-*`.

---

## 8. Checklist de test manuel (par phase, avant merge)

- [ ] Cliquer **chaque** entrée de sidebar : pas de reload complet (onglet réseau =
      requête `dashboard.php` avec `HX-Request`, pas de rechargement des assets).
- [ ] URL mise à jour à chaque nav ; **back/forward** restaure le bon rapport.
- [ ] **Refresh** (F5) sur une URL `?page=…` rend la page pleine correcte.
- [ ] **Dr. Brief** : ouvrir une conversation, naviguer entre 3 rapports → la conversation
      et un éventuel streaming en cours **persistent**.
- [ ] **crawl-panel** : un crawl en cours continue de poller/progresser pendant la nav.
- [ ] **notifications / downloads** : badges et polling intacts après navigation.
- [ ] **Graphes Highcharts** : s'affichent après nav (pas de canvas vide) ; pas de fuite
      (compter `Highcharts.charts` ne croît pas indéfiniment).
- [ ] **Tables** (url/link explorer) : pagination, tri, filtres, export CSV OK après nav.
- [ ] **SQL explorer** : CodeMirror se ré-initialise (pas de double éditeur).
- [ ] **Tooltips** et **modals URL** fonctionnent sur le contenu fraîchement chargé.
- [ ] Console **sans erreur**, pas de listeners empilés (cliquer 5× le même lien puis une
      action → pas de double-fire).

**Zone B (hub) + passerelle :**
- [ ] Naviguer `index` → `project` → `profile` → `monitor` → `settings` sans rechargement
      complet ; le header reste, seul le corps change.
- [ ] **cloche** et **centre de téléchargements** : polling/badges **ininterrompus** sur
      toute cette navigation (widgets `hx-preserve`).
- [ ] **crawl-panel** : un crawl en cours suivi reste affiché/progresse en passant
      d'`index` à `project` ; en allant sur `profile` (sans panel) puis retour, il reprend
      depuis localStorage.
- [ ] **Aller-retour sous-dossier** : `index.php` → `pages/settings.php` → retour, **CSS
      intact** (fusion `<head>`), pas de chemins d'assets cassés.
- [ ] **Passerelle** : entrer dans un crawl depuis `index`/`project`, la sidebar rapports +
      Dr.Brief apparaissent ; ressortir, le sélecteur de crawl du header **disparaît** (pas
      figé) et le header redevient celui du hub.
- [ ] Onglet réseau : nav zone B = requête boostée (header `HX-Request`), pas de
      re-téléchargement des assets déjà chargés.

**Transverse :**
- [ ] Sans JS (htmx désactivé) : navigation toujours fonctionnelle via `href`.

---

## 9. Le premier vrai chantier

Avant la moindre ligne d'htmx fonctionnel : **extraire `partials/main-content.php`** (le
`switch($page)`) et **brancher `HX-Request`** dans `dashboard.php` (§4.1), puis donner
l'id `#main-content` au `<main>`. C'est la fondation full-page/fragment ; tout le reste
s'y branche. Le reste de la Phase 1 (attributs sur les liens + teardown) vient ensuite.

---

## 10. Phase 2 (hub) — état d'avancement & arbitrage de risque

Audit de swap-safety des 5 pages hub (présence de code qui casserait sous `hx-boost`,
qui échange tout le `<body>` et ne relance PAS le code en `DOMContentLoaded`) :

| Page | `DOMContentLoaded` | listeners doc/window | Verdict |
|------|:--:|:--:|--------|
| `profile.php` | 0 | 0 | ✅ sûr à booster |
| `pages/monitor.php` | 0 | 0 | ✅ sûr |
| `pages/settings.php` | 0 | 3 | 🟧 modéré (listeners à garder) |
| `project.php` | 1 | 6 | 🟥 lourd |
| `index.php` | 1 | 11 (dont `window load`) | 🟥 lourd |

**Le hic.** `index.php` et `project.php` initialisent une grosse partie de leur UI
(grille projets, modales de création, filtres, mesure de hauteur d'en-tête via
`window load`) dans des blocs `DOMContentLoaded`/`window.load` + 11/6 listeners répartis
dans de gros fichiers. Sous `hx-boost`, ces inits **ne se relanceraient pas** et les
listeners **s'empileraient** → régression sur les deux pages les plus critiques hors
dashboard. Les rendre swap-safe = chirurgie sur du code sensible (mêmes techniques que la
Phase 1 : `once-guard` + conversion `DOMContentLoaded`→exécution immédiate), faisable mais
à risque, et difficile à e2e-tester entièrement (flux de création de projet, etc.).

**Décision : Phase 2 mise en pause pour arbitrage** (Phase 1 livre déjà le cœur du besoin —
navigation fluide *dans* un crawl). Options soumises à l'utilisateur dans le chat.
