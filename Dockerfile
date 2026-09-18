FROM php:8.2-cli

# Absolute minimum - only pdo_mysql (built-in, cannot fail)
RUN docker-php-ext-install pdo pdo_mysql

WORKDIR /app

# Cache-bust: 2026-09-16-screenshots
COPY Software/App/ /app/

EXPOSE 8080

CMD ["sh", "-c", "PHP_CLI_SERVER_WORKERS=8 php -d display_errors=1 -d error_reporting=E_ALL -d opcache.enable=1 -d opcache.enable_cli=1 -d opcache.memory_consumption=128 -d opcache.validate_timestamps=0 -d realpath_cache_size=4096k -d realpath_cache_ttl=600 -S 0.0.0.0:${PORT:-8080} index.php"]
