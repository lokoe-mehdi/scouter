# Spécification Technique : Système de Projets et Permissions

Ce document détaille l'implémentation de la notion de "Projet" au-dessus des crawls, la gestion des rôles utilisateurs (Admin, User, Viewer) et le système de partage.

---

## Phase 1 : Base de Données et Migration

Cette phase structure la donnée. Elle doit être exécutée avant tout changement de code applicatif.

### 1.1 Schéma SQL (`init.sql`)
Mise à jour du fichier `docker/postgres/init.sql` pour inclure :

1.  **Modification table `users`**
    - Ajout colonne `role` ENUM ou VARCHAR ('admin', 'user', 'viewer').
    - Défaut : 'user'.

2.  **Création table `projects`**
    - `id` (PK)
    - `user_id` (FK vers users.id) : Le propriétaire.
    - `name` (VARCHAR) : Le nom du projet (souvent le domaine).
    - `created_at` (TIMESTAMP).

3.  **Création table `project_shares`**
    - `project_id` (FK)
    - `user_id` (FK) : L'utilisateur avec qui le projet est partagé.
    - `created_at` (TIMESTAMP).
    - *Note : Les partages sont en lecture seule par défaut selon la spec.*

4.  **Modification table `crawls`**
    - Ajout colonne `project_id` (FK vers projects.id).
    - *Note : On garde `domain` pour l'historique/cache, mais le `project_id` devient le lien parent.*
    - **Important** : La suppression d'un projet entraîne la suppression en cascade de tous ses crawls (`ON DELETE CASCADE`).

### 1.2 Script de Migration (`migrations/YYYY-MM-DD-migration-projets.php`)
Un script robuste pour migrer les données existantes :

1.  Récupérer tous les `crawls` existants.
2.  Récupérer le **premier utilisateur** de la table `users` (ID min) pour lui attribuer la paternité par défaut (Admin par défaut).
3.  Grouper les crawls par `domain`.
4.  Pour chaque domaine unique :
    - Créer une entrée dans `projects` (Nom = Domain, User = Premier User).
    - Récupérer l'ID du nouveau projet.
    - Mettre à jour tous les `crawls` de ce domaine avec ce `project_id`.

---

## Phase 2 : Backend & Authentification

Adaptation du coeur de l'application pour gérer la logique métier.

### 2.1 Mise à jour de `App\Auth`
Ajout de méthodes de vérification des droits :
- `hasRole(string $role)` : Vérifie si l'user a le rôle requis.
- `isAdmin()` : Helper pour le rôle admin.
- `canAccessProject(int $projectId)` :
    - Retourne `true` si : Admin OU Propriétaire OU Partagé via `project_shares`.
- `canManageProject(int $projectId)` :
    - Retourne `true` si : Admin OU Propriétaire.
    - *Refusé pour les utilisateurs en partage (Lecture seule).*

### 2.2 Mise à jour de `App\GlobalDatabase`
Ajout des requêtes SQL nécessaires :
- `createProject($userId, $name)`
- `getProjectsForUser($userId)` : Retourne les projets dont il est propriétaire.
- `getSharedProjectsForUser($userId)` : Retourne les projets partagés avec lui.
- `getAllProjectsWithOwner()` : Pour les Admins (vue globale).
- `shareProject($projectId, $targetUserId)`
- `unshareProject($projectId, $targetUserId)`
- `getProjectShares($projectId)` : Liste des utilisateurs ayant accès à un projet.

---

## Phase 3 : Gestion des Utilisateurs (Admin)

Permettre la gestion des nouveaux rôles.

### 3.1 Création / Édition User
- Dans la modale de création d'utilisateur (et une nouvelle modale d'édition) :
    - Ajouter un `<select>` stylisé pour le Rôle (même select stylisé que ceux déjà utilisé dans la modal de création de crawl):
        - **Admin** : Accès total.
        - **User** : Crée ses projets, voit les partagés.
        - **Viewer** : Ne crée rien, voit uniquement les partagés.
- Backend : Mettre à jour `createUser` et `updateUser` pour enregistrer le rôle.

---

## Phase 4 : Interface Dashboard (Index)

Refonte de la page d'accueil pour refléter la hiérarchie.

### 4.1 Séparation des Vues
Sur `index.php`, diviser l'affichage en sections selon le rôle :

- **Si Admin** :
    - Section "Mes Projets".
    - Section "Tous les autres Projets" (Groupés par propriétaire "Projet de [Email]").
- **Si User** :
    - Section "Mes Projets".
    - Section "Partagés avec moi" (Indiquer le propriétaire).
- **Si Viewer** :
    - Uniquement Section "Partagés avec moi".
    - Masquer le bouton "Nouveau Crawl".

### 4.2 Composant "Carte Projet"
Mettre à jour l'affichage des projets (anciennement groupement par domaine) :
- Afficher le nom du projet.
- Bouton "Paramètres" (Roue crantée) :
    - **Si Propriétaire/Admin** : Ouvre la modale de gestion (Renommer, Partager, Supprimer).
    - **Si Partagé** : Bouton caché ou désactivé.

### 4.3 Modale de Partage
Créer une interface dans les paramètres du projet :
- Liste des utilisateurs ayant accès (avec bouton "Retirer").
- Select pour ajouter un nouvel utilisateur (liste des users sauf soi-même).

---

## Phase 5 : Sécurisation & Logique Métier

Verrouillage des actions critiques.

### 5.1 Middleware de Sécurité
Dans les fichiers PHP d'action (`web/api/delete-crawl.php`, `save-categorization.php`, etc.) :

```php
$auth->requireLogin();
$project = $db->getProject($projectId);

// Pour actions destructives (Delete, Categorize)
if (!$auth->canManageProject($project->id)) {
    http_response_code(403);
    die(json_encode(['error' => 'Droit insuffisant (Lecture seule)']));
}
```
Il faut aussi empechr l'acces en lectuere quand on est utilisateur ou lecteur mais qu'on a pas le partage de ce projeht, dazns ce cas la même avec l'url on doit avoir une 403 si on essaye de voir une vue du dahboard ou le monitor ou même une url d'export ou d'api lié au projet ou on a pas de droit ou autre, faut  que la sécurité soit garanti

Faut aussi sécurisé tous les endpoint api selon si on a le droit d'utiliser ça avec notre session.

### 5.2 Adaptation UX (Frontend)
- **Page Dashboard** (`index.php`) : Masquer le bouton "Supprimer" si lecture seule.
- **Page Catégorisation** :
    - Si lecture seule : Désactiver le Drag & Drop, masquer le bouton "Sauvegarder".
    - Afficher un bandeau "Mode Lecture Seule".

### 5.3 Création de Crawl
- Lors de la création d'un crawl (`start-crawl.php`) :
    - Vérifier si un projet existe déjà pour ce domaine et si l'user en est propriétaire.
    - Sinon, créer automatiquement le projet lié à l'utilisateur courant.
    - *Note : Un Viewer ne doit pas pouvoir déclencher cette action.*


---

## Résumé des fichiers à toucher

1.  `docker/postgres/init.sql` (Schema)
2.  `migrations/` (Nouveau fichier de migration)
3.  `app/Auth.php` (Logique Rôles)
4.  `app/GlobalDatabase.php` (Requêtes Projets/Partages)
5.  `web/index.php` (Dashboard divisé)
6.  `web/api/` (Sécurisation des endpoints)
7.  `web/components/` (Modales Partage & User Edit)
