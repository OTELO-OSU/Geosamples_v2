#!/bin/sh
# Initialisation du conteneur backend : clés JWT, attente de PostgreSQL, migrations, cache.
set -e
cd /var/www/backend

if [ ! -f config/jwt/private.pem ]; then
  echo "Génération des clés JWT"
  openssl genpkey -algorithm RSA -out config/jwt/private.pem -aes256 -pass "pass:${JWT_PASSPHRASE}" -pkeyopt rsa_keygen_bits:3072
  openssl pkey -in config/jwt/private.pem -passin "pass:${JWT_PASSPHRASE}" -pubout -out config/jwt/public.pem
  chown www-data:www-data config/jwt/*.pem && chmod 640 config/jwt/private.pem
fi

until pg_isready -d "${DATABASE_URL%%\?*}" >/dev/null 2>&1; do echo "Attente de PostgreSQL…"; sleep 2; done

php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
php bin/console cache:clear --no-warmup && php bin/console cache:warmup
chown -R www-data:www-data var

# Purge quotidienne des refresh tokens expirés
crond -b -l 8

exec "$@"
