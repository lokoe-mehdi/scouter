<?php
/**
 * Crée l'utilisateur demo/demo par défaut
 * Usage: php create-demo-user.php
 */

require("vendor/autoload.php");

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
