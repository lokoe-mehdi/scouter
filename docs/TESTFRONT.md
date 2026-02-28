# Tests Unitaires - Front-End & Application

Ce document liste les tests unitaires à implémenter pour couvrir les fonctionnalités front-end, la gestion des utilisateurs, des jobs, des projets, etc.

---

## 1. Authentification & Autorisation (`AuthTest.php`)

### Authentification
- [ ] `it can login with valid credentials`
- [ ] `it rejects login with invalid password`
- [ ] `it rejects login with non-existent email`
- [ ] `it starts a session on successful login`
- [ ] `it can logout and destroy session`
- [ ] `it redirects to login page when not authenticated`

### Rôles
- [ ] `admin role has full access`
- [ ] `user role can create projects`
- [ ] `viewer role cannot create projects`
- [ ] `viewer role can only view shared projects`
- [ ] `it returns correct role from getCurrentRole()`

### Accès aux projets
- [ ] `owner can access their own project`
- [ ] `user cannot access other users projects`
- [ ] `admin can access all projects`
- [ ] `shared user can access shared project`
- [ ] `viewer can access shared project in read-only`

### Accès aux crawls
- [ ] `canAccessCrawlById returns true for owner`
- [ ] `canAccessCrawlById returns false for non-owner`
- [ ] `canAccessCrawlById returns true for admin`
- [ ] `canManageCrawlById returns true for owner`
- [ ] `canManageCrawlById returns false for viewer`
- [ ] `requireCrawlAccessById throws 403 for unauthorized`

---

## 2. Gestion des Utilisateurs (`UserRepositoryTest.php`)

### CRUD Utilisateurs
- [ ] `it can create a user with email and password`
- [ ] `it hashes password on creation`
- [ ] `it can get user by email`
- [ ] `it can get user by id`
- [ ] `it can update user email`
- [ ] `it can update user role`
- [ ] `it can change user password`
- [ ] `it can delete user`
- [ ] `it returns all users for admin`

### Validation
- [ ] `emailExists returns true for existing email`
- [ ] `emailExists returns false for non-existing email`
- [ ] `it cannot create duplicate email`
- [ ] `countUsers returns correct count`

---

## 3. Gestion des Projets (`ProjectRepositoryTest.php`)

### CRUD Projets
- [ ] `it can create a project for user`
- [ ] `it can get project by id`
- [ ] `it can get projects for user`
- [ ] `it can update project name`
- [ ] `it can delete project`
- [ ] `getOrCreate returns existing project if exists`
- [ ] `getOrCreate creates new project if not exists`

### Partage
- [ ] `it can share project with another user`
- [ ] `it can unshare project`
- [ ] `getSharedForUser returns shared projects`
- [ ] `getShares returns list of users with access`
- [ ] `getAvailableUsersForSharing excludes already shared users`
- [ ] `isOwner returns true for owner`
- [ ] `isOwner returns false for shared user`
- [ ] `userCanAccess returns true for owner`
- [ ] `userCanAccess returns true for shared user`
- [ ] `userCanAccess returns false for unauthorized user`

---

## 4. Gestion des Crawls (`CrawlRepositoryTest.php`)

### CRUD Crawls
- [ ] `it can insert a new crawl`
- [ ] `it can get crawl by id`
- [ ] `it can get crawl by path`
- [ ] `it can update crawl status`
- [ ] `it can update crawl stats`
- [ ] `it can delete crawl`
- [ ] `getByProjectId returns all crawls for project`
- [ ] `getAll returns all crawls`

### Statuts
- [ ] `insert sets status to pending by default`
- [ ] `update can change status to queued`
- [ ] `update can change status to running`
- [ ] `update can change status to stopped`
- [ ] `update can change status to finished`

---

## 5. Gestion des Catégories (`CategoryRepositoryTest.php`)

### CRUD Catégories utilisateur
- [ ] `it can create a category for user`
- [ ] `it can get categories for user`
- [ ] `it can update category name and color`
- [ ] `it can delete category`
- [ ] `getById returns correct category`

### Assignation aux projets
- [ ] `it can assign project to category`
- [ ] `it can remove project from category`
- [ ] `getForProject returns categories for project`
- [ ] `setForProject replaces all categories`
- [ ] `categories are user-specific (isolation)`

---

## 6. Gestion des Jobs (`JobManagerTest.php`)

### CRUD Jobs
- [ ] `it can create a new job`
- [ ] `it can get job by id`
- [ ] `it can get job by project_dir`
- [ ] `it can update job status`
- [ ] `it can add log to job`
- [ ] `it can get logs for job`

### Statuts et transitions
- [ ] `createJob sets status to pending`
- [ ] `updateJobStatus can transition pending to queued`
- [ ] `updateJobStatus can transition queued to running`
- [ ] `updateJobStatus can transition running to stopping`
- [ ] `updateJobStatus can transition stopping to stopped`
- [ ] `updateJobStatus can transition running to completed`

