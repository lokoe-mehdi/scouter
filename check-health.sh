#!/bin/bash

echo "=== 🏥 SCOUTER HEALTH CHECK ==="
echo ""

# 1. État des conteneurs
echo "--- 🐳 DOCKER CONTAINERS ---"
docker ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}" | grep scouter
echo ""

# 2. État des jobs en base de données
echo "--- 📊 JOBS STATUS ---"
# On essaie de trouver le conteneur scouter (peu importe le separateur - ou _)
SCOUTER_CONTAINER=$(docker ps --format "{{.Names}}" | grep -E "scouter[-_]scouter" | head -n 1)

if [ -z "$SCOUTER_CONTAINER" ]; then
    echo "❌ Conteneur Scouter non trouvé"
else
    docker exec $SCOUTER_CONTAINER php -r "
    require_once 'vendor/autoload.php';
    use App\Database\PostgresDatabase;
    try {
        \$db = App\Database\PostgresDatabase::getInstance()->getConnection();
        \$stmt = \$db->query(\"SELECT status, COUNT(*) as count FROM jobs GROUP BY status\");
        \$rows = \$stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty(\$rows)) echo \"Aucun job en base.\n\";
        foreach(\$rows as \$row) {
            echo str_pad(\$row['status'], 15) . ': ' . \$row['count'] . \"\n\";
        }
    } catch (Exception \$e) {
        echo \"Erreur DB: \" . \$e->getMessage() . \"\n\";
    }
    "
fi
echo ""

# 3. Vérifier les erreurs récentes
echo "--- 🚨 RECENT ERRORS (Last 10 lines) ---"
WORKER_CONTAINER=$(docker ps --format "{{.Names}}" | grep -E "scouter[-_]worker" | head -n 1)

if [ -z "$WORKER_CONTAINER" ]; then
    echo "❌ Aucun worker trouvé"
else
    echo "Logs du worker: $WORKER_CONTAINER"
    docker logs $WORKER_CONTAINER --tail 10
fi
echo ""

echo "=== ✅ END CHECK ==="
