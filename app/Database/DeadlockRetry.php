<?php

namespace App\Database;

use PDO;
use PDOException;

/**
 * Trait pour gérer les deadlocks PostgreSQL avec retry et backoff exponentiel
 * 
 * Ce trait fournit des méthodes pour exécuter des requêtes SQL avec un mécanisme
 * de retry automatique en cas de deadlock (SQLSTATE 40P01).
 * 
 * Utilisation :
 * ```php
 * class MyClass {
 *     use DeadlockRetry;
 *     
 *     public function doSomething() {
 *         $this->executeWithRetry($pdo, function($pdo) {
 *             // Code qui peut causer un deadlock
 *         });
 *     }
 * }
 * ```
 * 
 * @package    Scouter
 * @subpackage Database
 * @author     Mehdi Colin
 * @version    2.0.0
 */
trait DeadlockRetry
{
    /**
     * Nombre maximum de tentatives en cas de deadlock
     */
    private int $maxRetries = 3;
    
    /**
     * Délai minimum entre les tentatives (en millisecondes)
     */
    private int $minDelayMs = 50;
    
    /**
     * Délai maximum entre les tentatives (en millisecondes)
     */
    private int $maxDelayMs = 500;

    /**
     * Nettoie l'état de transaction si elle est en erreur (25P02)
     * PostgreSQL reste en état "transaction aborted" après une erreur
     * jusqu'à ce qu'on fasse un ROLLBACK explicite
     * 
     * @param PDO $pdo Connexion PDO
     */
    protected function cleanupTransactionState(PDO $pdo): void
    {
        try {
            // Vérifier si une transaction est active avant de rollback
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (\Exception $e) {
            // Ignore toutes les erreurs - on veut juste nettoyer l'état
            // "no active transaction" ou autres erreurs sont OK
        }
    }
    
    /**
     * Exécute une callback avec retry automatique en cas de deadlock
     * Attend si une création de partition est en cours (exclusive lock 12345)
     * 
     * @param PDO $pdo Connexion PDO
     * @param callable $callback Fonction à exécuter (reçoit $pdo en argument)
     * @param int $maxRetries Nombre max de tentatives (défaut: 3)
     * @return mixed Résultat de la callback
     * @throws PDOException Si toutes les tentatives échouent
     */
    protected function executeWithRetry(PDO $pdo, callable $callback, int $maxRetries = 3)
    {
        $attempts = 0;
        $lastException = null;
        
        while ($attempts < $maxRetries) {
            try {
                // Attendre si une création de partition est en cours
                // pg_try_advisory_lock_shared retourne false si un exclusive lock est détenu
                $stmt = $pdo->query("SELECT pg_try_advisory_lock_shared(12345)");
                $gotLock = $stmt->fetchColumn();
                
                if (!$gotLock) {
                    // Une création de partition est en cours, attendre
                    usleep(100000); // 100ms
                    continue;
                }
                
                try {
                    $result = $callback($pdo);
                    // Libérer le shared lock
                    $pdo->exec("SELECT pg_advisory_unlock_shared(12345)");
                    return $result;
                } catch (\Exception $e) {
                    // Libérer le shared lock même en cas d'erreur
                    try { $pdo->exec("SELECT pg_advisory_unlock_shared(12345)"); } catch (\Exception $ignored) {}
                    throw $e;
                }
            } catch (PDOException $e) {
                $attempts++;
                $lastException = $e;
                
                // Vérifier si c'est une erreur récupérable :
                // - 40P01 = deadlock
                // - 40001 = serialization failure
                // - 25P02 = transaction aborted
                // - 55P03 = lock timeout (fréquent sur CREATE TABLE concurrent)
                $sqlState = $e->getCode();
                $isRetryable = in_array($sqlState, ['40P01', '40001', '25P02', '55P03']);
                
                if (!$isRetryable) {
                    // Ce n'est pas une erreur récupérable, on ne retry pas
                    throw $e;
                }
                
                // Nettoyer l'état de transaction seulement si c'est 25P02
                // (les autres erreurs sont gérées par executeTransactionWithRetry)
                if ($sqlState === '25P02') {
                    $this->cleanupTransactionState($pdo);
                }
                
                if ($attempts >= $maxRetries) {
                    // Plus de tentatives, on lève l'exception
                    error_log("DB_RETRY: Max retries ({$maxRetries}) exceeded. SQLSTATE: {$sqlState}. Last error: " . $e->getMessage());
                    throw $e;
                }
                
                // Backoff exponentiel avec jitter aléatoire
                $delay = $this->calculateBackoffDelay($attempts);
                error_log("DB_RETRY: SQLSTATE {$sqlState} (attempt {$attempts}/{$maxRetries}), retrying in {$delay}ms...");
                
                usleep($delay * 1000); // Convertir ms en µs
            }
        }
        
        throw $lastException;
    }

    /**
     * Exécute une transaction complète avec retry automatique
     * 
     * @param PDO $pdo Connexion PDO
     * @param callable $callback Fonction à exécuter dans la transaction
     * @param int $maxRetries Nombre max de tentatives
     * @return mixed Résultat de la callback
     */
    protected function executeTransactionWithRetry(PDO $pdo, callable $callback, int $maxRetries = 3)
    {
        return $this->executeWithRetry($pdo, function($pdo) use ($callback) {
            // Si une transaction est déjà en cours, on l'utilise
            $inTransaction = $pdo->inTransaction();
            
            if (!$inTransaction) {
                $pdo->beginTransaction();
            }
            
            try {
                $result = $callback($pdo);
                
                if (!$inTransaction) {
                    $pdo->commit();
                }
                
                return $result;
            } catch (\Exception $e) {
                if (!$inTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }, $maxRetries);
    }

    /**
     * Calcule le délai de backoff avec jitter aléatoire
     * 
     * @param int $attempt Numéro de la tentative (1-based)
     * @return int Délai en millisecondes
     */
    private function calculateBackoffDelay(int $attempt): int
    {
        // Backoff exponentiel: 50ms, 100ms, 200ms... avec jitter
        $baseDelay = $this->minDelayMs * pow(2, $attempt - 1);
        $maxDelay = min($baseDelay * 2, $this->maxDelayMs);
        
        // Ajouter du jitter aléatoire (±25%)
        $jitter = mt_rand(-25, 25) / 100;
        $delay = (int)($baseDelay * (1 + $jitter));
        
        return max($this->minDelayMs, min($delay, $this->maxDelayMs));
    }

    /**
     * Vérifie si une exception est récupérable (deadlock, serialization, transaction aborted)
     * 
     * @param \Exception $e Exception à vérifier
     * @return bool True si c'est une erreur récupérable
     */
    protected function isRetryableError(\Exception $e): bool
    {
        if (!$e instanceof PDOException) {
            return false;
        }
        
        $sqlState = $e->getCode();
        // 40P01 = deadlock, 40001 = serialization failure, 25P02 = transaction aborted, 55P03 = lock timeout
        return in_array($sqlState, ['40P01', '40001', '25P02', '55P03']);
    }
    
    /**
     * @deprecated Use isRetryableError() instead
     */
    protected function isDeadlock(\Exception $e): bool
    {
        return $this->isRetryableError($e);
    }
}
