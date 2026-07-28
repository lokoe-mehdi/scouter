#!/bin/bash
set -e

# Wait for PostgreSQL to be ready (max 30 seconds)
echo "Waiting for PostgreSQL..."
for i in $(seq 1 30); do
    if php -r 'require_once "/app/vendor/autoload.php"; try { App\Database\PostgresDatabase::getInstance(); exit(0); } catch(Exception $e) { exit(1); }' 2>/dev/null; then
        echo "PostgreSQL is ready!"
        break
    fi
    echo "  Attempt $i/30 - waiting..."
    sleep 1
done

# Fix log permissions so both root (worker) and www-data (scouter) can write
mkdir -p /app/logs
chmod 777 /app/logs
find /app/logs -name "*.log" -exec chmod 666 {} \; 2>/dev/null || true

# Run migrations
echo ""
php /app/migrations/migrate.php

# Export env vars for cron jobs.
# CLICKHOUSE_* is required by app/bin/gsc-reconciler.php: it reads the gsc_*
# tables to know which days are actually present before deciding what to
# enqueue. Without them ClickHouseDatabase throws on construction and the
# reconciler — the only thing that resurrects a stuck GSC connector — would die
# on every tick.
printenv | grep -E '^(DATABASE_URL|RENDERER_URL|CLICKHOUSE_|MAX_|PHP_|APP_)' | sed 's/=\(.*\)/="\1"/' > /etc/environment

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
