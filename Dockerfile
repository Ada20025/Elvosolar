FROM php:8.2-cli

ARG BUILD_VERSION=2026-09-05-v3-fix-buttons-okte
# Full dashboard: relay, OKTE, charts, control - ALL buttons fixed
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libonig-dev \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql mysqli mbstring zip gd \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /app

# Copy ALL app files including templates/
COPY Software/App/ .

# Expose port
EXPOSE ${PORT:-8080}

# Start PHP built-in server
CMD php -d display_errors=1 -d error_reporting=E_ALL -S 0.0.0.0:${PORT:-8080} index.php
