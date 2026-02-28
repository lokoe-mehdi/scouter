<?php
require_once '/app/vendor/autoload.php';

use App\Database\PostgresDatabase;

try {
    $db = PostgresDatabase::getInstance()->getConnection();
    
    // On prend le premier user trouvé (normalement il n'y en a qu'un après un rebuild)
    $stmt = $db->query("SELECT id, email FROM users ORDER BY id ASC LIMIT 1");
    $user = $stmt->fetch(PDO::FETCH_OBJ);
    
    if (!$user) {
        echo "❌ Aucun utilisateur trouvé en base !\n";
        exit(1);
    }

    echo "Trouvé utilisateur : {$user->email} (ID: {$user->id})\n";
    
    // Passage en admin
    $update = $db->prepare("UPDATE users SET role = 'admin' WHERE id = :id");
    $update->execute([':id' => $user->id]);
    
    echo "✅ Utilisateur passé ADMIN avec succès !\n";

} catch (Exception $e) {
    echo "❌ Erreur : " . $e->getMessage() . "\n";
    exit(1);
}
