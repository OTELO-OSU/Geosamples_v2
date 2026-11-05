# Architecture

## One instance, multiple sites

```
            nometal.otelo.univ-lorraine.fr ─┐
               orne.otelo.univ-lorraine.fr ─┼─► Apache (TLS) ─► nginx ─┬─► compiled frontend (SPA)
 geosamples.otelo.univ-lorraine.fr/s/<slug> ─┘   ProxyPreserveHost      └─► /api → php-fpm (Symfony) ─► PostgreSQL
```

The current site is determined, in this order, by:
1. the `{slug}` parameter in `/api/sites/{slug}/…` routes;
2. the `X-Site` header, sent by the frontend when served under `/s/<slug>/`;
3. the request hostname (`site.hostnames`);
4. `DEFAULT_SITE`.

If no site is resolved, the frontend displays the **portal** (site list and global administration).

## Data model

| Table | Purpose |
|---|---|
| `site` | Complete site configuration: `hostnames`, text (Markdown), `theme`, `map` (center, zoom, basemaps, `colorBy`, `clusterMaxZoom`), `fields` (custom fields), `import_template`, `settings` (moderation, defaults, contact, etc.), and `published`. |
| `app_user` | Account; `federation_id` (eppn, OIDC `sub`, or ORCID identifier) for federated accounts, `password` for local accounts, and `ROLE_ADMIN` in `roles`. |
| `site_membership` | Account role on a site: manager or contributor; status `active` or `pending` (membership request). |
| `dataset` | Dataset: shared metadata in columns, site fields in `properties` (JSONB), `temporal_start/end`, `status`, and `doi`/`ordar_url` after publication in ORDaR. |
| `sampling_point` | Point (station, sample): `longitude`, `latitude` (WGS84), `elevation` + `vertical_datum`, `observed_at/observed_end`, original coordinates (`src_crs`, `src_x`, `src_y`), and per-point measurements in `properties` (JSONB). |
| `dataset_file` | Files (data, photos, documents), stored under `UPLOAD_DIR/<site>/<uuid>/`. |
| `refresh_token` | SHA-256 hashes of refresh tokens (rotated on each use). |

### Site-specific fields

```json
{ "key": "lithology", "label": "Lithology", "type": "select", "options": ["Granite", "Sandstone"],
  "required": true, "unit": null, "help": null, "group": "Geology",
  "filter": "select", "popup": true, "scope": "dataset" }
```

- `type`: `text`, `textarea`, `number`, `integer`, `date`, `select`, `multiselect`, `boolean`, `url`
- `scope`: `dataset` (one value per dataset) or `point` (one value per point/date: time-series measurements)
- `filter`: `select`, `range`, or `text` to expose it in the filter panel; available values and counts are calculated on demand (`/facets`).

Values are validated and normalized (decimal separators, case- and accent-insensitive lists,
yes/no, etc.) by `FieldSchema`. Replacing geo-samples' `form_<project>.twig` forms with this
database definition eliminates project-specific development.

## Authorization

| | public | signed-in non-member | user | manager | admin |
|---|---|---|---|---|---|
| View the map and published datasets | ✓ | ✓ | ✓ | ✓ | ✓ |
| Download files from an open dataset | ✓ | ✓ | ✓ | ✓ | ✓ |
| Download files from a restricted dataset | | | ✓ | ✓ | ✓ |
| Request contributor access | | ✓ | | | |
| Submit, import, and edit own datasets | | | ✓ | ✓ | ✓ |
| Delete own unpublished datasets | | | ✓ | ✓ | ✓ |
| Approve / reject / unpublish, edit all site datasets | | | | ✓ | ✓ |
| Configure the site and manage its members | | | | ✓ | ✓ |
| Create sites, hostnames, accounts, and administrators | | | | | ✓ |

Rules are centralized in `backend/src/Service/Permissions.php`. When moderation is enabled, user
submissions enter the “submitted” state; any changes a user makes to a published dataset send it
back for review.

## Map: clustering by zoom level

`GET /api/sites/{slug}/map?zoom=&bbox=&…filtres` :
- if the view contains few points (≤ 1,500) or the zoom exceeds `clusterMaxZoom`, points are returned as-is (markercluster refines them in the browser);
- otherwise, PostgreSQL aggregates points on an 80 px Web Mercator grid at that zoom (`GROUP BY floor(x/cell), floor(y/cell)`) and returns centroids, counts, and extents; clicking a cluster zooms to its extent.

No extensions (PostGIS, Elasticsearch) are required; the `(latitude, longitude)` index is sufficient
for a few million points. PostGIS can be added if non-point geometries (lines, polygons) become necessary.

## Import format

One row = one point. Common columns:

| Column | Level | Contents |
|---|---|---|
| `dataset_id` | dataset | UUID of an existing dataset → **update** (all its points are replaced) |
| `dataset_ref` | dataset | Groups rows belonging to the same dataset |
| `title`, `description`, `keywords`, `authors`, `license`, `access_right`, `language`, `temporal_start`, `temporal_end`, `doi` | dataset | Read from the first populated row in the group; lists are separated by “;”, authors use `Name (Affiliation)` |
| `point_name`, `longitude`, `latitude`, `elevation`, `vertical_datum`, `observed_at`, `observed_end` | point | Dates: `YYYY`, `YYYY-MM`, `YYYY-MM-DD`, `DD/MM/YYYY`, ISO 8601, or Excel serial number |
| `crs`, `x`, `y` | point | Projected coordinates converted to WGS84 in the browser (proj4) |
| `<field key>` | depends on field | Site-specific fields |

Headers are matched by key or label; users can correct the mapping before validation. Imports are
transactional (all or nothing).

## API

JSON routes under `/api` (see the top of each controller for the full list):

- `GET /api/context`, `GET /api/sites`, `GET|PUT /api/sites/{slug}`, `POST /api/sites/{slug}/logo`
- `GET /api/sites/{slug}/map|facets|datasets`, `POST /api/sites/{slug}/datasets`, `GET|PUT|DELETE /api/sites/{slug}/datasets/{uuid}`, `POST …/{uuid}/status`, `POST|DELETE …/{uuid}/files`, `GET /api/files/{id}`
- `GET /api/sites/{slug}/import/columns`, `POST …/import/preview|commit`, `GET /api/sites/{slug}/export?format=json|csv`
- `GET|POST|PATCH|DELETE /api/sites/{slug}/members…`, `POST /api/sites/{slug}/join`
- `POST /api/auth/login|refresh|logout`, `GET /api/auth/federation/login|callback`, `GET /api/auth/orcid/login|callback`, `GET /api/auth/shibboleth`, `GET|PATCH /api/me`
- `GET|POST|PATCH /api/admin/users…`, `POST|DELETE /api/admin/sites…`

Common filters: `q`, `kw`, `from`, `to`, `elevMin`, `elevMax`, `bbox=w,s,e,n`, `f[<key>]=a,b`,
`f[<field key>][min|max]`, `scope=public|mine|manage`, `status`.
