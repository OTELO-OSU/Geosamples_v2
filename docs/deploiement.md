# Déploiement

## Conteneurs

| Service | Image | Rôle |
|---|---|---|
| `nginx` | `docker/nginx/Dockerfile` | front compilé, relais `/api` → php-fpm, en-têtes de sécurité (CSP incluant les fonds de carte) |
| `php` | `docker/php-fpm/Dockerfile` | Symfony ; à chaque démarrage : clés JWT si absentes, attente de PostgreSQL, migrations, cache ; cron de purge des sessions |
| `database` | `postgres:18-alpine` | données |
| `mailer` | mailpit (profil `dev`) | boîte mail de test |

Volumes persistants dans `docker_data/` : `postgres`, `uploads` (fichiers déposés et logos), `jwt`, `logs`.
Sauvegarder `postgres` (ou `pg_dump`) **et** `uploads`.

## Reverse proxy Apache de l'hôte

Un seul vhost pour tous les sites, le `Host` d'origine étant conservé :

```apache
<VirtualHost *:443>
    ServerName  geosamples.otelo.univ-lorraine.fr
    ServerAlias nometal.otelo.univ-lorraine.fr orne.otelo.univ-lorraine.fr

    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/geosamples.otelo.univ-lorraine.fr/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/geosamples.otelo.univ-lorraine.fr/privkey.pem

    ProxyPreserveHost On
    RequestHeader set X-Forwarded-Proto "https"
    ProxyPass        / http://127.0.0.1:57010/
    ProxyPassReverse / http://127.0.0.1:57010/
    LimitRequestBody 545259520
</VirtualHost>
```

Le certificat doit couvrir tous les noms (SAN) ; avec certbot :
`certbot --apache -d geosamples.otelo.univ-lorraine.fr -d nometal.otelo.univ-lorraine.fr -d orne.otelo.univ-lorraine.fr`.

Variables à vérifier dans `.env` : `TRUSTED_HOSTS` (regex couvrant tous les noms),
`TRUSTED_PROXIES=private_ranges` (pour que `X-Forwarded-Proto` soit pris en compte : cookies
`Secure`, URL de retour OIDC en https), `COOKIE_SECURE=1`.

## Premier démarrage

```sh
cp .env.example .env && $EDITOR .env
docker compose up -d --build
docker compose exec php php bin/console app:user:create vous@univ-lorraine.fr --admin --password '…'
```

Puis, connecté en administrateur : « Administration générale » → créer les sites, leurs noms d'hôte
et leurs responsables.

## Mise à jour

```sh
git pull && docker compose up -d --build   # les migrations s'appliquent au démarrage de php
```
