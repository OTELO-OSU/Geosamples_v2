<?php

namespace App\Service;

use App\Entity\Dataset;
use App\Entity\Site;
use App\Exception\ApiException;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

/**
 * Recherche, carte (regroupement selon le zoom), facettes et export.
 *
 * Tout est fait en SQL natif PostgreSQL (pas d'Elasticsearch) :
 *  - filtres au niveau du jeu (texte, mots-clés, champs spécifiques, statut)
 *  - filtres au niveau du point (emprise, altitude, période, champs "point")
 *  - regroupement en grille Web-Mercator dont la taille de maille dépend du zoom
 */
final class DatasetQuery
{
    public const CLUSTER_CELL_PX = 80;
    public const POINTS_THRESHOLD = 1500;
    public const MAX_POINTS = 20000;

    private const HOLE_ID_PATH = "COALESCE(
        NULLIF(trim(p.properties ->> 'hole_id'), ''),
        NULLIF(trim(CASE WHEN left(p.properties ->> 'holeid_point', 1) = '{'
                        THEN (p.properties ->> 'holeid_point')::jsonb ->> 'HOLEID' END), ''),
        NULLIF(trim(p.properties #>> '{holeid_point,HOLEID}'), '')
    )";

    private const HOLE = "COALESCE(
        'h:' || " . self::HOLE_ID_PATH . ",
        'c:' || round(p.longitude::numeric, 5)::text || '/' || round(p.latitude::numeric, 5)::text
    )";

    private int $pi = 0;

    public function __construct(private Connection $db, private Permissions $perm) {}

    /** Lit les filtres depuis la query string. */
    public function filtersFromRequest(Request $r): array
    {
        $q = $r->query->all();
        $f = $q['f'] ?? [];
        return [
            'q' => trim((string) ($q['q'] ?? '')),
            'keywords' => array_values(array_filter(array_map('trim', \is_array($q['kw'] ?? null) ? $q['kw'] : explode(',', (string) ($q['kw'] ?? ''))))),
            'from' => $q['from'] ?? null,
            'to' => $q['to'] ?? null,
            'elevMin' => $q['elevMin'] ?? null,
            'elevMax' => $q['elevMax'] ?? null,
            'bbox' => $q['bbox'] ?? null,
            'scope' => $q['scope'] ?? 'public',
            'status' => $q['status'] ?? null,
            'nature'     => $this->listParam($q['nature'] ?? []),
            'sampleType' => $this->listParam($q['sample-type'] ?? []),
            'f' => \is_array($f) ? $f : [],
        ];
    }

    private function listParam(mixed $v): array
    {
        $v = \is_array($v) ? $v : explode(',', (string) $v);
        return array_values(array_filter(array_map(fn ($x) => trim((string) $x), $v), static fn ($x) => $x !== ''));
    }

    /** Tableau JSONB sûr : '[]' si la clé est absente ou n'est pas un tableau. */
    private function jsonArr(string $expr): string
    {
        return "(CASE WHEN jsonb_typeof($expr) = 'array' THEN $expr ELSE '[]'::jsonb END)";
    }

