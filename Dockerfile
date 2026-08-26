FROM php:8.2-cli

# Install system dependencies for MySQL + PDO
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

# Copy only the app directory
COPY Software/App/ .

# Expose port
EXPOSE 8080

# Start PHP built-in server with error display
CMD ["php", "-d", "display_errors=1", "-d", "error_reporting=E_ALL", "-S", "0.0.0.0:8080", "index.php"]
