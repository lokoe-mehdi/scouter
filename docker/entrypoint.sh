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

# Export env vars for cron jobs
printenv | grep -E '^(DATABASE_URL|RENDERER_URL|MAX_|PHP_|APP_)' | sed 's/=\(.*\)/="\1"/' > /etc/environment

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
