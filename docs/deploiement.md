# Deployment

## Conteneurs

| Service | Image | Purpose |
|---|---|---|
| `nginx` | `docker/nginx/Dockerfile` | compiled frontend, `/api` proxy to php-fpm, security headers (CSP including basemaps) |
| `php` | `docker/php-fpm/Dockerfile` | Symfony; on each startup: generate JWT keys if missing, wait for PostgreSQL, run migrations, warm cache; cron to purge sessions |
| `database` | `postgres:18-alpine` | data |
| `mailer` | mailpit (`dev` profile) | test mailbox |

Persistent volumes in `docker_data/`: `postgres`, `uploads` (submitted files and logos), `jwt`, and `logs`.
Back up both `postgres` (or use `pg_dump`) **and** `uploads`.

## Host Apache reverse proxy

Use a single vhost for all sites, preserving the original `Host`:

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

The certificate must cover all hostnames (SAN). With certbot:
`certbot --apache -d geosamples.otelo.univ-lorraine.fr -d nometal.otelo.univ-lorraine.fr -d orne.otelo.univ-lorraine.fr`.

Check these variables in `.env`: `TRUSTED_HOSTS` (a regex covering all hostnames),
`TRUSTED_PROXIES=private_ranges` (so `X-Forwarded-Proto` is honored for `Secure` cookies and
HTTPS OIDC callback URLs), and `COOKIE_SECURE=1`.

## First startup

```sh
cp .env.example .env && $EDITOR .env
docker compose up -d --build
docker compose exec php php bin/console app:user:create you@univ-lorraine.fr --admin --password '…'
```

Then, while signed in as an administrator, go to “Global administration” to create sites, hostnames,
and managers.

## Update

```sh
git pull && docker compose up -d --build   # migrations run when php starts
```

## Troubleshooting: “Waiting for PostgreSQL…” never completes

```sh
docker compose ps                                                   # database should be "healthy"
docker compose exec php php bin/console dbal:run-sql "SELECT 1"     # displays the actual error
```

- `password authentication failed`: `POSTGRES_PASSWORD` was changed **after** the initial
  initialization of `docker_data/postgres`; PostgreSQL retained the old password. Either update
  the database password:
  `docker compose exec database psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "ALTER USER \"$POSTGRES_USER\" PASSWORD '<new-password>'"`,
  or, if the database is still empty: `docker compose down && rm -rf docker_data/postgres && docker compose up -d`.
- `could not translate host name "database"`: the php container is not on the project network
  (start both services using the same `docker compose` project).
