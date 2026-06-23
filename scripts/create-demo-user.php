<?php
/**
 * Crée l'utilisateur demo/demo par défaut.
 *
 * Disabled by default to avoid accidentally creating a known admin account.
 * Usage: SCOUTER_ALLOW_DEMO_USER=true php scripts/create-demo-user.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$allowDemo = strtolower((string)getenv('SCOUTER_ALLOW_DEMO_USER'));
if (!in_array($allowDemo, ['1', 'true', 'yes'], true)) {
    fwrite(STDERR, "Refusing to create demo admin. Set SCOUTER_ALLOW_DEMO_USER=true to enable this dev-only script.\n");
    exit(1);
}

require(__DIR__ . "/../vendor/autoload.php");

use App\Database\UserRepository;

try {
    $users = new UserRepository();
    
    // Vérifier si l'utilisateur demo existe déjà
    if ($users->emailExists('demo@scouter.local')) {
        echo "✓ L'utilisateur 'demo' existe déjà.\n";
        echo "  Login: demo\n";
        echo "  Password: demo\n";
        exit;
    }
    
    // Créer l'utilisateur demo
    $userId = $users->create('demo@scouter.local', 'demo', 'admin');
    
    echo "✓ Utilisateur créé avec succès!\n";
    echo "  Login: demo\n";
    echo "  Password: demo\n";
    echo "\nVous pouvez maintenant vous connecter à l'application.\n";
    
} catch (Exception $e) {
    echo "❌ Erreur: " . $e->getMessage() . "\n";
    exit(1);
}
