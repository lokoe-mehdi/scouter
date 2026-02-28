<?php

use App\Database\ProjectRepository;
use App\Database\PostgresDatabase;

beforeEach(function () {
    $this->repo = new ProjectRepository();
    $this->db = PostgresDatabase::getInstance()->getConnection();
});

test('can set and get categorization config for a project', function () {
    // Create a test project
    $projectId = $this->repo->create(1, 'Test Project for Categorization');

    // Set categorization config
    $yaml = "Blog:\n  dom: example.com\n  include:\n    - '/blog.*'\n  color: '#FF5733'";
    $this->repo->setCategorizationConfig($projectId, $yaml);

    // Get categorization config
    $retrieved = $this->repo->getCategorizationConfig($projectId);

    expect($retrieved)->toBe($yaml);

    // Cleanup
    $this->repo->delete($projectId);
});

test('returns null for project without categorization config', function () {
    // Create a project without config
    $projectId = $this->repo->create(1, 'Test Project Without Config');

    $config = $this->repo->getCategorizationConfig($projectId);

    expect($config)->toBeNull();

    // Cleanup
    $this->repo->delete($projectId);
});

test('can update existing categorization config', function () {
    // Create project with initial config
    $projectId = $this->repo->create(1, 'Test Project Update Config');
    $initialYaml = "Category1:\n  dom: test.com\n  include: ['/test.*']";
    $this->repo->setCategorizationConfig($projectId, $initialYaml);

    // Update with new config
    $newYaml = "Category2:\n  dom: test.com\n  include: ['/new.*']";
    $this->repo->setCategorizationConfig($projectId, $newYaml);

    // Verify updated config
    $retrieved = $this->repo->getCategorizationConfig($projectId);

    expect($retrieved)->toBe($newYaml);
    expect($retrieved)->not->toBe($initialYaml);

    // Cleanup
    $this->repo->delete($projectId);
});
