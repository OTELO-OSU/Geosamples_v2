# GeoSamples v2

An overhaul of [geo-samples](https://github.com/OTELO-OSU/geo-samples): **a single codebase and
instance** for all OTELo geospatial data valorization sites (nometal, orne, ...), whether they are
pending or complementing publication in
[ORDaR v2](https://ordar.otelo.univ-lorraine.fr).

It uses the ORDaR v2 technology stack: **Symfony 7.4, Doctrine, PostgreSQL, JWT (lexik)** on the
server, **Vue 3, Vite, Vuetify 3, Pinia, Leaflet + markercluster** in the browser, all delivered in
**nginx + php-fpm** containers.

## What the application does

| Need | Solution |
|---|---|
| A single codebase and instance | Each site is a database row (`site`) containing its text, logo, colors, map extent and basemaps, fields, filters, and import template. The site is selected by **hostname** (`nometal.otelo.univ-lorraine.fr`) or the `/s/<slug>/` path. Create a site using the form in “Global administration”; no redeployment is needed. |
| Map clustering by zoom level | **Server-side** clustering on a Web Mercator grid whose cell size depends on zoom (millions of points remain responsive), followed by browser-side markercluster above a threshold. Repeated observations from the same station are merged into one marker. |
| Temporal data | Dataset period (`temporal_start/end`) and **per-point** date or period (time series). A timeline (histogram + period selector) appears below the map, with a measurements chart on the detail page. |
| 3D data | Each point has longitude, latitude (WGS84), and **elevation** (m, negative for depth) with its vertical datum (NGF-IGN69 by default). Filter by elevation or color by elevation. Input is supported in Lambert 93, extended Lambert II, UTM, ED50, and RGNC91; the original coordinates are retained. |
| Form-based submission | A form generated from the site's fields, point placement by clicking the map, and file and photo attachments. |
| Bulk import | A **site-customizable** template (columns, labels, examples, instructions), downloadable as XLSX (dropdown lists, help sheet) or CSV; a template **based on an existing dataset**; row-by-row validation before import; all-or-nothing import. |
| Edit an existing dataset | Use the form or **bulk editing**: export datasets (they include their `dataset_id`), edit the spreadsheet, then re-import it. |
| Roles | **admin** (global), **manager**, and **user** (per site). Workflow: draft → submitted → published/rejected; moderation can be enabled per site, and users can request membership. |
| RENATER federation | OIDC through a Keycloak connected to the federation (as with Projet_IGSN), or Apache `mod_shib` / CAS headers (as with ORDaR). Local accounts are available for initial setup. See [docs/federation.md](docs/federation.md). |

When a dataset is added to ORDaR, its DOI is recorded and the detail page displays a link to the repository.

## Run locally (without Docker)

Prérequis : PHP ≥ 8.2 (pdo_pgsql, intl), Composer, Node 22+, PostgreSQL 16+.

```sh
# Database
createuser -P geosamples && createdb -O geosamples geosamples

# Backend
cd backend
composer install
mkdir -p config/jwt
openssl genpkey -algorithm RSA -out config/jwt/private.pem -aes256 -pass pass:change-me-jwt-passphrase -pkeyopt rsa_keygen_bits:2048
openssl pkey -in config/jwt/private.pem -passin pass:change-me-jwt-passphrase -pubout -out config/jwt/public.pem
php bin/console doctrine:migrations:migrate -n
php bin/console app:demo:load          # 2 sites and fictional demo data
php -S 127.0.0.1:8000 -t public

# Frontend (another terminal) — /api is proxied to 127.0.0.1:8000
cd front && npm ci && npm run dev
```

Then open http://localhost:5173 (portal), http://localhost:5173/s/nometal/, or http://localhost:5173/s/orne/.
Demo accounts (password `demo-geosamples`): `admin@example.org`,
`responsable.nometal@example.org`, `responsable.orne@example.org`, `contributeur@example.org`.

Tests: `cd backend && vendor/bin/phpunit` (a `geosamples_test` database is required; see `.env.test`).

## Deploy

```sh
cp .env.example .env        # secrets, FEDERATION_MODE...
docker compose up -d --build
docker compose exec php php bin/console app:user:create vous@univ-lorraine.fr --admin --password '…'
```

nginx listens on `127.0.0.1:57010`. The host's Apache reverse proxy (which already handles TLS/certbot)
sends **all** site hostnames to this port while preserving the `Host` header
(`ProxyPreserveHost On`), which identifies the site. See the complete example in
[docs/deploiement.md](docs/deploiement.md).

Add a data site:
1. Go to Global administration → “New site” (name, identifier, hostname, manager).
2. Add the hostname in Apache (another `ServerAlias`) and in the Keycloak client (redirect URI).
3. The manager configures the text, map, fields, and template under “Administration”.

## Migrating legacy geo-samples sites

From the BSON files:
```
docker exec -e APP_ENV=prod -w /var/www/backend geosamples-php php bin/console app:site:create \
acev athabasca gitology moris nometal orne scandium \
petrophysics-allemagne petrophysics-ardeche petrophysics-fosserhenan \
petrophysics-guadeloupe petrophysics-limagne petrophysics-pyrenees \
petrophysics-soultz petrophysics-vosges
```

Dry run:
```sh
PHP_CONTAINER=geosamples-php FILES_DIR=/var/www/backend/var/uploads PHP_WORKDIR=/var/www/backend ONLY=nometal ./migrate_all.sh ./ORDAR/*
```

Import:
``` sh
RUN=1 PHP_CONTAINER=geosamples-php FILES_DIR=/var/www/backend/var/uploads PHP_WORKDIR=/var/www/backend ./migrate_all.sh ./ORDAR/
```
Import using mongoexport:

```sh
mongoexport --db=<base> --collection=<collection> --jsonArray -o export.json
php bin/console app:legacy:import <slug> export.json --dry-run --create-fields
php bin/console app:legacy:import <slug> export.json --create-fields --files-dir=/path/to/files
```

The command converts `INTRO.*`, `SAMPLING_POINT` (including Lambert 93 → WGS84), and `SUPPLEMENTARY_FIELDS`
into site fields, and links files by name. It was written based on the geo-samples documentation:
**always run it first with `--dry-run` on a real export**.

## Code organization

```
backend/                Symfony 7.4
  src/Entity            Site, SiteMembership, User, Dataset, SamplingPoint, DatasetFile, RefreshToken
  src/Controller        JSON API (/api/…) — routes are listed at the top of each controller
  src/Service           DatasetQuery (search, map, facets), DatasetWriter (validation),
                        ImportService, FieldSchema, FederationService, AuthService, Permissions
  src/Command           app:user:create, app:demo:load, app:legacy:import, app:auth:purge
  tests/                PHPUnit (unitaires + API)
front/                  Vue 3 + Vuetify
  src/views             MapView (map + filters + list + preview), DatasetView, DatasetEditView,
                        ImportView, WorkspaceView, SiteAdminView, GlobalAdminView, PortalView…
  src/components/map    SampleMap, TimelineBar, FilterPanel, MapLegend
docker/                 nginx (front compilé + relais /api), php-fpm (entrypoint : JWT, migrations)
docs/                   architecture, federation, deployment
```

See [docs/architecture.md](docs/architecture.md) for the data model, authorization rules, and import format.
