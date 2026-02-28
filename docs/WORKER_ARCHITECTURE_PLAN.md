# Architecture des Workers de Crawl

Ce document détaille l'architecture technique pour migrer l'exécution des crawls d'un modèle "exécution directe" (via Web) vers un modèle de **workers asynchrones** scalables via Docker.

## 1. Vue d'ensemble

### Architecture Actuelle
*   **Front (Web):** Lance `exec(php scouter.php crawl ...)` directement.
*   **Problème:** Risque de saturation du serveur, pas de gestion de file d'attente, processus orphelins si le web server redémarre.

### Nouvelle Architecture Cible
*   **Front (Web):** Crée un Job avec le statut `queued` et rend la main immédiatement.
*   **Base de Données (PostgreSQL):** Sert de file d'attente via la table `jobs`.
*   **Service Worker (Docker):**
    *   X conteneurs (replicas) qui tournent en permanence.
    *   Chaque worker "pioche" un job en attente.
    *   Exécute le crawl avec des limites de threads configurables.
*   **Service Renderer (Node/Puppeteer):** Gère le rendu JS, sollicité par les workers.

## 2. Modifications Base de Données

Aucune modification de structure majeure n'est requise, mais nous introduisons un nouveau statut pour les jobs et les crawls.

### Nouveaux Statuts
*   **`queued`**: Le crawl est validé et en attente d'un worker disponible.
*   **Cycle de vie:** `pending` (création) -> `queued` (validé) -> `running` (pris par un worker) -> `completed` / `failed` / `stopped`.

## 3. Architecture Docker (docker-compose.yml)

Ajout d'un service `worker` dédié.

```yaml
services:
  # ... services existants (postgres, scouter, renderer) ...

  worker:
    build: .
    # Démarrer le script de worker au lieu d'Apache/PHP-FPM
    entrypoint: ["php", "app/bin/worker.php"]
    restart: unless-stopped
    deploy:
      mode: replicated
      replicas: 5  # Nombre de workers (crawls simultanés max au total)
    environment:
      - DATABASE_URL=postgresql://${POSTGRES_USER}:${POSTGRES_PASSWORD}@postgres:5432/${POSTGRES_DB}
      - RENDERER_URL=http://renderer:3000
      
      # CONFIGURATION DE LA PUISSANCE PAR WORKER
      # Nombre de threads Curl classiques simultanés par crawl
      - MAX_CONCURRENT_CURL=15 
      # Nombre de pages Chrome/Puppeteer simultanées par crawl
      - MAX_CONCURRENT_CHROME=5
      
    volumes:
      # Partage des logs indispensable pour que le front puisse lire les logs du worker
      - ./logs:/var/www/html/logs
    depends_on:
      - postgres
      - renderer
```

## 4. Le Script Worker (`app/bin/worker.php`)

Ce script sera le cœur du système. Il tourne en boucle infinie dans le conteneur `worker`.

### Algorithme du Worker

1.  **Démarrage :** Charge la configuration et se connecte à la DB.
2.  **Boucle Infinie :**
    *   **Check Signal :** Si SIGTERM/SIGINT reçu, finir le job en cours et s'arrêter.
    *   **Polling (Atomic Lock) :**
        Recherche un job en attente et le verrouille pour éviter qu'un autre worker ne le prenne.
        ```sql
        SELECT * FROM jobs 
        WHERE status = 'queued' 
        ORDER BY created_at ASC 
        LIMIT 1 
        FOR UPDATE SKIP LOCKED
        ```
    *   **Si Job trouvé :**
        1.  Update status -> `running`.
        2.  Récupérer le PID actuel du worker (pour pouvoir le tuer si besoin via l'interface).
        3.  Lancer le crawl.
            *   *Option A (Processus isolé) :* Lancer `passthru('php scouter.php crawl ...')`. Meilleur pour la gestion mémoire.
            *   *Injection de config :* Passer les env vars `MAX_CONCURRENT_CURL` au sous-processus.
        4.  À la fin du crawl, update status -> `completed` ou `failed`.
    *   **Si pas de Job :** `sleep(2)` avant de retenter.

## 5. Modifications du Moteur de Crawl (`scouter.php` & `DepthCrawler.php`)

Le crawler actuel déduit le nombre de threads (`simultaneousLimit`) uniquement via des profils de vitesse (`slow`, `fast`, etc.). Il faut le rendre plus flexible.

### Actions requises :

1.  **Mise à jour de `scouter.php` :**
    *   Lire les variables d'environnement `MAX_CONCURRENT_CURL` et `MAX_CONCURRENT_CHROME`.
    *   Les passer dans la configuration du `Crawler`.

2.  **Mise à jour de `DepthCrawler.php` :**
    *   Modifier `configureCrawlSpeed()` ou le constructeur pour accepter des entiers bruts si les variables d'environnement sont présentes.
    *   Pour `runJavascript()` : Utiliser `MAX_CONCURRENT_CHROME` pour limiter la taille des batchs envoyés au service Renderer.
    *   Pour `runNormal()` : Utiliser `MAX_CONCURRENT_CURL` pour configurer `RollingCurl`.

## 6. Modifications Frontend & API

### `web/api/start-crawl.php`
*   **AVANT :** Créait le job, lançait `proc_open`, renvoyait le PID.
*   **APRÈS :** Crée le job, set status = `queued`. C'est tout. Ne lance plus de process.

### Interface Utilisateur
*   Le panel de monitoring doit gérer l'état `queued`.
    *   Badge : "En file d'attente" (Couleur: Jaune/Gris).
    *   Logs : Afficher "En attente d'un worker..." tant que le statut est `queued`.
*   Le polling JS existant (`crawl-panel.js`) fonctionnera toujours car il check le statut du job en base. Dès que le worker passe le job en `running`, l'UI se mettra à jour automatiquement.

## 7. Gestion des Logs et Monitoring

Pour que le système de monitoring actuel continue de fonctionner :
*   **Logs Fichiers :** Les workers doivent écrire dans `./logs/{project}.log`. Grâce au volume Docker partagé, le conteneur Web pourra lire ces fichiers via `get-job-logs.php`.
*   **Logs Base de données :** Le `JobManager` écrit déjà en base. Comme le worker a accès à la même DB, les logs temps réel ("Found X URLs", "Depth 1 finished") apparaitront bien dans l'interface.

## 8. Arrêt des Crawls (Stop)

*   **Problème :** Le bouton "Stop" actuel fait un `kill -9 PID`. Le Web ne pourra pas tuer un process dans le conteneur Worker.
*   **Solution :**
    1.  Le bouton "Stop" passe le statut du job à `stopping` (nouveau statut transitoire) via la DB.
    2.  Le `DepthCrawler` doit vérifier périodiquement (ex: à chaque batch d'URLs) si le statut du job en DB est `stopping`.
    3.  Si oui, il s'arrête proprement (`exit`).
    4.  Le Worker détecte la fin du process et marque le job comme `stopped`.

## Résumé du Plan de Migration

1.  **Database :** Ajouter statut `queued`.
2.  **Code :**
    *   Créer `app/bin/worker.php`.
    *   Adapter `DepthCrawler.php` pour la config threads.
    *   Modifier `start-crawl.php` pour mise en file d'attente.
    *   Adapter l'UI pour le statut `queued`.
3.  **Docker :** Mettre à jour `docker-compose.yml`.
4.  **Déploiement :** `docker-compose up -d --build`.
