<?php
/**
 * Migration de test - valide que le systeme fonctionne
 */
$pdo = \App\Database\PostgresDatabase::getInstance()->getConnection();
echo "(test migration - no changes) ";
return true;
