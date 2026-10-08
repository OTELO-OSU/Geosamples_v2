# GeoSamples v2

Refonte de [geo-samples](https://github.com/OTELO-OSU/geo-samples) : **une seule base de code et une
seule instance** pour tous les sites de valorisation de jeux de données géolocalisés de l'OTELo
(nometal, orne, …), qu'ils soient en attente ou en complément d'une publication dans
[ORDaR v2](https://ordar.otelo.univ-lorraine.fr).

Elle reprend la pile technique d'ORDaR v2 : **Symfony 7.4, Doctrine, PostgreSQL, JWT (lexik)** côté
serveur, **Vue 3, Vite, Vuetify 3, Pinia, Leaflet + markercluster** côté navigateur, le tout livré en
conteneurs **nginx + php-fpm**.

## Ce que fait l'application

| Besoin | Réponse |
|---|---|
| Un seul code, une seule instance | Chaque site est une ligne en base (`site`) : textes, logo, couleurs, emprise et fonds de carte, champs, filtres, template d'import. Le site est choisi d'après le **nom d'hôte** (`nometal.otelo.univ-lorraine.fr`) ou le chemin `/s/<slug>/`. Créer un site = un formulaire dans « Administration générale », sans redéploiement. |
| Carte avec regroupements selon le zoom | Regroupement **côté serveur** sur une grille Web-Mercator dont la maille dépend du zoom (des millions de points restent fluides), puis markercluster côté navigateur au-delà d'un seuil. Les observations répétées d'une même station sont fusionnées en un marqueur. |
| Données temporelles | Période du jeu (`temporal_start/end`) et date ou période **par point** (séries temporelles). Frise temporelle (histogramme + sélection de période) sous la carte, graphique des mesures dans la fiche. |
| Données 3D | Chaque point a longitude, latitude (WGS84) et **altitude** (m, négative pour une profondeur) avec son référentiel vertical (NGF-IGN69 par défaut). Filtre d'altitude, couleur par altitude. Saisie possible en Lambert 93, Lambert II étendu, UTM, ED50, RGNC91 : la saisie d'origine est conservée. |
| Dépôt par formulaire | Formulaire généré à partir des champs du site, placement des points au clic sur la carte, fichiers et photos. |
| Import en lot | Template **personnalisable par site** (colonnes, libellés, exemples, consignes), téléchargeable en XLSX (listes déroulantes, feuille d'aide) ou CSV ; modèle **d'après un jeu existant** ; contrôle ligne par ligne avant import ; import tout-ou-rien. |
| Modifier un jeu existant | Formulaire, ou **modification en lot** : exporter des jeux (ils portent leur `dataset_id`), corriger le tableur, réimporter. |
| Rôles | **admin** (global), **responsable** et **utilisateur** (par site). Circuit brouillon → soumis → publié/refusé, modération activable par site, demandes d'adhésion. |
| Fédération RENATER | OIDC via un Keycloak raccordé à la fédération (comme Projet_IGSN), ou en-têtes Apache `mod_shib` / CAS (comme ORDaR). Comptes locaux possibles pour l'amorçage. Voir [docs/federation.md](docs/federation.md). |

Le passage d'un jeu dans ORDaR est tracé par son DOI : la fiche affiche alors le lien vers l'entrepôt.

## Démarrer en local (sans Docker)

Prérequis : PHP ≥ 8.2 (pdo_pgsql, intl), Composer, Node 22+, PostgreSQL 16+.

```sh
# Base
createuser -P geosamples && createdb -O geosamples geosamples

# Backend
cd backend
composer install
mkdir -p config/jwt
openssl genpkey -algorithm RSA -out config/jwt/private.pem -aes256 -pass pass:change-me-jwt-passphrase -pkeyopt rsa_keygen_bits:2048
openssl pkey -in config/jwt/private.pem -passin pass:change-me-jwt-passphrase -pubout -out config/jwt/public.pem
php bin/console doctrine:migrations:migrate -n
php bin/console app:demo:load          # 2 sites et des données FICTIVES de démonstration
php -S 127.0.0.1:8000 -t public

# Front (autre terminal) — /api est relayé vers 127.0.0.1:8000
cd front && npm ci && npm run dev
```

Puis http://localhost:5173 (portail), http://localhost:5173/s/nometal/ et http://localhost:5173/s/orne/.
Comptes de démonstration (mot de passe `demo-geosamples`) : `admin@example.org`,
`responsable.nometal@example.org`, `responsable.orne@example.org`, `contributeur@example.org`.

Tests : `cd backend && vendor/bin/phpunit` (une base `geosamples_test` est nécessaire, cf. `.env.test`).

## Déployer

```sh
cp .env.example .env        # secrets, FEDERATION_MODE…
docker compose up -d --build
docker compose exec php php bin/console app:user:create vous@univ-lorraine.fr --admin --password '…'
```

nginx écoute sur `127.0.0.1:57010`. Le reverse proxy Apache de l'hôte (qui porte déjà TLS/certbot)
envoie **tous** les noms d'hôte des sites vers ce port, en conservant l'en-tête `Host`
(`ProxyPreserveHost On`) : c'est lui qui désigne le site. Exemple complet dans
[docs/deploiement.md](docs/deploiement.md).

Ajouter un site de valorisation :
1. Administration générale → « Nouveau site » (nom, identifiant, nom d'hôte, responsable).
2. Déclarer le nom d'hôte dans Apache (un `ServerAlias` de plus) et dans le client Keycloak (URI de redirection).
3. Le responsable configure textes, carte, champs et template depuis « Administration ».

## Reprise des anciens sites geo-samples

```sh
mongoexport --db=<base> --collection=<collection> --jsonArray -o export.json
php bin/console app:legacy:import <slug> export.json --dry-run --create-fields
php bin/console app:legacy:import <slug> export.json --create-fields --files-dir=/chemin/des/fichiers
```

La commande convertit `INTRO.*`, `SAMPLING_POINT` (dont Lambert 93 → WGS84), `SUPPLEMENTARY_FIELDS`
(en champs du site) et rattache les fichiers par leur nom. Elle a été écrite d'après la documentation
de geo-samples : **lancez-la d'abord en `--dry-run` sur un export réel**.

## Organisation du code

```
backend/                Symfony 7.4
  src/Entity            Site, SiteMembership, User, Dataset, SamplingPoint, DatasetFile, RefreshToken
  src/Controller        API JSON (/api/…) — liste des routes en tête de chaque contrôleur
  src/Service           DatasetQuery (recherche, carte, facettes), DatasetWriter (validation),
                        ImportService, FieldSchema, FederationService, AuthService, Permissions
  src/Command           app:user:create, app:demo:load, app:legacy:import, app:auth:purge
  tests/                PHPUnit (unitaires + API)
front/                  Vue 3 + Vuetify
  src/views             MapView (carte + filtres + liste + aperçu), DatasetView, DatasetEditView,
                        ImportView, WorkspaceView, SiteAdminView, GlobalAdminView, PortalView…
  src/components/map    SampleMap, TimelineBar, FilterPanel, MapLegend
docker/                 nginx (front compilé + relais /api), php-fpm (entrypoint : JWT, migrations)
docs/                   architecture, fédération, déploiement
```

Voir [docs/architecture.md](docs/architecture.md) pour le modèle de données, les règles d'autorisation
et le format d'import.
