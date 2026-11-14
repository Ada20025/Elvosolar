#!/bin/sh
# Štart kontajnera: Apache musí počúvať na porte, ktorý prideľuje Railway (PORT).
set -e

PORT="${PORT:-8080}"
DATA_DIR="${ELVO_DATA_DIR:-/data}"

# Apache: jediný listener na pridelenom porte + rovnaký port vo virtualhoste
printf 'Listen 0.0.0.0:%s\n' "$PORT" > /etc/apache2/ports.conf

VHOST=/etc/apache2/sites-available/000-default.conf
if [ -f "$VHOST" ]; then
    sed -i -E "s#<VirtualHost \\*:[0-9]+>#<VirtualHost *:${PORT}>#" "$VHOST"
fi

# Priečinok pre dopyty (submissions.jsonl, undelivered.log) musí byť zapisovateľný.
# Ak je pripojený Railway Volume, prežije aj nové nasadenie.
mkdir -p "$DATA_DIR"
chown -R www-data:www-data "$DATA_DIR" 2>/dev/null || true
chmod 0775 "$DATA_DIR" 2>/dev/null || true

# Interné adresáre webu (ak ich obraz vytvoril ako root)
for d in /var/www/html/_data; do
    [ -d "$d" ] && chown -R www-data:www-data "$d" 2>/dev/null || true
done

echo "[elvosolar] Apache na porte ${PORT}, dáta v ${DATA_DIR}"

exec "$@"
