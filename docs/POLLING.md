# 📋 Le Polling des Jobs - Guide Complet

Ce document explique comment fonctionne le système de polling des jobs dans Scouter, pourquoi il peut planter, et comment on l'a corrigé.

---

## 🎯 C'est quoi le Polling ?

### Analogie simple

Imagine un restaurant avec **4 serveurs** (les workers) et **1 tableau des commandes** (la base de données).

```
┌─────────────────────────────────────────────────────────────┐
│                    TABLEAU DES COMMANDES                    │
│                      (Table "jobs")                         │
├─────────────────────────────────────────────────────────────┤
│  Job 1: "Crawler site-a.com"    → Status: queued            │
│  Job 2: "Crawler site-b.com"    → Status: queued            │
│  Job 3: "Crawler site-c.com"    → Status: running (Worker 2)│
│  Job 4: "Crawler site-d.com"    → Status: completed         │
└─────────────────────────────────────────────────────────────┘

    ↑           ↑           ↑           ↑
    │           │           │           │
 Worker 1    Worker 2    Worker 3    Worker 4
 "Y'a du     "Je fais    "Y'a du     "Y'a du
  boulot?"    Job 3"      boulot?"    boulot?"
```

**Le polling, c'est ça** : chaque serveur (worker) regarde régulièrement le tableau pour voir s'il y a une nouvelle commande (job) à prendre.

### En code

Chaque worker fait une boucle infinie :

```php
while (true) {
    // 1. "Hey base de données, t'as un job pour moi ?"
    $job = $db->query("SELECT * FROM jobs WHERE status = 'queued' LIMIT 1");
    
    if ($job) {
        // 2. "Ok je prends ce job et je le fais"
        $db->exec("UPDATE jobs SET status = 'running' WHERE id = $job->id");
        executeCrawl($job);
        $db->exec("UPDATE jobs SET status = 'completed' WHERE id = $job->id");
    } else {
        // 3. "Pas de job, j'attends 2 secondes et je redemande"
        sleep(2);
    }
}
```

---

## ⚠️ Le Problème : Plusieurs Workers = Bagarre

### Le scénario catastrophe

Imagine que Worker 1 et Worker 2 regardent le tableau **en même temps** :

```
Temps 0ms:
  Worker 1: "SELECT * FROM jobs WHERE status = 'queued'" → Voit Job 1
  Worker 2: "SELECT * FROM jobs WHERE status = 'queued'" → Voit Job 1 aussi !

Temps 1ms:
  Worker 1: "UPDATE jobs SET status = 'running' WHERE id = 1"
  Worker 2: "UPDATE jobs SET status = 'running' WHERE id = 1"
  
  → LES DEUX font le même job ! 💥
```

### La solution : FOR UPDATE SKIP LOCKED

PostgreSQL a une fonctionnalité magique pour éviter ça :

```sql
SELECT * FROM jobs 
WHERE status = 'queued' 
ORDER BY created_at ASC 
LIMIT 1 
FOR UPDATE SKIP LOCKED
```

**Qu'est-ce que ça fait ?**

- `FOR UPDATE` : "Je réserve cette ligne, personne d'autre ne peut la toucher"
- `SKIP LOCKED` : "Si une ligne est déjà réservée par quelqu'un, passe à la suivante"

```
Temps 0ms:
  Worker 1: SELECT ... FOR UPDATE SKIP LOCKED → Voit Job 1, le VERROUILLE
  Worker 2: SELECT ... FOR UPDATE SKIP LOCKED → Job 1 est verrouillé, donc il prend Job 2

  → Chacun son job, pas de bagarre ! ✅
```

---

## 💥 Pourquoi ça plantait en prod ?

### Le problème : les Checkpoints PostgreSQL

PostgreSQL doit régulièrement sauvegarder ses données sur le disque. Ça s'appelle un **checkpoint**.

```
┌─────────────────────────────────────────────────────────────┐
│                      CHECKPOINT                              │
│                                                              │
│  PostgreSQL: "Attendez tous, je sauvegarde 320 MB           │
│               sur le disque..."                              │
│                                                              │
│  Temps estimé: 30 secondes à 5 MINUTES                      │
│  (selon la vitesse du disque)                               │
└─────────────────────────────────────────────────────────────┘
```

Pendant ce temps, **toutes les requêtes sont ralenties**.

### Ce qui s'est passé

1. Tu avais un `statement_timeout = 60s` (une requête ne peut pas durer plus de 60 secondes)
2. Un checkpoint massif a commencé (320 MB à écrire)
3. Sur ton VPS Hostinger (disques lents), ça a pris **plus de 60 secondes**
4. La requête de polling a timeout → **ERREUR**
5. Comme les 4 workers faisaient tous du polling, **3 ont crashé en même temps**

