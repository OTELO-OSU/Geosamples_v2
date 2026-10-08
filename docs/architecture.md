# Architecture

## Une instance, plusieurs sites

```
            nometal.otelo.univ-lorraine.fr ─┐
               orne.otelo.univ-lorraine.fr ─┼─► Apache (TLS) ─► nginx ─┬─► front compilé (SPA)
 geosamples.otelo.univ-lorraine.fr/s/<slug> ─┘   ProxyPreserveHost      └─► /api → php-fpm (Symfony) ─► PostgreSQL
```

Le site courant est déterminé, dans cet ordre, par :
1. le paramètre `{slug}` des routes `/api/sites/{slug}/…` ;
2. l'en-tête `X-Site`, envoyé par le front quand il est servi sous `/s/<slug>/` ;
3. le nom d'hôte de la requête (`site.hostnames`) ;
4. `DEFAULT_SITE`.

Sans site résolu, le front affiche le **portail** (liste des sites, administration générale).

## Modèle de données

| Table | Rôle |
|---|---|
| `site` | Configuration complète d'un site : `hostnames`, textes (markdown), `theme`, `map` (centre, zoom, fonds, `colorBy`, `clusterMaxZoom`), `fields` (champs spécifiques), `import_template`, `settings` (modération, valeurs par défaut, contact…), `published`. |
| `app_user` | Compte ; `federation_id` (eppn ou `sub` OIDC) pour les comptes fédérés, `password` pour les comptes locaux, `ROLE_ADMIN` dans `roles`. |
| `site_membership` | Rôle d'un compte sur un site : `responsable` ou `utilisateur`, statut `active` ou `pending` (demande d'adhésion). |
| `dataset` | Jeu de données : métadonnées communes en colonnes, champs du site en `properties` (JSONB), `temporal_start/end`, `status`, `doi`/`ordar_url` une fois publié dans ORDaR. |
| `sampling_point` | Point (station, échantillon) : `longitude`, `latitude` (WGS84), `elevation` + `vertical_datum`, `observed_at/observed_end`, saisie d'origine (`src_crs`, `src_x`, `src_y`), mesures par point en `properties` (JSONB). |
| `dataset_file` | Fichiers (données, photos, documents), stockés sous `UPLOAD_DIR/<site>/<uuid>/`. |
| `refresh_token` | Hash SHA-256 des refresh tokens (rotation à chaque usage). |

### Champs spécifiques d'un site

```json
{ "key": "lithology", "label": "Lithologie", "type": "select", "options": ["Granite", "Grès"],
  "required": true, "unit": null, "help": null, "group": "Géologie",
  "filter": "select", "popup": true, "scope": "dataset" }
```

