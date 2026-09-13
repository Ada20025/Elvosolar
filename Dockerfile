FROM php:8.2-cli

# Absolute minimum - only pdo_mysql (built-in, cannot fail)
RUN docker-php-ext-install pdo pdo_mysql

WORKDIR /app

# Cache-bust: 2026-09-13-1215
COPY Software/App/ /app/

EXPOSE 8080

CMD ["sh", "-c", "php -d display_errors=1 -d error_reporting=E_ALL -S 0.0.0.0:${PORT:-8080} index.php"]
