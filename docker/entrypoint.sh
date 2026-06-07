#!/bin/sh
set -e

# A persistent volume mounted at /storage comes up owned by root and empty.
# Apache runs as www-data, so make sure it can create/write the SQLite file.
mkdir -p /var/www/html/storage
chown -R www-data:www-data /var/www/html/storage
chmod -R 775 /var/www/html/storage

exec "$@"
