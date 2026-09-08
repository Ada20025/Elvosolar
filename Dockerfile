FROM php:8.2-cli

# Install system deps
RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libonig-dev unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql mysqli mbstring zip gd \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Force rebuild timestamp: 2026-09-05
# Copy ALL app files including templates/dashboard.html (3283 lines)
COPY Software/App/ /app/

EXPOSE ${PORT:-8080}

CMD php -d display_errors=1 -d error_reporting=E_ALL -S 0.0.0.0:${PORT:-8080} index.php
