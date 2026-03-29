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

# Install PHP dependencies
RUN composer update --no-interaction --prefer-dist --optimize-autoloader

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