```
Timeline du crash:

00:00  - Checkpoint PostgreSQL commence
00:30  - Workers en train de poller, requêtes bloquées...
01:00  - TIMEOUT ! 3 workers crashent
01:30  - Checkpoint termine (trop tard)
```

---

## 🔧 Les Corrections Appliquées

### 1. Désactiver le timeout pour le polling

**Fichier : `app/bin/worker.php`**

```php
// AVANT le polling, on désactive le timeout
$db->exec("SET statement_timeout = '0'");   // 0 = pas de limite
$db->exec("SET lock_timeout = '30s'");      // Mais on garde une limite sur les locks

// Faire le polling
$stmt = $db->query("SELECT * FROM jobs ... FOR UPDATE SKIP LOCKED");

// APRÈS le polling, on remet des timeouts normaux
$db->exec("SET statement_timeout = '60s'");
$db->exec("SET lock_timeout = '10s'");
```

**Pourquoi ?**
- Le polling peut attendre longtemps pendant un checkpoint, c'est OK
- Mais les autres requêtes (INSERT, UPDATE de données) doivent avoir un timeout

### 2. Reconnexion automatique

Si la connexion à la base de données est coupée, le worker se reconnecte tout seul :

```php
function isConnectionAlive($pdo) {
    try {
        $pdo->query("SELECT 1");  // Petit test rapide
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Dans la boucle principale
if (!isConnectionAlive($db)) {
    echo "Connexion perdue, je me reconnecte...\n";
    PostgresDatabase::resetInstance();  // Forcer nouvelle connexion
    $db = PostgresDatabase::getInstance()->getConnection();
}
```

### 3. Compteur d'erreurs + Backoff exponentiel

Si le worker a trop d'erreurs d'affilée, il se redémarre :

```php
$consecutiveErrors = 0;
$maxConsecutiveErrors = 10;

try {
    // Polling...
    $consecutiveErrors = 0;  // Succès, on reset le compteur
} catch (Exception $e) {
    $consecutiveErrors++;
    
    if ($consecutiveErrors >= $maxConsecutiveErrors) {
        echo "Trop d'erreurs, je redémarre...\n";
        exit(1);  // Docker va me relancer automatiquement
    }
    
    // Backoff exponentiel : attendre de plus en plus longtemps
    // 2s, 4s, 8s, 16s, max 30s
    $sleepTime = min(30, pow(2, $consecutiveErrors));
    sleep($sleepTime);
}
```

**C'est quoi le backoff exponentiel ?**

```
Erreur 1 → Attendre 2 secondes
Erreur 2 → Attendre 4 secondes
Erreur 3 → Attendre 8 secondes
Erreur 4 → Attendre 16 secondes
Erreur 5+ → Attendre 30 secondes (max)
```

L'idée : si la base de données a un problème, on ne la bombarde pas de requêtes. On attend de plus en plus longtemps pour lui laisser le temps de récupérer.

### 4. Heartbeat (battement de coeur)

Le worker affiche régulièrement qu'il est vivant :

```php
$pollCount = 0;
$heartbeatInterval = 100;  // Tous les 100 polls

while (true) {
    $pollCount++;
    
    if ($pollCount % $heartbeatInterval === 0) {
        echo "[Worker] ♥ Alive - $pollCount polls effectués\n";
    }
    
    // ... polling ...
}
```

**Pourquoi ?**
- Pour voir dans les logs que le worker tourne bien
- Si tu ne vois plus de heartbeat, c'est que le worker est bloqué

---

## 🔄 Le Problème des Transactions (Erreur 25P02)

### C'est quoi une transaction ?

Une transaction, c'est un groupe d'opérations qui doivent **toutes réussir** ou **toutes échouer** :

```php
$db->beginTransaction();  // "Je commence un groupe d'opérations"

try {
    $db->exec("INSERT INTO pages ...");
    $db->exec("INSERT INTO links ...");
    $db->exec("UPDATE crawl ...");
    
    $db->commit();  // "Tout a marché, je valide tout"
} catch (Exception $e) {
    $db->rollBack();  // "Y'a eu un problème, j'annule tout"
}
```

### Le problème : transaction "abortée"

Quand une erreur se produit **dans** une transaction, PostgreSQL la met en état "aborted" :

```
┌─────────────────────────────────────────────────────────────┐
│                    TRANSACTION ABORTÉE                       │
│                                                              │
│  État: "J'ai eu une erreur. Je refuse toute nouvelle        │
│         commande jusqu'à ce que tu fasses ROLLBACK."        │
│                                                              │
│  Toute requête → ERREUR 25P02                               │
└─────────────────────────────────────────────────────────────┘
```

