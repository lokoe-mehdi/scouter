# Architecture de Communication : Workers, Jobs & Logs

Ce document explique en détail comment le Frontend (JS) et le Backend (Workers) communiquent.
Il n'y a **pas de connexion directe** (pas de WebSocket, pas de lien direct). Tout passe par la **Base de Données (PostgreSQL)** qui agit comme une boîte aux lettres centrale.

---

## 🗺️ Vue d'ensemble (La "Big Picture")

L'architecture fonctionne sur le principe du **"Fire and Forget"** pour le front, et du **"Worker Polling"** pour le back.

1.  **Le Chef (Frontend)** : Envoie un ordre "Démarre le crawl !" et reçoit un numéro de ticket (`job_id`).
2.  **La Boîte aux Lettres (Database)** : Stocke l'ordre dans la table `jobs`.
3.  **L'Ouvrier (Worker)** : Vérifie la boîte aux lettres en permanence. Dès qu'il voit un ordre, il le prend, le verrouille, et travaille.
4.  **Le Streaming** : L'ouvrier écrit ses notes (`logs`) dans la base de données au fur et à mesure. Le chef relit ces notes toutes les secondes.

---

## 🔧 Les Composants & Fichiers Clés

Voici le rôle précis de chaque fichier dans cette danse.

### 1. Le Frontend (Command Center)
*   **Fichier** : `web/assets/crawl-panel.js`
*   **Rôle** : Interface utilisateur.
*   **Action** :
    *   Au démarrage : Appelle `api/start-crawl.php`.
    *   Pendant le crawl : Appelle `api/get-job-logs.php` et `api/get-job-status.php` en boucle (toutes les ~1-2 secondes). C'est ce qu'on appelle du **Polling**.
    *   Ce n'est **PAS** du vrai streaming (comme Netflix), c'est du rafraîchissement rapide.

### 2. L'API (Le Guichetier)
*   **Fichiers** :
    *   `web/api/start-crawl.php` : Crée une ligne dans la table `jobs` avec status `pending`.
    *   `web/api/get-running-crawls.php` : Regarde quels jobs sont actifs.
    *   `web/api/get-job-logs.php` : Récupère les lignes de la table `job_logs` pour un ID donné.
*   **Rôle** : Faire l'intermédiaire entre le JS et la Base de Données.

### 3. Le Gestionnaire (Le Cerveau)
*   **Fichier** : `app/JobManager.php`
*   **Rôle** : Classe PHP centrale qui manipule SQL.
*   **Méthodes Clés** :
    *   `createJob()` : Insère le job.
    *   `addLog()` : Insère un log dans `job_logs`.
    *   `updateJobStatus()` : Change l'état (pending -> running -> completed).

### 4. Le Worker (L'Ouvrier)
*   **Fichier** : `app/bin/worker.php`
*   **Rôle** : Script PHP qui tourne **en infini** (via Docker Supervisor).
*   **Boucle (La "Zumba")** :
    1.  `SELECT * FROM jobs WHERE status = 'queued' FOR UPDATE SKIP LOCKED` : Cherche un job libre et pose un verrou dessus (pour éviter que 2 workers prennent le même).
    2.  `proc_open('php scouter.php crawl ...')` : Lance le VRAI script de crawl dans un sous-processus.
    3.  Capture la sortie (stdout) et l'écrit dans un fichier `.log` physique.
    4.  Utilise `JobManager->addLog()` pour écrire les logs structurés dans la DB.

---

## 🔄 Flux Détaillé : "De Start à Logs"

Voici exactement ce qui se passe quand tu cliques sur "Start".

### Étape 1 : Création du Job
1.  **JS** : Envoie POST vers `/api/start-crawl.php`.
2.  **PHP** : `JobManager` insère une ligne dans la table `jobs`.
    *   `status`: `pending`
    *   `project_dir`: `google.com`
3.  **PHP** : Rend l'ID `123` au JS.
4.  **JS** : Affiche le panneau et commence à poller l'ID `123`.

### Étape 2 : Prise en charge par le Worker
1.  **Worker.php** (qui boucle sans arrêt) voit le job `123` en `queued` (ou `pending`).
2.  Il passe le status à `running`.
3.  Il lance la commande système de crawl.

### Étape 3 : La récupération des Logs (Le "Streaming")
C'est là que la magie (ou l'arnaque) opère.

1.  **Côté Worker** : Pendant que le crawl tourne, le code PHP du crawl appelle `$jobManager->addLog(123, "Page trouvée: /contact", "info")`.
    *   Cela insère une ligne dans la table `job_logs`.
2.  **Côté Frontend** : Le JS a un `setInterval`.
    *   Il appelle `/api/get-job-logs.php?job_id=123`.
    *   L'API fait un `SELECT * FROM job_logs WHERE job_id = 123`.
    *   Le JS reçoit le JSON et l'ajoute dans la div noire du terminal.

### Étape 4 : Fin du Job
1.  Le processus de crawl se termine (exit code 0).
2.  **Worker.php** détecte la fin du processus.
3.  Il met à jour la table `jobs` : `status = 'completed'`.
4.  Il met à jour la table `crawls` (l'ancienne table) pour que le reste du site soit au courant.
5.  Le **JS** voit le status `completed` lors de son prochain poll et arrête de demander des logs.

---

## 🚨 Points Importants (Pourquoi c'est "Carré")

1.  **Indépendance** : Si tu fermes ton navigateur, le Worker continue. Le job est en base de données.
2.  **Scalabilité** : Tu peux lancer 50 containers `worker`. Grâce au `FOR UPDATE SKIP LOCKED` (PostgreSQL), ils ne se marcheront jamais dessus.
3.  **Logs Persistants** : Les logs ne sont pas juste en mémoire vive. Ils sont dans la table `job_logs`. Si tu recharges la page, l'historique revient.
4.  **Double Logging** :
    *   **DB (`job_logs`)** : Logs "propres" pour l'affichage UI (ex: "URL crawlé", "Erreur 404").
    *   **Fichier (`logs/projet.log`)** : Sortie brute du terminal (utile pour débugger si le worker crash violemment).

## 🛠 Résumé des Tables SQL

*   **`jobs`** : La file d'attente. Contient l'état (`pending`, `running`), le PID, et les dates.
*   **`job_logs`** : Le journal de bord. Lié à `jobs` par `job_id`. Contient le message et le type (`info`, `error`).
*   **`crawls`** : L'ancienne table principale. Elle est maintenue à jour "en miroir" par `JobManager` pour la compatibilité avec le reste du site.