### Nettoyage
- [ ] `cleanupStaleJobs marks orphaned jobs as failed`
- [ ] `cleanupStaleJobs does not affect running jobs with recent activity`

---

## 7. API Endpoints (`ApiTest.php`)

### API Users
- [ ] `POST /api/users.php creates user (admin only)`
- [ ] `GET /api/users.php returns all users (admin only)`
- [ ] `PUT /api/users.php updates user`
- [ ] `DELETE /api/users.php deletes user (admin only)`
- [ ] `users API returns 403 for non-admin`

### API Projects
- [ ] `GET /api/projects.php returns user projects`
- [ ] `POST /api/projects.php/share shares project`
- [ ] `DELETE /api/projects.php/share unshares project`
- [ ] `GET /api/projects.php/shares returns shares`

### API Categories
- [ ] `GET /api/categories.php returns user categories`
- [ ] `POST /api/categories.php creates category`
- [ ] `PUT /api/categories.php updates category`
- [ ] `DELETE /api/categories.php deletes category`

### API Crawls
- [ ] `POST /api/create-project.php creates crawl`
- [ ] `POST /api/start-crawl.php starts crawl`
- [ ] `POST /api/stop-crawl.php stops running crawl`
- [ ] `POST /api/stop-crawl.php cancels pending crawl`
- [ ] `POST /api/resume-crawl.php resumes stopped crawl`
- [ ] `POST /api/duplicate-and-start.php duplicates and starts`
- [ ] `DELETE /api/delete-crawl.php deletes crawl`

### API Stats
- [ ] `GET /api/get-project-stats.php returns stats`
- [ ] `GET /api/get-crawl-info.php returns crawl info`
- [ ] `GET /api/get-running-crawls.php returns running crawls`
- [ ] `GET /api/get-job-status.php returns job status`
- [ ] `GET /api/get-job-logs.php returns job logs`

### Sécurité API
- [ ] `API returns 401 for unauthenticated requests`
- [ ] `API returns 403 for unauthorized project access`
- [ ] `API validates required parameters`

---

## 8. Pages Dashboard (`DashboardPagesTest.php`)

### Protection des pages
- [ ] `dashboard redirects to login if not authenticated`
- [ ] `dashboard returns 403 for unauthorized crawl`
- [ ] `config page returns 403 for viewer`
- [ ] `categorize page returns 403 for viewer`

### Gestion crawl vide
- [ ] `inlinks page shows empty message when no compliant pages`
- [ ] `outlinks page shows empty message when no compliant pages`
- [ ] `seo-tags page shows empty message when no compliant pages`
- [ ] `response-time page shows empty message when no compliant pages`
- [ ] `home page loads even with empty crawl`

---

## 9. Composants UI (`ComponentsTest.php`)

### Sidebar Navigation
- [ ] `sidebar shows correct menu items for user role`
- [ ] `sidebar hides admin menu for non-admin`

### Crawl Panel
- [ ] `crawl panel shows running crawls`
- [ ] `crawl panel can stop crawl`
- [ ] `crawl panel can dismiss notification`
- [ ] `crawl panel updates stats in real-time`

### Project Cards
- [ ] `project card shows correct status badge`
- [ ] `project card shows crawl count`
- [ ] `project card shows category if assigned`

---

## 10. Intégration Base de Données (`DatabaseIntegrationTest.php`)

### Transactions
- [ ] `failed crawl insert rolls back transaction`
- [ ] `failed project creation rolls back transaction`

### Contraintes
- [ ] `cannot delete user with projects`
- [ ] `deleting project cascades to crawls`
- [ ] `deleting crawl cascades to pages and links`

### Performance
- [ ] `getByProjectId uses index efficiently`
- [ ] `pages query with crawl_id uses partition`

---

## Résumé

| Catégorie | Nombre de tests |
|-----------|-----------------|
| Authentification & Autorisation | 18 |
| Utilisateurs | 12 |
| Projets | 16 |
| Crawls | 12 |
| Catégories | 10 |
| Jobs | 12 |
| API Endpoints | 24 |
| Dashboard Pages | 9 |
| Composants UI | 8 |
| Intégration DB | 7 |
| **TOTAL** | **128 tests** |

---

## Notes d'implémentation

1. **Mocking** : Utiliser des mocks pour les dépendances (PDO, sessions)
2. **Base de test** : Utiliser une base PostgreSQL de test ou SQLite in-memory
3. **Fixtures** : Créer des fixtures pour users, projects, crawls de test
4. **Isolation** : Chaque test doit être indépendant (cleanup après)
5. **Pest** : Utiliser la syntaxe Pest pour la cohérence avec les tests existants
