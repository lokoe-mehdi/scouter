<?php

namespace App\Database;

use PDO;

/**
 * Connexion PostgreSQL singleton
 * 
 * Cette classe gère la connexion unique à PostgreSQL.
 * Utilise le pattern Singleton pour garantir une seule connexion
 * par processus, avec des connexions persistantes pour la performance.
 * 
 * Configuration via variable d'environnement `DATABASE_URL` :
 * ```
 * DATABASE_URL=postgresql://user:password@host:5432/dbname
 * ```
 * 
 * @package    Scouter
 * @subpackage Database
 * @author     Mehdi Colin
 * @version    2.0.0
 * @since      2.0.0
 */
class PostgresDatabase
{
    private static $instance = null;
    private $pdo;

    private function __construct()
    {
        $databaseUrl = getenv('DATABASE_URL');
        
        if (!$databaseUrl) {
            $databaseUrl = 'postgresql://scouter:scouter@postgres:5432/scouter';
        }

        $params = parse_url($databaseUrl);
        
        $host = $params['host'] ?? 'postgres';
        $port = $params['port'] ?? 5432;
        $dbname = ltrim($params['path'] ?? '/scouter', '/');
        $user = $params['user'] ?? 'scouter';
        $password = $params['pass'] ?? 'scouter';

        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
        
        $this->pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => true  // Connexion persistante (réduit overhead)
        ]);
        
        // Configuration pour réduire les deadlocks
        // deadlock_timeout: délai avant détection deadlock (défaut 1s, on réduit à 200ms)
        // lock_timeout: timeout sur les verrous (60s pour supporter plusieurs crawls simultanés)
        // statement_timeout: timeout sur les requêtes (120s - permissif pour gros HTML et crawls lourds)
        $this->pdo->exec("SET deadlock_timeout = '200ms'");
        $this->pdo->exec("SET lock_timeout = '60s'");
        $this->pdo->exec("SET statement_timeout = '120s'");
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Réinitialise l'instance singleton (pour forcer une reconnexion)
     * Utilisé par les workers en cas de timeout ou erreur de connexion
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    public function getConnection(): PDO
    {
        return $this->pdo;
    }
}
