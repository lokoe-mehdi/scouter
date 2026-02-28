<?php
/**
 * Script de diagnostic et d'auto-réparation pour Scouter
 * 
 * Vérifie:
 * 1. La connexion base de données
 * 2. La structure des tables (migrations)
 * 3. Les permissions des dossiers
 * 4. La présence des dépendances
 * 5. La configuration PHP
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Database\PostgresDatabase;

$green = "\033[32m";
$red = "\033[31m";
$yellow = "\033[33m";
$reset = "\033[0m";

echo "=== SCOUTER DIAGNOSTIC TOOL ===\n\n";

// 1. PHP Version
echo "1. Checking PHP Environment...\n";
if (version_compare(PHP_VERSION, '8.0.0') >= 0) {
    echo "   {$green}✓ PHP Version: " . PHP_VERSION . "{$reset}\n";
} else {
    echo "   {$red}✗ PHP Version: " . PHP_VERSION . " (Requires 8.0+){$reset}\n";
}

$requiredExtensions = ['pdo', 'pdo_pgsql', 'curl', 'json', 'mbstring', 'pcntl'];
foreach ($requiredExtensions as $ext) {
    if (extension_loaded($ext)) {
        echo "   {$green}✓ Extension $ext loaded{$reset}\n";
    } else {
        echo "   {$red}✗ Extension $ext MISSING{$reset}\n";
    }
}

// 2. Database Connection
echo "\n2. Checking Database Connection...\n";
try {
    $db = PostgresDatabase::getInstance()->getConnection();
    echo "   {$green}✓ Connection successful{$reset}\n";
} catch (Exception $e) {
    echo "   {$red}✗ Connection failed: " . $e->getMessage() . "{$reset}\n";
    echo "     Check your DATABASE_URL environment variable.\n";
    exit(1);
}

// 3. Database Schema
echo "\n3. Checking Key Tables...\n";
$tables = ['users', 'jobs', 'job_logs', 'crawls', 'projects', 'project_shares'];
$missingTables = [];

foreach ($tables as $table) {
    $stmt = $db->query("SELECT to_regclass('public.$table')");
    if ($stmt->fetchColumn()) {
        echo "   {$green}✓ Table '$table' exists{$reset}\n";
    } else {
        echo "   {$red}✗ Table '$table' MISSING{$reset}\n";
        $missingTables[] = $table;
    }
}

if (!empty($missingTables)) {
    echo "\n   {$yellow}Attempting to fix missing tables...{$reset}\n";
    // Here we could run migrations, but for now we just warn
    echo "   Run migrations manually using: php scripts/migrate.php\n";
}

// 4. Directory Permissions
echo "\n4. Checking Directories...\n";
$dirs = [
    'logs' => __DIR__ . '/logs',
    'web/assets' => __DIR__ . '/web/assets'
];

foreach ($dirs as $name => $path) {
    if (!is_dir($path)) {
        if (mkdir($path, 0755, true)) {
            echo "   {$green}✓ Created directory $name{$reset}\n";
        } else {
            echo "   {$red}✗ Could not create directory $name{$reset}\n";
        }
    } else {
        if (is_writable($path)) {
            echo "   {$green}✓ Directory $name is writable{$reset}\n";
        } else {
            echo "   {$red}✗ Directory $name is NOT writable{$reset}\n";
        }
    }
}

// 5. Check Admin User
echo "\n5. Checking Admin User...\n";
$stmt = $db->query("SELECT count(*) FROM users WHERE role = 'admin'");
$adminCount = $stmt->fetchColumn();

if ($adminCount > 0) {
    echo "   {$green}✓ Admin user exists ($adminCount found){$reset}\n";
} else {
    echo "   {$red}✗ No admin user found{$reset}\n";
    echo "   You can create one using: php scripts/create-demo-user.php\n";
}

echo "\n=== DIAGNOSTIC COMPLETE ===\n";
