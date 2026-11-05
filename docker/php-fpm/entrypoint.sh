#!/bin/sh
# Initialize the backend container: JWT keys, PostgreSQL wait, migrations, and cache.
# Each step is logged (docker compose logs php); errors stop the container with an
# explicit message rather than starting an application without a schema.
set -eu
cd /var/www/backend
log() { echo "[geosamples] $*"; }

if [ ! -f config/jwt/private.pem ]; then
  log "Generating JWT keys"
  openssl genpkey -algorithm RSA -out config/jwt/private.pem -aes256 -pass "pass:${JWT_PASSPHRASE}" -pkeyopt rsa_keygen_bits:3072
  openssl pkey -in config/jwt/private.pem -passin "pass:${JWT_PASSPHRASE}" -pubout -out config/jwt/public.pem
fi
chown www-data:www-data config/jwt/*.pem && chmod 640 config/jwt/private.pem

# Wait for the database using the same configuration as the application (DB_* variables read by Doctrine).
log "Waiting for PostgreSQL…"
i=0
until php bin/console dbal:run-sql -q "SELECT 1" >/dev/null 2>&1; do
  i=$((i + 1))
  if [ "$i" -ge 60 ]; then
    log "ERROR: database unreachable after 2 minutes. Details:"
    php bin/console dbal:run-sql "SELECT 1" || true
    exit 1
  fi
  sleep 2
done
log "Database is reachable"

log "Running schema migrations"
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console cache:clear --no-warmup
php bin/console cache:warmup
chown -R www-data:www-data var
log "Ready"

# Daily purge of expired refresh tokens.
crond -b -l 8 || log "crond unavailable: run app:auth:purge from a host cron job"

exec "$@"
