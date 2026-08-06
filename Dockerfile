FROM php:8.3-fpm

# Install system dependencies and Nginx
RUN apt-get update && apt-get install -y \
    git \
    curl \
    zip \
    unzip \
    libzip-dev \
    libcurl4-openssl-dev \
    libxml2-dev \
    libonig-dev \
    sqlite3 \
    libsqlite3-dev \
    libpq-dev \
    nginx \
    supervisor \
    cron \
    && docker-php-ext-install \
    curl \
    pdo \
    pdo_sqlite \
    pdo_pgsql \
    zip \
    pcntl \
    dom \
    mbstring \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy application files
COPY . /app

# Install PHP dependencies from the lock file only. Builds must be reproducible;
# generate composer.lock locally with `composer update` when dependencies change.
RUN composer install --no-interaction --prefer-dist --optimize-autoloader

# Configure Nginx
COPY docker/nginx.conf /etc/nginx/sites-available/default

# Setup Cron
# The GSC reconciler runs every 10 min, not once a day: it is the only thing
# that resurrects a connector whose job died (crash, OOM, container restart), so
# its period IS the worst-case recovery delay. It is cheap and idempotent — it
# enqueues nothing when everything is already up to date.
RUN printf "0 * * * * root . /etc/environment; /usr/local/bin/php /app/scripts/watchdog.php >> /proc/1/fd/1 2>> /proc/1/fd/2\n* * * * * root . /etc/environment; /usr/local/bin/php /app/app/bin/scheduler.php >> /proc/1/fd/1 2>> /proc/1/fd/2\n*/10 * * * * root . /etc/environment; /usr/local/bin/php /app/app/bin/gsc-reconciler.php >> /proc/1/fd/1 2>> /proc/1/fd/2\n" > /etc/cron.d/scouter-cron && \
    chmod 0644 /etc/cron.d/scouter-cron && \
    crontab /etc/cron.d/scouter-cron

# Configure Supervisor (version prod par défaut)
COPY docker/supervisord.prod.conf /etc/supervisor/conf.d/supervisord.conf

# Base PHP limits — these are the CLI limits. conf.d is read by every SAPI, but
# the FPM pool below overrides them for web requests (see zz-scouter.conf).
#
# Unlimited is CORRECT for the CLI: worker.php runs batch categorization, report
# precompute and bulk-AI jobs that legitimately run for hours over a whole crawl.
# It is NOT correct for a web request — a report page that walks a large crawl
# would allocate until the container's cgroup is exhausted, and the kernel's
# OOM-killer then picks a victim process (nginx, the FPM master, a neighbour
# container) instead of PHP cleanly aborting the one bad request.
RUN echo "max_execution_time = 0" > /usr/local/etc/php/conf.d/timeout.ini && \
    echo "memory_limit = -1" >> /usr/local/etc/php/conf.d/timeout.ini && \
    echo "default_socket_timeout = 3600" >> /usr/local/etc/php/conf.d/timeout.ini

# Tune PHP-FPM pool : default config ships with `pm.max_children = 5` which
# is way too low when long-running endpoints (SSE chat) coexist with normal
# requests. With 5 workers, a single in-flight Dr. Brief conversation
# already eats 20% of the pool ; 3 of them and the whole app freezes for
# every user. We bump it to 40 dynamic workers — generous headroom for
# small/medium installs without exploding RAM (~150 MB/worker peak).
# Adjust pm.* values via env vars in docker-compose if your host has
# more or less RAM than the default Scouter sizing.
#
# The three settings after pm.* are the WEB guardrails (the CLI keeps the
# unlimited values from timeout.ini above):
#
#   php_admin_value[memory_limit] = 512M
#     NOT overridable by application code. No report page has a legitimate
#     reason to exceed this — past that point it is a bug (a query loading a
#     whole crawl into RAM instead of aggregating in the database). Without the
#     cap a single request could exhaust the container's cgroup, at which point
#     the kernel OOM-killer picks a victim — nginx, the FPM master, a neighbour
#     container — instead of PHP cleanly aborting the one bad request. With it:
#     a fatal error on THAT request, a 500, and the app stays up.
#
#   php_value[max_execution_time] = 120
#     Overridable on purpose: the SSE endpoint (Dr. Brief) lifts it itself via
#     set_time_limit(0). Note it only counts PHP's own CPU time, never the wait
#     on a SQL query — hence the FPM-level net below.
#
#   request_terminate_timeout = 900
#     The only mechanism that can kill a request blocked inside a ClickHouse or
#     Postgres query. Left at 0, such a request held its worker AND its memory
#     indefinitely, and 40 stuck workers means a frozen application. 15 minutes
#     leaves ample room for an SSE conversation (minutes at worst) while still
#     bounding a hang.
RUN echo "[www]"                          >  /usr/local/etc/php-fpm.d/zz-scouter.conf && \
    echo "pm = dynamic"                   >> /usr/local/etc/php-fpm.d/zz-scouter.conf && \
    echo "pm.max_children = 40"           >> /usr/local/etc/php-fpm.d/zz-scouter.conf && \
    echo "pm.start_servers = 8"           >> /usr/local/etc/php-fpm.d/zz-scouter.conf && \
    echo "pm.min_spare_servers = 4"       >> /usr/local/etc/php-fpm.d/zz-scouter.conf && \
    echo "pm.max_spare_servers = 16"      >> /usr/local/etc/php-fpm.d/zz-scouter.conf && \
    echo "pm.max_requests = 500"          >> /usr/local/etc/php-fpm.d/zz-scouter.conf && \
    echo "php_admin_value[memory_limit] = 512M" >> /usr/local/etc/php-fpm.d/zz-scouter.conf && \
    echo "php_value[max_execution_time] = 120"  >> /usr/local/etc/php-fpm.d/zz-scouter.conf && \
    echo "request_terminate_timeout = 900"      >> /usr/local/etc/php-fpm.d/zz-scouter.conf

# Create necessary directories
RUN mkdir -p /var/log/supervisor && \
    chown -R www-data:www-data /var/log/nginx

# Fix permissions for Nginx and PHP-FPM
RUN chown -R www-data:www-data /app && \
    chmod -R 755 /app

# Expose port 8080 for Nginx
EXPOSE 8080

# Copy entrypoint script
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Start with entrypoint
CMD ["/entrypoint.sh"]