    /**
     * Construit les clauses WHERE.
     * @return array{0:string, 1:string, 2:array, 3:array} [where jeu, where point, params, types]
     */
    private function build(Site $site, array $f): array
    {
        $this->pi = 0;
        $dw = ['d.site_id = :site'];
        $pw = [];
        $params = ['site' => $site->getId()];
        $types = [];

        // Portée de la requête
        $user = $this->perm->user();
        switch ($f['scope']) {
            case 'mine':
                if (!$user) {
                    throw ApiException::unauthorized();
                }
                $dw[] = 'd.owner_id = :uid';
                $params['uid'] = $user->getId();
                break;
            case 'manage':
                $this->perm->denyUnless($this->perm->canManageSite($site));
                break;
            default:
                $dw[] = "d.status = 'published'";
        }
        if ($f['status'] && $f['scope'] !== 'public') {
            if (!\in_array($f['status'], Dataset::STATUSES, true)) {
                throw new ApiException('Statut inconnu');
            }
            $dw[] = 'd.status = :status';
            $params['status'] = $f['status'];
        }

        if ($f['q'] !== '') {
            $dw[] = '(d.title ILIKE :q OR d.description ILIKE :q OR d.keywords::text ILIKE :q OR d.properties::text ILIKE :q OR EXISTS (SELECT 1 FROM sampling_point sp WHERE sp.dataset_id = d.id AND sp.name ILIKE :q))';
            $params['q'] = '%' . addcslashes($f['q'], '%_\\') . '%';
        }
        foreach ($f['keywords'] as $kw) {
            $p = $this->p();
            $dw[] = "EXISTS (SELECT 1 FROM jsonb_array_elements_text(d.keywords::jsonb) k WHERE lower(k) = lower(:$p))";
            $params[$p] = $kw;
        }

        if (!empty($f['nature'])) {
            $vp = $this->p();
            $params[$vp] = $f['nature'];
            $types[$vp] = ArrayParameterType::STRING;
            $dw[] = "EXISTS (
                SELECT 1
                FROM jsonb_array_elements(" . $this->jsonArr("d.properties -> 'source_measurements'") . ") AS sm(v),
                    jsonb_array_elements(" . $this->jsonArr("sm.v -> 'measurements'") . ") AS m(v)
                WHERE m.v ->> 'NATURE' IN (:$vp)
            )";
        }
        if (!empty($f['sampleType'])) {
            $vp = $this->p();
            $params[$vp] = $f['sampleType'];
            $types[$vp] = ArrayParameterType::STRING;
            $dw[] = "EXISTS (
                SELECT 1
                FROM jsonb_array_elements(" . $this->jsonArr("d.properties -> 'source_samples'") . ") AS ss(v),
                    jsonb_array_elements(" . $this->jsonArr("ss.v -> 'samples'") . ") AS s(v)
                WHERE s.v ->> 'sample kind' IN (:$vp)
            )";
        }

        // Champs spécifiques filtrables
        foreach ($site->getFields() as $field) {
            $key = $field['key'];
            if (empty($field['filter']) || !\array_key_exists($key, $f['f'])) {
                continue;
            }
            $val = $f['f'][$key];
            $col = ($field['scope'] ?? 'dataset') === 'point' ? 'p.properties' : 'd.properties';
            $target = ($field['scope'] ?? 'dataset') === 'point' ? 'pw' : 'dw';
            $kp = $this->p();
            $params[$kp] = $key;
            $clause = null;
            switch ($field['filter']) {
                case 'select':
                    $vals = array_values(array_filter(array_map('strval', \is_array($val) ? $val : explode(',', (string) $val)), static fn ($v) => $v !== ''));
                    if (!$vals) {
                        break;
                    }
                    $vp = $this->p();
                    $params[$vp] = $vals;
                    $types[$vp] = ArrayParameterType::STRING;
                    if ($field['type'] === 'multiselect') {
                        $clause = "jsonb_exists_any($col -> :$kp, ARRAY[:$vp]::text[])";
                    } else {
                        $clause = "($col ->> :$kp) IN (:$vp)";
                    }
                    break;
                case 'range':
                    $min = \is_array($val) ? ($val['min'] ?? null) : null;
                    $max = \is_array($val) ? ($val['max'] ?? null) : null;
                    $expr = "(CASE WHEN jsonb_typeof($col -> :$kp) = 'number' THEN ($col ->> :$kp)::float8 END)";
                    $parts = [];
                    if (is_numeric($min)) { $mp = $this->p(); $params[$mp] = (float) $min; $parts[] = "$expr >= :$mp"; }
                    if (is_numeric($max)) { $mp = $this->p(); $params[$mp] = (float) $max; $parts[] = "$expr <= :$mp"; }
                    $clause = $parts ? implode(' AND ', $parts) : null;
                    break;
                case 'text':
                    if (trim((string) $val) === '') {
                        break;
                    }
                    $vp = $this->p();
                    $params[$vp] = '%' . addcslashes(trim((string) $val), '%_\\') . '%';
                    $clause = "($col ->> :$kp) ILIKE :$vp";
                    break;
            }
            if ($clause) {
                if ($target === 'pw') {
                    $pw[] = $clause;
                } else {
                    $dw[] = $clause;
                }
            }
        }

        // Niveau point : emprise, altitude, période
        if ($f['bbox']) {
            $b = array_map('floatval', explode(',', (string) $f['bbox']));
            if (\count($b) === 4) {
                [$w, $s, $e, $n] = $b;
                $params += ['bw' => $w, 'bs' => $s, 'be' => $e, 'bn' => $n];
                $pw[] = 'p.latitude BETWEEN :bs AND :bn';
                $pw[] = $w <= $e ? 'p.longitude BETWEEN :bw AND :be' : '(p.longitude >= :bw OR p.longitude <= :be)';
            }
        }
        if (is_numeric($f['elevMin'])) { $pw[] = 'p.elevation >= :emin'; $params['emin'] = (float) $f['elevMin']; }
        if (is_numeric($f['elevMax'])) { $pw[] = 'p.elevation <= :emax'; $params['emax'] = (float) $f['elevMax']; }
        try {
            if ($from = DateParser::parse($f['from'])) {
                $pw[] = 'COALESCE(p.observed_end, p.observed_at, d.temporal_end, d.temporal_start) >= :tfrom';
                $params['tfrom'] = $from->format('Y-m-d H:i:sP');
            }
            if ($to = DateParser::parse($f['to'], true)) {
                $pw[] = 'COALESCE(p.observed_at, d.temporal_start) <= :tto';
                $params['tto'] = $to->format('Y-m-d H:i:sP');
            }
        } catch (\InvalidArgumentException $e) {
            throw new ApiException($e->getMessage());
        }

        return [implode(' AND ', $dw), implode(' AND ', $pw), $params, $types];
    }

    private function p(): string { return 'x' . (++$this->pi); }

    private function datasetWhere(string $dw, string $pw): string
    {
        return $pw === '' ? $dw : "$dw AND EXISTS (SELECT 1 FROM sampling_point p WHERE p.dataset_id = d.id AND $pw)";
    }

    // ------------------------------------------------------------------ liste
    public function list(Site $site, array $f, int $page = 1, int $perPage = 20, string $sort = 'updated'): array
    {
        [$dw, $pw, $params, $types] = $this->build($site, $f);
        $where = $this->datasetWhere($dw, $pw);
        $order = match ($sort) {
            'title' => 'd.title ASC',
            'date' => 'd.temporal_start DESC NULLS LAST',
            'date_asc' => 'd.temporal_start ASC NULLS LAST',
            'created' => 'd.created_at DESC',
            default => 'd.updated_at DESC',
        };
        $total = (int) $this->db->fetchOne("SELECT count(*) FROM dataset d WHERE $where", $params, $types);
        $perPage = max(1, min(200, $perPage));
        $offset = (max(1, $page) - 1) * $perPage;
        $rows = $this->db->fetchAllAssociative(
            "SELECT d.id, d.uuid, d.title, d.status, d.keywords, d.temporal_start, d.temporal_end, d.updated_at,
                    d.access_right, d.properties, d.doi, d.owner_id,
                    trim(coalesce(u.firstname,'') || ' ' || coalesce(u.lastname,'')) AS owner_name,
                    (SELECT count(*) FROM sampling_point sp WHERE sp.dataset_id = d.id) AS n_points,
                    (SELECT json_build_array(min(sp.longitude), min(sp.latitude), max(sp.longitude), max(sp.latitude), min(sp.elevation), max(sp.elevation))
                       FROM sampling_point sp WHERE sp.dataset_id = d.id) AS extent,
                    (SELECT f.id FROM dataset_file f WHERE f.dataset_id = d.id AND f.kind = 'picture' ORDER BY f.id LIMIT 1) AS thumb
               FROM dataset d LEFT JOIN app_user u ON u.id = d.owner_id
              WHERE $where ORDER BY $order, d.id DESC LIMIT $perPage OFFSET $offset",
            $params, $types
        );
        $restricted = !$this->perm->isMember($site);
        $items = array_map(function (array $r) use ($restricted) {
            $ext = json_decode((string) $r['extent'], true) ?: [];
            return [
                'uuid' => $r['uuid'],
                'title' => $r['title'],
                'status' => $r['status'],
                'keywords' => json_decode($r['keywords'], true),
                'temporalStart' => $this->iso($r['temporal_start']),
                'temporalEnd' => $this->iso($r['temporal_end']),
                'updatedAt' => $this->iso($r['updated_at']),
                'accessRight' => $r['access_right'],
                'properties' => (object) json_decode($r['properties'], true),
                'doi' => $r['doi'],
                'owner' => $r['owner_name'] ?: null,
                'pointsCount' => (int) $r['n_points'],
                'bbox' => isset($ext[0]) ? [(float) $ext[0], (float) $ext[1], (float) $ext[2], (float) $ext[3]] : null,
                'elevationRange' => isset($ext[4]) ? [(float) $ext[4], (float) $ext[5]] : null,
                'thumbnail' => ($r['thumb'] && (!$restricted || $r['access_right'] === 'open')) ? '/api/files/' . $r['thumb'] . '?inline=1' : null,
            ];
        }, $rows);

        return ['total' => $total, 'page' => max(1, $page), 'perPage' => $perPage, 'items' => $items];
    }

    // ------------------------------------------------------------------ carte
    public function map(Site $site, array $f, int $zoom): array
    {
        [$dw, $pw, $params, $types] = $this->build($site, $f);

        $where = $dw . ($pw !== '' ? " AND $pw" : '');
        $from = "FROM sampling_point p JOIN dataset d ON d.id = p.dataset_id WHERE $where";

        // Total number of samples
        $total = (int) $this->db->fetchOne(
            "SELECT count(*) $from",
            $params,
            $types
        );

        // Number of distinct holes/locations
        $locations = (int) $this->db->fetchOne(
            "SELECT count(*) FROM (
                SELECT 1 $from
                GROUP BY " . self::HOLE . "
            ) g",
            $params,
            $types
        );

        $zoom = max(0, min(22, $zoom));

        $mapCfg = $site->getMap();
        $maxZoom = (int) ($mapCfg['clusterMaxZoom'] ?? 14);

        // Show individual samples when there are few locations
        $usePoints = $locations <= self::POINTS_THRESHOLD
            || ($zoom >= $maxZoom && $total <= self::MAX_POINTS);

        if ($usePoints) {
            return [
                'mode' => 'points',
                'total' => $total,
                'locations' => $locations,
                'clusters' => [],
                'points' => $this->pointRows(
                    $site,
                    $from,
                    $params,
                    $types,
                    self::MAX_POINTS
                ),
            ];
        }

        // Calculate cluster grid size according to zoom level
        $scale = self::CLUSTER_CELL_PX / 256 / (2 ** $zoom);

        $params['cx'] = 360 * $scale;
        $params['cy'] = 2 * M_PI * $scale;

        $rows = $this->db->fetchAllAssociative(
        "WITH point_coordinates AS (
            SELECT
                p.id,
                " . self::HOLE . " AS loc,
                CASE
                    WHEN left(p.properties ->> 'holeid_point', 1) = '{'
                    THEN
                        COALESCE(
                            (p.properties ->> 'holeid_point')::jsonb
                                ->> 'HOLEID_LONGITUDE',
                            p.longitude::text
                        )::double precision
                    ELSE p.longitude
                END AS lon,
                CASE
                    WHEN left(p.properties ->> 'holeid_point', 1) = '{'
                    THEN
                        COALESCE(
                            (p.properties ->> 'holeid_point')::jsonb
                                ->> 'HOLEID_LATITUDE',
                            p.latitude::text
                        )::double precision
                    ELSE p.latitude
                END AS lat
            $from
        )
        SELECT
            count(DISTINCT loc) AS n,
            avg(lon) AS lon,
            avg(lat) AS lat,
            min(lon) AS w,
            min(lat) AS s,
            max(lon) AS e,
            max(lat) AS nn,
            json_agg(id) AS ids
        FROM point_coordinates
        GROUP BY
            floor(lon / :cx),
            floor(
                ln(
                    tan(
                        pi()/4 + radians(
                            LEAST(GREATEST(lat, -85.05), 85.05)
                        ) / 2
                    )
                ) / :cy
            )",
        $params,
        $types
    );

        $clusters = [];
        $singles = [];

        foreach ($rows as $r) {
            if ((int) $r['n'] === 1) {
                foreach (json_decode($r['ids'], true) as $id) {
                    $singles[] = (int) $id;
                }
                continue;
            }

            $clusters[] = [
                'lat' => (float) $r['lat'],
                'lon' => (float) $r['lon'],
                'count' => (int) $r['n'],
                'bbox' => [
                    (float) $r['w'],
                    (float) $r['s'],
                    (float) $r['e'],
                    (float) $r['nn'],
                ],
            ];
        }

        // Return individual points belonging to single-hole grid cells
        $points = [];

        if ($singles) {
            $points = $this->pointRows(
                $site,
                'FROM sampling_point p
                JOIN dataset d ON d.id = p.dataset_id
                WHERE p.id IN (:singles)',
                ['singles' => $singles],
                ['singles' => ArrayParameterType::INTEGER],
                count($singles)
            );
        }

        return [
            'mode' => 'clusters',
            'total' => $total,
            'locations' => $locations,
            'clusters' => $clusters,
            'points' => $points,
        ];
    }

    private function pointRows(Site $site, string $from, array $params, array $types, int $limit): array
    {
        [$colorExpr, $colorParams] = $this->colorExpr($site);
        $rows = $this->db->fetchAllAssociative(
            "SELECT p.id, p.longitude, p.latitude, p.elevation, p.name, d.uuid, d.title, d.status, " . self::HOLE . " AS loc,
            CASE
                WHEN left(p.properties ->> 'holeid_point', 1) = '{'
                THEN p.properties ->> 'holeid_point'
            END AS holeid,
            COALESCE(NULLIF(p.properties ->> 'sample_name', ''), p.name) AS sample,
            COALESCE(p.observed_at, d.temporal_start) AS t,
            $colorExpr AS v $from
            ORDER BY p.id
            LIMIT $limit",
            $params + $colorParams,
            $types
        );

        return array_map(function ($r) {
            $hole = !empty($r['holeid'])? json_decode($r['holeid'], true) : null;
            return [
                'id' => (int) $r['id'],
                'lat' => isset($hole['HOLEID_LATITUDE'])
                    ? (float) $hole['HOLEID_LATITUDE']
                    : (float) $r['latitude'],
                'lon' => isset($hole['HOLEID_LONGITUDE'])
                    ? (float) $hole['HOLEID_LONGITUDE']
                    : (float) $r['longitude'],
                'elev' => $r['elevation'] !== null
                    ? (float) $r['elevation']
                    : null,
                'name' => $r['name'],
                'date' => $this->iso($r['t']),
                'dataset' => $r['uuid'],
                'title' => $r['title'],
                'status' => $r['status'],
                'v' => $r['v'],
                'loc' => $r['loc'],
                'sample' => $r['sample'],
            ];
        }, $rows);
    }

    /** Valeur servant à colorer les points (map.colorBy du site). */
    private function colorExpr(Site $site): array
    {
        $by = $site->getMap()['colorBy'] ?? 'none';
        if ($by === 'elevation') {
            return ['p.elevation', []];
        }
        if ($by === 'year') {
            return ['EXTRACT(YEAR FROM COALESCE(p.observed_at, d.temporal_start))', []];
        }
        if ($by === 'status') {
            return ['d.status', []];
        }
        $field = $site->getFieldMap()[$by] ?? null;
        if ($field) {
            $col = ($field['scope'] ?? 'dataset') === 'point' ? 'p.properties' : 'd.properties';
            return ["($col ->> :colorkey)", ['colorkey' => $by]];
        }
        return ['NULL', []];
    }

    // ------------------------------------------------------------------ facettes
    public function facets(Site $site, array $f): array
    {
        [$dw, $pw, $params, $types] = $this->build($site, $f);
        $dWhere = $this->datasetWhere($dw, $pw);
        $pWhere = $dw . ($pw !== '' ? " AND $pw" : '');

        $stats = $this->db->fetchAssociative(
            "SELECT count(*) AS n_points, count(DISTINCT d.id) AS n_datasets,
                    min(p.elevation) AS emin, max(p.elevation) AS emax,
                    min(COALESCE(p.observed_at, d.temporal_start)) AS tmin,
                    max(COALESCE(p.observed_end, p.observed_at, d.temporal_end, d.temporal_start)) AS tmax,
                    min(p.longitude) AS w, min(p.latitude) AS s, max(p.longitude) AS e, max(p.latitude) AS n
             FROM sampling_point p JOIN dataset d ON d.id = p.dataset_id WHERE $pWhere",
            $params, $types
        );

        // Histogramme temporel : par année, ou par mois si la plage couvre moins de 3 ans
        $histogram = [];
        $unit = 'year';
        if ($stats['tmin'] && $stats['tmax']) {
            $span = (new \DateTimeImmutable($stats['tmin']))->diff(new \DateTimeImmutable($stats['tmax']))->y;
            $unit = $span < 3 ? 'month' : 'year';
            $rows = $this->db->fetchAllAssociative(
                "SELECT date_trunc('$unit', COALESCE(p.observed_at, d.temporal_start)) AS b, count(*) AS n
                 FROM sampling_point p JOIN dataset d ON d.id = p.dataset_id
                 WHERE $pWhere AND COALESCE(p.observed_at, d.temporal_start) IS NOT NULL
                 GROUP BY 1 ORDER BY 1",
                $params, $types
            );
            $histogram = array_map(fn ($r) => ['bucket' => substr((string) $r['b'], 0, $unit === 'year' ? 4 : 7), 'count' => (int) $r['n']], $rows);
        }

        $keywords = $this->db->fetchAllAssociative(
            "SELECT k AS value, count(*) AS n FROM dataset d, jsonb_array_elements_text(d.keywords::jsonb) k
             WHERE $dWhere GROUP BY k ORDER BY n DESC, k ASC LIMIT 60",
            $params, $types
        );

        $fields = [];
        foreach ($site->getFields() as $field) {
            if (empty($field['filter'])) {
                continue;
            }
            $key = $field['key'];
            $isPoint = ($field['scope'] ?? 'dataset') === 'point';
            $col = $isPoint ? 'p.properties' : 'd.properties';
            $base = $isPoint
                ? "FROM sampling_point p JOIN dataset d ON d.id = p.dataset_id WHERE $pWhere"
                : "FROM dataset d WHERE $dWhere";
            $fp = $params + ['fkey' => $key];
            if ($field['filter'] === 'select') {
                if ($field['type'] === 'multiselect') {
                    $sql = "SELECT v AS value, count(*) AS n " . preg_replace('/^FROM (\S+ \S+)/', 'FROM $1 CROSS JOIN LATERAL jsonb_array_elements_text(' . $col . ' -> :fkey) v', $base)
                         . " AND jsonb_typeof($col -> :fkey) = 'array' GROUP BY 1 ORDER BY 2 DESC LIMIT 200";
                } else {
                    $sql = "SELECT ($col ->> :fkey) AS value, count(*) AS n $base AND ($col ->> :fkey) IS NOT NULL GROUP BY 1 ORDER BY 2 DESC LIMIT 200";
                }
                $vals = $this->db->fetchAllAssociative($sql, $fp, $types);
                $fields[$key] = ['type' => 'select', 'values' => array_map(fn ($r) => ['value' => $r['value'], 'count' => (int) $r['n']], $vals)];
            } elseif ($field['filter'] === 'range') {
                $r = $this->db->fetchAssociative(
                    "SELECT min(($col ->> :fkey)::float8) AS mn, max(($col ->> :fkey)::float8) AS mx $base AND jsonb_typeof($col -> :fkey) = 'number'",
                    $fp, $types
                );
                $fields[$key] = ['type' => 'range', 'min' => $r['mn'] !== null ? (float) $r['mn'] : null, 'max' => $r['mx'] !== null ? (float) $r['mx'] : null];
            }
        }

        $statusCounts = null;
        if ($f['scope'] !== 'public') {
            $statusCounts = [];
            $f2 = $f;
            $f2['status'] = null;
            [$dw2, $pw2, $p2, $t2] = $this->build($site, $f2);
            foreach ($this->db->fetchAllAssociative('SELECT d.status, count(*) AS n FROM dataset d WHERE ' . $this->datasetWhere($dw2, $pw2) . ' GROUP BY 1', $p2, $t2) as $r) {
                $statusCounts[$r['status']] = (int) $r['n'];
            }
        }

        $natures = $this->db->fetchAllAssociative(
            "SELECT m.v ->> 'NATURE' AS value, count(DISTINCT d.id) AS n
            FROM dataset d
            CROSS JOIN LATERAL jsonb_array_elements(" . $this->jsonArr("d.properties -> 'source_measurements'") . ") AS sm(v)
            CROSS JOIN LATERAL jsonb_array_elements(" . $this->jsonArr("sm.v -> 'measurements'") . ") AS m(v)
            WHERE $dWhere AND m.v ->> 'NATURE' IS NOT NULL
            GROUP BY 1 ORDER BY 2 DESC, 1 ASC LIMIT 200",
            $params, $types
        );
        $sampleTypes = $this->db->fetchAllAssociative(
            "SELECT s.v ->> 'sample kind' AS value, count(DISTINCT d.id) AS n
            FROM dataset d
            CROSS JOIN LATERAL jsonb_array_elements(" . $this->jsonArr("d.properties -> 'source_samples'") . ") AS ss(v)
            CROSS JOIN LATERAL jsonb_array_elements(" . $this->jsonArr("ss.v -> 'samples'") . ") AS s(v)
            WHERE $dWhere AND s.v ->> 'sample kind' IS NOT NULL
            GROUP BY 1 ORDER BY 2 DESC, 1 ASC LIMIT 200",
            $params, $types
        );
        $fmt = fn (array $rows) => array_map(fn ($r) => ['value' => $r['value'], 'count' => (int) $r['n']], $rows);

        return [
            'datasets' => (int) $stats['n_datasets'],
            'points' => (int) $stats['n_points'],
            'elevation' => ['min' => $stats['emin'] !== null ? (float) $stats['emin'] : null, 'max' => $stats['emax'] !== null ? (float) $stats['emax'] : null],
            'temporal' => ['min' => $this->iso($stats['tmin']), 'max' => $this->iso($stats['tmax']), 'unit' => $unit, 'histogram' => $histogram],
            'bbox' => $stats['w'] !== null ? [(float) $stats['w'], (float) $stats['s'], (float) $stats['e'], (float) $stats['n']] : null,
            'keywords' => array_map(fn ($r) => ['value' => $r['value'], 'count' => (int) $r['n']], $keywords),
            'fields' => (object) $fields,
            'statusCounts' => $statusCounts,
            'measurement' => ['nature' => $fmt($natures)],
            'sample'      => ['sample-type' => $fmt($sampleTypes)],
        ];
    }

    /** Identifiants des jeux correspondant aux filtres (export). @return int[] */
    public function ids(Site $site, array $f, int $limit = 10000): array
    {
        [$dw, $pw, $params, $types] = $this->build($site, $f);
        return array_map('intval', $this->db->fetchFirstColumn(
            'SELECT d.id FROM dataset d WHERE ' . $this->datasetWhere($dw, $pw) . " ORDER BY d.id LIMIT $limit",
            $params, $types
        ));
    }

    private function iso(?string $v): ?string
    {
        return $v ? (new \DateTimeImmutable($v))->format(\DateTimeInterface::ATOM) : null;
    }
}