- `type` : `text`, `textarea`, `number`, `integer`, `date`, `select`, `multiselect`, `boolean`, `url`
- `scope` : `dataset` (une valeur par jeu) ou `point` (une valeur par point/date : mesures d'une série temporelle)
- `filter` : `select`, `range` ou `text` pour l'exposer dans le panneau de filtres ; les valeurs proposées et leurs effectifs sont calculés à la volée (`/facets`).

Les valeurs sont contrôlées et normalisées (nombres à virgule, listes insensibles à la casse et aux
accents, oui/non…) par `FieldSchema`. Le remplacement des formulaires `form_<projet>.twig` de
geo-samples par cette définition en base supprime tout développement par projet.

## Autorisations

| | public | connecté non membre | utilisateur | responsable | admin |
|---|---|---|---|---|---|
| Voir la carte et les jeux publiés | ✓ | ✓ | ✓ | ✓ | ✓ |
| Télécharger les fichiers d'un jeu en accès ouvert | ✓ | ✓ | ✓ | ✓ | ✓ |
| Télécharger les fichiers d'un jeu restreint | | | ✓ | ✓ | ✓ |
| Demander à contribuer | | ✓ | | | |
| Déposer, importer, modifier ses jeux | | | ✓ | ✓ | ✓ |
| Supprimer ses jeux non publiés | | | ✓ | ✓ | ✓ |
| Valider / refuser / dépublier, modifier tous les jeux du site | | | | ✓ | ✓ |
| Configurer le site, gérer ses membres | | | | ✓ | ✓ |
| Créer des sites, noms d'hôte, comptes, administrateurs | | | | | ✓ |

Règles centralisées dans `backend/src/Service/Permissions.php`. Avec la modération activée, un dépôt
d'utilisateur passe par l'état « soumis » ; toute modification d'un jeu publié par un utilisateur le
renvoie en validation.

## Carte : regroupement selon le zoom

`GET /api/sites/{slug}/map?zoom=&bbox=&…filtres` :
- si la vue contient peu de points (≤ 1 500) ou que le zoom dépasse `clusterMaxZoom`, les points sont renvoyés tels quels (markercluster affine côté navigateur) ;
- sinon PostgreSQL agrège les points sur une grille Web-Mercator de 80 px à ce zoom (`GROUP BY floor(x/maille), floor(y/maille)`) et renvoie centroïdes, effectifs et emprises ; un clic sur un regroupement zoome sur son emprise.

Aucune extension (PostGIS, Elasticsearch) n'est requise ; l'index `(latitude, longitude)` suffit pour
quelques millions de points. PostGIS pourra être ajouté si des géométries non ponctuelles
(lignes, polygones) deviennent nécessaires.

## Format d'import

Une ligne = un point. Colonnes communes :

| Colonne | Niveau | Contenu |
|---|---|---|
| `dataset_id` | jeu | UUID d'un jeu existant → **mise à jour** (tous ses points sont remplacés) |
| `dataset_ref` | jeu | Regroupe les lignes d'un même jeu |
| `title`, `description`, `keywords`, `authors`, `license`, `access_right`, `language`, `temporal_start`, `temporal_end`, `doi` | jeu | lus sur la première ligne renseignée du groupe ; listes séparées par « ; », auteurs `Nom (Affiliation)` |
| `point_name`, `longitude`, `latitude`, `elevation`, `vertical_datum`, `observed_at`, `observed_end` | point | dates : `AAAA`, `AAAA-MM`, `AAAA-MM-JJ`, `JJ/MM/AAAA`, ISO 8601, numéro de série Excel |
| `crs`, `x`, `y` | point | coordonnées projetées converties en WGS84 dans le navigateur (proj4) |
| `<clé de champ>` | selon le champ | champs spécifiques du site |

Les en-têtes sont reconnus par leur clé ou leur libellé ; l'utilisateur peut corriger la
correspondance avant le contrôle. L'import est transactionnel (tout ou rien).

## API

Routes JSON sous `/api` (liste détaillée en tête de chaque contrôleur) :

- `GET /api/context`, `GET /api/sites`, `GET|PUT /api/sites/{slug}`, `POST /api/sites/{slug}/logo`
- `GET /api/sites/{slug}/map|facets|datasets`, `POST /api/sites/{slug}/datasets`, `GET|PUT|DELETE /api/sites/{slug}/datasets/{uuid}`, `POST …/{uuid}/status`, `POST|DELETE …/{uuid}/files`, `GET /api/files/{id}`
- `GET /api/sites/{slug}/import/columns`, `POST …/import/preview|commit`, `GET /api/sites/{slug}/export?format=json|csv`
- `GET|POST|PATCH|DELETE /api/sites/{slug}/members…`, `POST /api/sites/{slug}/join`
- `POST /api/auth/login|refresh|logout`, `GET /api/auth/federation/login|callback`, `GET /api/auth/shibboleth`, `GET|PATCH /api/me`
- `GET|POST|PATCH /api/admin/users…`, `POST|DELETE /api/admin/sites…`

Filtres communs : `q`, `kw`, `from`, `to`, `elevMin`, `elevMax`, `bbox=w,s,e,n`, `f[<clé>]=a,b`,
`f[<clé>][min|max]`, `scope=public|mine|manage`, `status`.
