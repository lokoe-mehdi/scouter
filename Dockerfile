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
RUN printf "0 * * * * root . /etc/environment; /usr/local/bin/php /app/scripts/watchdog.php >> /proc/1/fd/1 2>> /proc/1/fd/2\n* * * * * root . /etc/environment; /usr/local/bin/php /app/app/bin/scheduler.php >> /proc/1/fd/1 2>> /proc/1/fd/2\n" > /etc/cron.d/scouter-cron && \
    chmod 0644 /etc/cron.d/scouter-cron && \
    crontab /etc/cron.d/scouter-cron

# Configure Supervisor (version prod par défaut)
COPY docker/supervisord.prod.conf /etc/supervisor/conf.d/supervisord.conf

# Set unlimited execution time for PHP-FPM
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
RUN echo "[www]"                          >  /usr/local/etc/php-fpm.d/zz-scouter.conf && \
    echo "pm = dynamic"                   >> /usr/local/etc/php-fpm.d/zz-scouter.conf && \
    echo "pm.max_children = 40"           >> /usr/local/etc/php-fpm.d/zz-scouter.conf && \
    echo "pm.start_servers = 8"           >> /usr/local/etc/php-fpm.d/zz-scouter.conf && \
    echo "pm.min_spare_servers = 4"       >> /usr/local/etc/php-fpm.d/zz-scouter.conf && \
    echo "pm.max_spare_servers = 16"      >> /usr/local/etc/php-fpm.d/zz-scouter.conf && \
    echo "pm.max_requests = 500"          >> /usr/local/etc/php-fpm.d/zz-scouter.conf && \
    echo "request_terminate_timeout = 0"  >> /usr/local/etc/php-fpm.d/zz-scouter.conf

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
