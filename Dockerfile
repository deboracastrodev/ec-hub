FROM php:8.4-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libonig-dev \
    libzip-dev \
    zip \
    unzip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql mbstring zip

# pcov: code coverage driver used by `make test-coverage` (R7.4)
RUN pecl install pcov && docker-php-ext-enable pcov

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Install the locked dependencies before copying the application. This keeps
# the image self-contained for a clean `docker compose up` and caches the
# Composer layer until composer.json or composer.lock changes.
COPY composer.json composer.lock /var/www/html/
RUN composer install --no-interaction --prefer-dist --no-progress

# Copy application files (source directories are mounted by Compose in local development)
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Expose HTTP server port
EXPOSE 9501

# Run PHP builtin server (development) with router
# -t sets document root to public directory
CMD ["php", "-S", "0.0.0.0:9501", "-t", "public", "public/index.php"]
