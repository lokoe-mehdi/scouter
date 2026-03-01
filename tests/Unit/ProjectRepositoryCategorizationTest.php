<?php

use App\Database\ProjectRepository;
use App\Database\PostgresDatabase;

beforeEach(function () {
    $this->repo = new ProjectRepository();
    $this->db = PostgresDatabase::getInstance()->getConnection();

    // Create a test user (FK constraint on projects.user_id)
    $this->db->exec("INSERT INTO users (id, email, password_hash) VALUES (9999, 'test@test.com', 'hash') ON CONFLICT (id) DO NOTHING");
    $this->testUserId = 9999;
});

afterEach(function () {
    // Cleanup cascades to projects
    $this->db->exec("DELETE FROM users WHERE id = 9999");
});

test('can set and get categorization config for a project', function () {
    $projectId = $this->repo->create($this->testUserId, 'Test Project for Categorization');

    $yaml = "Blog:\n  dom: example.com\n  include:\n    - '/blog.*'\n  color: '#FF5733'";
    $this->repo->setCategorizationConfig($projectId, $yaml);

    $retrieved = $this->repo->getCategorizationConfig($projectId);

    expect($retrieved)->toBe($yaml);
});

test('returns null for project without categorization config', function () {
    $projectId = $this->repo->create($this->testUserId, 'Test Project Without Config');

    $config = $this->repo->getCategorizationConfig($projectId);

    expect($config)->toBeNull();
});

test('can update existing categorization config', function () {
    $projectId = $this->repo->create($this->testUserId, 'Test Project Update Config');
    $initialYaml = "Category1:\n  dom: test.com\n  include: ['/test.*']";
    $this->repo->setCategorizationConfig($projectId, $initialYaml);

    $newYaml = "Category2:\n  dom: test.com\n  include: ['/new.*']";
    $this->repo->setCategorizationConfig($projectId, $newYaml);

    $retrieved = $this->repo->getCategorizationConfig($projectId);

    expect($retrieved)->toBe($newYaml);
    expect($retrieved)->not->toBe($initialYaml);
});
