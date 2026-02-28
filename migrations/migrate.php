<?php
/**
 * Système de migrations PostgreSQL pour Scouter
 * 
 * Exécute les migrations non encore appliquées.
 * Les fichiers de migration doivent être nommés : YYYY-MM-DD-HH-II-nom-migration.php
 * 
 * Usage: php migrations/migrate.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Database\PostgresDatabase;

echo "\n";
echo "===========================================\n";
echo "  SCOUTER - Migration PostgreSQL\n";
echo "===========================================\n\n";

try {
    $pdo = PostgresDatabase::getInstance()->getConnection();
} catch (Exception $e) {
    echo "❌ Erreur de connexion à PostgreSQL: " . $e->getMessage() . "\n";
    echo "   Vérifiez que PostgreSQL est démarré et accessible.\n\n";
    exit(1);
}

// S'assurer que la table migrations existe
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS migrations (
            id SERIAL PRIMARY KEY,
            name VARCHAR(255) UNIQUE NOT NULL,
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
} catch (Exception $e) {
    echo "❌ Erreur lors de la création de la table migrations: " . $e->getMessage() . "\n";
    exit(1);
}

// Récupérer les migrations déjà exécutées
$executed = [];
$stmt = $pdo->query("SELECT name FROM migrations ORDER BY name");
while ($row = $stmt->fetch(PDO::FETCH_OBJ)) {
    $executed[$row->name] = true;
}

echo "📋 Migrations déjà exécutées: " . count($executed) . "\n\n";

// Scanner le dossier migrations pour les fichiers de migration
$migrationsDir = __DIR__;
$files = glob($migrationsDir . '/[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]-[0-9][0-9]-[0-9][0-9]-*.php');

if (empty($files)) {
    echo "✓ Aucune migration à exécuter.\n\n";
    exit(0);
}

// Trier les fichiers par nom (ordre chronologique)
sort($files);

$migrationsToRun = [];
foreach ($files as $file) {
    $filename = basename($file);
    $migrationName = pathinfo($filename, PATHINFO_FILENAME);
    
    if (!isset($executed[$migrationName])) {
        $migrationsToRun[] = [
            'file' => $file,
            'name' => $migrationName
        ];
    }
}

if (empty($migrationsToRun)) {
    echo "✓ Base de données à jour. Aucune nouvelle migration.\n\n";
    exit(0);
}

echo "🔄 " . count($migrationsToRun) . " migration(s) à exécuter:\n";
foreach ($migrationsToRun as $m) {
    echo "   - " . $m['name'] . "\n";
}
echo "\n";

// Exécuter chaque migration
$success = 0;
$failed = 0;

foreach ($migrationsToRun as $migration) {
    echo "▶ Exécution: " . $migration['name'] . "... ";
    
    try {
        // Inclure le fichier de migration
        // Le fichier doit définir une fonction migrate($pdo) ou exécuter directement
        $result = include $migration['file'];
        
        // Si la migration retourne false, c'est un échec
        if ($result === false) {
            throw new Exception("La migration a retourné false");
        }
        
        // Enregistrer la migration comme exécutée
        $stmt = $pdo->prepare("INSERT INTO migrations (name) VALUES (:name)");
        $stmt->execute([':name' => $migration['name']]);
        
        echo "✓\n";
        $success++;
        
    } catch (Exception $e) {
        echo "✗\n";
        echo "   Erreur: " . $e->getMessage() . "\n";
        $failed++;
        
        // Arrêter en cas d'erreur pour éviter les migrations incohérentes
        echo "\n❌ Migration interrompue suite à une erreur.\n";
        echo "   Corrigez le problème et relancez les migrations.\n\n";
        exit(1);
    }
}

echo "\n";
echo "===========================================\n";
echo "  Résultat: $success réussie(s), $failed échouée(s)\n";
echo "===========================================\n\n";

exit($failed > 0 ? 1 : 0);
