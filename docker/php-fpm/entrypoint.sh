#!/bin/sh
# Initialisation du conteneur backend : clés JWT, attente de PostgreSQL, migrations, cache.
# Chaque étape est journalisée (docker compose logs php) ; une erreur arrête le conteneur
# avec un message explicite plutôt que de laisser démarrer une application sans schéma.
set -eu
cd /var/www/backend
log() { echo "[geosamples] $*"; }

if [ ! -f config/jwt/private.pem ]; then
  log "Génération des clés JWT"
  openssl genpkey -algorithm RSA -out config/jwt/private.pem -aes256 -pass "pass:${JWT_PASSPHRASE}" -pkeyopt rsa_keygen_bits:3072
  openssl pkey -in config/jwt/private.pem -passin "pass:${JWT_PASSPHRASE}" -pubout -out config/jwt/public.pem
fi
chown www-data:www-data config/jwt/*.pem && chmod 640 config/jwt/private.pem

# Attente de la base, avec la même configuration que l'application (DATABASE_URL lue par Doctrine)
log "Attente de PostgreSQL…"
i=0
until php bin/console dbal:run-sql -q "SELECT 1" >/dev/null 2>&1; do
  i=$((i + 1))
  if [ "$i" -ge 60 ]; then
    log "ERREUR : base injoignable après 2 minutes. Détail :"
    php bin/console dbal:run-sql "SELECT 1" || true
    exit 1
  fi
  sleep 2
done
log "Base joignable"

log "Migrations du schéma"
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console cache:clear --no-warmup
php bin/console cache:warmup
chown -R www-data:www-data var
log "Prêt"

# Purge quotidienne des refresh tokens expirés
crond -b -l 8 || log "crond indisponible : lancer app:auth:purge par un cron de l'hôte"

exec "$@"
