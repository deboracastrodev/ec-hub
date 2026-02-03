FROM php:7.4-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql mbstring exif pcntl bcmath zip

# Install Redis extension
RUN pecl install redis-5.3.7 \
    && docker-php-ext-enable redis

# Install Swoole extension (version 4.x for PHP 7.4 compatibility)
RUN pecl install swoole-4.8.12 \
    && docker-php-ext-enable swoole

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy application files (will be mounted via volume)
COPY . /var/www/html

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Expose Swoole HTTP server port
EXPOSE 9501

# Default command (will be overridden by docker-compose)
CMD ["php-fpm"]
