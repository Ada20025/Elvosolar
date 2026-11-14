# Elvosolar — PHP 8.3 + Apache image pre Railway (a akýkoľvek Docker hosting)
#
# Čo obsahuje:
#   - mod_rewrite, mod_headers, mod_expires, mod_deflate  → .htaccess funguje rovnako ako na Apache hostingu
#   - Apache počúva na porte z premennej PORT (Railway ho pridá automaticky)
#   - zapisovateľný priečinok /data pre dopyty z formulárov (pripojte Railway Volume na /data)
#   - žiadne tajomstvá v image: e-maily sa konfigurujú cez premenné prostredia (RESEND_API_KEY, MAIL_*)
#
# Lokálny test:  docker build -t elvosolar . && docker run --rm -p 8080:8080 -e PORT=8080 elvosolar

FROM php:8.3-apache

ENV PORT=8080 \
    ELVO_DATA_DIR=/data \
    TZ=Europe/Bratislava

# Apache moduly potrebné pre pravidlá v .htaccess
RUN a2enmod rewrite headers expires deflate

# Povoliť .htaccess (RewriteRule, Header, Expires) a vypnúť výpis adresárov
RUN printf '%s\n' \
      '<Directory /var/www/html/>' \
      '    Options -Indexes +FollowSymLinks' \
      '    AllowOverride All' \
      '    Require all granted' \
      '</Directory>' \
      > /etc/apache2/conf-available/elvosolar.conf \
 && a2enconf elvosolar \
 && printf 'ServerName localhost\nServerTokens Prod\nServerSignature Off\n' \
      > /etc/apache2/conf-available/servername.conf \
 && a2enconf servername

# PHP: e-maily a dátumy v slovenskom čase, žiadne exposovanie verzie
RUN printf '%s\n' \
      'date.timezone = Europe/Bratislava' \
      'expose_php = Off' \
      'memory_limit = 192M' \
      'post_max_size = 8M' \
      'upload_max_filesize = 8M' \
      'session.cookie_httponly = 1' \
      > /usr/local/etc/php/conf.d/elvosolar.ini

COPY docker/entrypoint.sh /usr/local/bin/elvosolar-entrypoint
RUN chmod +x /usr/local/bin/elvosolar-entrypoint

# Web (bez _mail-config.php, bez vývojových súborov — viď .dockerignore)
COPY . /var/www/html

RUN mkdir -p /var/www/html/_data /data \
 && chown -R www-data:www-data /var/www/html/_data /data

EXPOSE 8080

ENTRYPOINT ["elvosolar-entrypoint"]
CMD ["apache2-foreground"]