**L'erreur qu'on voyait :**
```
SQLSTATE[25P02]: In failed sql transaction: current transaction is aborted, 
commands ignored until end of transaction block
```

### La solution : nettoyer l'état

**Fichier : `app/Database/DeadlockRetry.php`**

```php
protected function cleanupTransactionState(PDO $pdo): void
{
    try {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();  // "OK PostgreSQL, j'annule la transaction"
        }
    } catch (\Exception $e) {
        // Ignorer les erreurs, on veut juste nettoyer
    }
}
```

On appelle cette fonction quand on détecte l'erreur 25P02, avant de réessayer.

---

## 📊 Récapitulatif des Timeouts

| Paramètre | Valeur | Quand | Pourquoi |
|-----------|--------|-------|----------|
| `statement_timeout` | `0` (désactivé) | Pendant le polling | Permettre d'attendre les checkpoints |
| `statement_timeout` | `60s` | Reste du temps | Éviter les requêtes infinies |
| `lock_timeout` | `30s` | Pendant le polling | Éviter d'attendre un lock infini |
| `lock_timeout` | `10s` | Reste du temps | Valeur normale |

---

## 🖼️ Schéma Global

```
┌──────────────────────────────────────────────────────────────────┐
│                         SCOUTER                                   │
├──────────────────────────────────────────────────────────────────┤
│                                                                   │
│   ┌─────────┐  ┌─────────┐  ┌─────────┐  ┌─────────┐            │
│   │Worker 1 │  │Worker 2 │  │Worker 3 │  │Worker 4 │            │
│   └────┬────┘  └────┬────┘  └────┬────┘  └────┬────┘            │
│        │            │            │            │                   │
│        │  POLLING   │  POLLING   │  POLLING   │                  │
│        │ (2s loop)  │ (2s loop)  │ (2s loop)  │                  │
│        │            │            │            │                   │
│        └────────────┴─────┬──────┴────────────┘                  │
│                           │                                       │
│                           ▼                                       │
│              ┌────────────────────────┐                          │
│              │      PostgreSQL        │                          │
│              │                        │                          │
│              │  ┌──────────────────┐  │                          │
│              │  │   Table "jobs"   │  │                          │
│              │  │                  │  │                          │
│              │  │ • queued         │  │                          │
│              │  │ • running        │  │                          │
│              │  │ • completed      │  │                          │
│              │  │ • failed         │  │                          │
│              │  └──────────────────┘  │                          │
│              │                        │                          │
│              │  Checkpoints           │                          │
│              │  (sauvegarde disque)   │                          │
│              └────────────────────────┘                          │
│                                                                   │
└──────────────────────────────────────────────────────────────────┘
```

---

## ✅ Ce qui protège maintenant contre les crashes

1. **Pas de timeout sur le polling** → Peut attendre les checkpoints
2. **Reconnexion automatique** → Survit aux coupures réseau
3. **Backoff exponentiel** → Ne bombarde pas la DB en cas de problème
4. **Compteur d'erreurs** → Redémarre si trop de problèmes
5. **Heartbeat** → On voit que le worker est vivant
6. **Nettoyage des transactions** → Récupère après une erreur 25P02

---

## 📁 Fichiers impliqués

| Fichier | Rôle |
|---------|------|
| `app/bin/worker.php` | Boucle principale de polling |
| `app/Database/PostgresDatabase.php` | Connexion singleton + resetInstance() |
| `app/Database/DeadlockRetry.php` | Retry automatique + nettoyage transactions |
| `app/Job/JobManager.php` | Création des jobs + index |

---

## 🔍 Comment débugger

### Voir les logs des workers
```bash
docker compose logs -f worker
```

### Voir si un checkpoint est en cours
```sql
SELECT * FROM pg_stat_bgwriter;
-- Regarder checkpoints_timed et checkpoints_req
```

### Voir les transactions actives
```sql
SELECT pid, state, query, now() - query_start AS duration
FROM pg_stat_activity
WHERE state != 'idle';
```

### Voir les locks
```sql
SELECT * FROM pg_locks WHERE NOT granted;
```

---

## 📚 Glossaire

| Terme | Définition |
|-------|------------|
| **Polling** | Demander régulièrement s'il y a du travail |
| **Worker** | Programme qui exécute les jobs |
| **Checkpoint** | Sauvegarde des données PostgreSQL sur disque |
| **Transaction** | Groupe d'opérations atomiques |
| **Deadlock** | Deux processus qui s'attendent mutuellement |
| **TTFB** | Time To First Byte - temps avant première réponse |
| **Backoff** | Attendre de plus en plus longtemps entre les tentatives |
| **FOR UPDATE SKIP LOCKED** | Verrouiller une ligne ou passer à la suivante |

---

*Document créé le 30/01/2026 suite aux problèmes de stabilité en production.*
