<?php

use App\Database\CategoryRepository;

/**
 * Tests pour CategoryRepository
 * Utilise SQLite en mémoire pour simuler la base de données
 */

function createTestDb(): PDO
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Créer les tables nécessaires
    $db->exec("
        CREATE TABLE project_categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            color TEXT DEFAULT '#4ECDC4',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    $db->exec("
        CREATE TABLE project_category_links (
            project_id INTEGER NOT NULL,
            category_id INTEGER NOT NULL,
            PRIMARY KEY (project_id, category_id)
        )
    ");
    
    return $db;
}

describe('CategoryRepository - Create', function () {

    it('creates a category with default color', function () {
        $db = createTestDb();
        $repo = new CategoryRepository($db);
        
        $id = $repo->create(1, 'E-Commerce');
        
        expect($id)->toBeGreaterThan(0);
        
        $cat = $repo->getById($id);
        expect($cat->name)->toBe('E-Commerce');
        expect($cat->color)->toBe('#4ECDC4');
        expect($cat->user_id)->toBe(1);
    });

    it('creates a category with custom color', function () {
        $db = createTestDb();
        $repo = new CategoryRepository($db);
        
        $id = $repo->create(1, 'Blog', '#FF5733');
        
        $cat = $repo->getById($id);
        expect($cat->name)->toBe('Blog');
        expect($cat->color)->toBe('#FF5733');
    });

});

describe('CategoryRepository - Read', function () {

    it('getForUser returns categories for specific user', function () {
        $db = createTestDb();
        $repo = new CategoryRepository($db);
        
        // User 1 categories
        $repo->create(1, 'E-Commerce');
        $repo->create(1, 'Blog');
        
        // User 2 category
        $repo->create(2, 'Portfolio');
        
        $user1Cats = $repo->getForUser(1);
        $user2Cats = $repo->getForUser(2);
        
        expect($user1Cats)->toHaveCount(2);
        expect($user2Cats)->toHaveCount(1);
    });

    it('getForUser returns categories sorted by name', function () {
        $db = createTestDb();
        $repo = new CategoryRepository($db);
        
        $repo->create(1, 'Zeta');
        $repo->create(1, 'Alpha');
        $repo->create(1, 'Beta');
        
        $cats = $repo->getForUser(1);
        
        expect($cats[0]->name)->toBe('Alpha');
        expect($cats[1]->name)->toBe('Beta');
        expect($cats[2]->name)->toBe('Zeta');
    });

    it('getForUser includes project_count', function () {
        $db = createTestDb();
        $repo = new CategoryRepository($db);
        
        $catId = $repo->create(1, 'E-Commerce');
        
        // Link some projects
        $db->exec("INSERT INTO project_category_links (project_id, category_id) VALUES (1, $catId)");
        $db->exec("INSERT INTO project_category_links (project_id, category_id) VALUES (2, $catId)");
        
        $cats = $repo->getForUser(1);
        
        expect($cats[0]->project_count)->toBe(2);
    });

    it('getById returns null for non-existent category', function () {
        $db = createTestDb();
        $repo = new CategoryRepository($db);
        
        $cat = $repo->getById(999);
        
        expect($cat)->toBeNull();
    });

    it('getById with userId filters by user', function () {
        $db = createTestDb();
        $repo = new CategoryRepository($db);
        
        $catId = $repo->create(1, 'E-Commerce');
        
        // User 1 can see it
        expect($repo->getById($catId, 1))->not->toBeNull();
        
        // User 2 cannot see it
        expect($repo->getById($catId, 2))->toBeNull();
    });

});

describe('CategoryRepository - Update', function () {

    it('updates category name', function () {
        $db = createTestDb();
        $repo = new CategoryRepository($db);
        
        $id = $repo->create(1, 'E-Commerce');
        $repo->update($id, 1, 'Boutique', null);
        
        $cat = $repo->getById($id);
        expect($cat->name)->toBe('Boutique');
        expect($cat->color)->toBe('#4ECDC4'); // Color unchanged
    });

    it('updates category color', function () {
        $db = createTestDb();
        $repo = new CategoryRepository($db);
        
        $id = $repo->create(1, 'E-Commerce');
        $repo->update($id, 1, null, '#FF0000');
        
        $cat = $repo->getById($id);
        expect($cat->name)->toBe('E-Commerce'); // Name unchanged
        expect($cat->color)->toBe('#FF0000');
    });

    it('does not update other user category', function () {
        $db = createTestDb();
        $repo = new CategoryRepository($db);
        
        $id = $repo->create(1, 'E-Commerce');
        $repo->update($id, 2, 'Hacked', '#000000'); // User 2 tries to update
        
        $cat = $repo->getById($id);
        expect($cat->name)->toBe('E-Commerce'); // Unchanged
    });

});

describe('CategoryRepository - Delete', function () {

    it('deletes category for owner', function () {
        $db = createTestDb();
        $repo = new CategoryRepository($db);
        
        $id = $repo->create(1, 'E-Commerce');
        $repo->delete($id, 1);
        
        expect($repo->getById($id))->toBeNull();
    });

    it('does not delete other user category', function () {
        $db = createTestDb();
        $repo = new CategoryRepository($db);
        
        $id = $repo->create(1, 'E-Commerce');
        $repo->delete($id, 2); // User 2 tries to delete
        
        expect($repo->getById($id))->not->toBeNull();
    });

});

describe('CategoryRepository - Project Assignment', function () {

    it('assigns project to category', function () {
        $db = createTestDb();
        $repo = new CategoryRepository($db);
        
        $catId = $repo->create(1, 'E-Commerce');
        $repo->assignProject(100, $catId, 1);
        
        $cats = $repo->getForProject(100, 1);
        expect($cats)->toHaveCount(1);
        expect($cats[0]->name)->toBe('E-Commerce');
    });

    it('throws exception when assigning to non-existent category', function () {
        $db = createTestDb();
        $repo = new CategoryRepository($db);
        
        expect(fn() => $repo->assignProject(100, 999, 1))
            ->toThrow(Exception::class, 'Catégorie non trouvée ou non autorisée');
    });

    it('throws exception when assigning to other user category', function () {
        $db = createTestDb();
        $repo = new CategoryRepository($db);
        
        $catId = $repo->create(1, 'E-Commerce'); // User 1's category
        
        expect(fn() => $repo->assignProject(100, $catId, 2)) // User 2 tries
            ->toThrow(Exception::class, 'Catégorie non trouvée ou non autorisée');
    });

    it('removes project from category', function () {
        $db = createTestDb();
        $repo = new CategoryRepository($db);
        
        $catId = $repo->create(1, 'E-Commerce');
        $repo->assignProject(100, $catId, 1);
        $repo->removeProject(100, $catId, 1);
        
        $cats = $repo->getForProject(100, 1);
        expect($cats)->toHaveCount(0);
    });

    it('setForProject replaces all categories', function () {
        $db = createTestDb();
        $repo = new CategoryRepository($db);
        
        $cat1 = $repo->create(1, 'E-Commerce');
        $cat2 = $repo->create(1, 'Blog');
        $cat3 = $repo->create(1, 'Portfolio');
        
        // Initially assign cat1 and cat2
        $repo->setForProject(100, [$cat1, $cat2], 1);
        expect($repo->getForProject(100, 1))->toHaveCount(2);
        
        // Replace with only cat3
        $repo->setForProject(100, [$cat3], 1);
        $cats = $repo->getForProject(100, 1);
        expect($cats)->toHaveCount(1);
        expect($cats[0]->name)->toBe('Portfolio');
    });

    it('setForProject with empty array removes all categories', function () {
        $db = createTestDb();
        $repo = new CategoryRepository($db);
        
        $catId = $repo->create(1, 'E-Commerce');
        $repo->assignProject(100, $catId, 1);
        
        $repo->setForProject(100, [], 1);
        
        expect($repo->getForProject(100, 1))->toHaveCount(0);
    });

});

describe('CategoryRepository - Project Count Display', function () {

    it('project_count is 0 for new category', function () {
        $db = createTestDb();
        $repo = new CategoryRepository($db);
        
        $repo->create(1, 'Empty Category');
        
        $cats = $repo->getForUser(1);
        expect($cats[0]->project_count)->toBe(0);
    });

    it('project_count increments correctly', function () {
        $db = createTestDb();
        $repo = new CategoryRepository($db);
        
        $catId = $repo->create(1, 'E-Commerce');
        
        // Add 3 projects
        $repo->assignProject(1, $catId, 1);
        $repo->assignProject(2, $catId, 1);
        $repo->assignProject(3, $catId, 1);
        
        $cats = $repo->getForUser(1);
        expect($cats[0]->project_count)->toBe(3);
    });

    it('project_count decrements when project removed', function () {
        $db = createTestDb();
        $repo = new CategoryRepository($db);
        
        $catId = $repo->create(1, 'E-Commerce');
        $repo->assignProject(1, $catId, 1);
        $repo->assignProject(2, $catId, 1);
        
        $repo->removeProject(1, $catId, 1);
        
        $cats = $repo->getForUser(1);
        expect($cats[0]->project_count)->toBe(1);
    });

});
