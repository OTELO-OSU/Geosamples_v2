<?php

namespace App\Service;

use App\Entity\Dataset;
use App\Entity\Site;
use App\Entity\User;
use App\Exception\ApiException;
use App\Repository\DatasetRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Import / export en lot selon un template tabulaire (CSV ou XLSX, lu et écrit côté
 * navigateur) : une ligne = un point de prélèvement. Les lignes partageant le même
 * `dataset_ref` forment un même jeu ; les métadonnées du jeu sont lues sur la
 * première ligne non vide du groupe. Une colonne `dataset_id` (UUID) désigne un jeu
 * existant à mettre à jour : c'est ce qui permet d'exporter des jeux, de les modifier
 * dans un tableur puis de les réimporter.
 */
final class ImportService
{
    public const MAX_ROWS = 20000;

    private const CORE = [
        ['key' => 'dataset_id', 'label' => 'Identifiant du jeu (mise à jour)', 'level' => 'dataset', 'help' => "UUID d'un jeu existant à remplacer ; vide pour créer"],
        ['key' => 'dataset_ref', 'label' => 'Référence du jeu', 'level' => 'dataset', 'help' => 'Regroupe plusieurs lignes (points) dans un même jeu'],
        ['key' => 'title', 'label' => 'Titre', 'level' => 'dataset', 'required' => true],
        ['key' => 'description', 'label' => 'Description', 'level' => 'dataset'],
        ['key' => 'keywords', 'label' => 'Mots-clés', 'level' => 'dataset', 'help' => 'Séparés par « ; »'],
        ['key' => 'authors', 'label' => 'Auteurs', 'level' => 'dataset', 'help' => 'Séparés par « ; », affiliation entre parenthèses : Marie Curie (GeoRessources)'],
        ['key' => 'license', 'label' => 'Licence', 'level' => 'dataset', 'example' => 'CC-BY-4.0'],
        ['key' => 'access_right', 'label' => 'Accès', 'level' => 'dataset', 'help' => 'open ou restricted', 'options' => ['open', 'restricted']],
        ['key' => 'language', 'label' => 'Langue', 'level' => 'dataset', 'example' => 'fr'],
        ['key' => 'temporal_start', 'label' => 'Début de période', 'level' => 'dataset', 'help' => 'AAAA, AAAA-MM, AAAA-MM-JJ ou JJ/MM/AAAA'],
        ['key' => 'temporal_end', 'label' => 'Fin de période', 'level' => 'dataset'],
        ['key' => 'doi', 'label' => 'DOI ORDaR', 'level' => 'dataset'],
        ['key' => 'point_name', 'label' => 'Nom du point', 'level' => 'point'],
        ['key' => 'longitude', 'label' => 'Longitude (WGS84)', 'level' => 'point', 'help' => 'Degrés décimaux ; ou renseigner crs + x + y'],
        ['key' => 'latitude', 'label' => 'Latitude (WGS84)', 'level' => 'point'],
        ['key' => 'elevation', 'label' => 'Altitude (m)', 'level' => 'point', 'help' => 'Négative pour une profondeur'],
        ['key' => 'vertical_datum', 'label' => 'Référentiel altimétrique', 'level' => 'point'],
        ['key' => 'observed_at', 'label' => 'Date du point', 'level' => 'point'],
        ['key' => 'observed_end', 'label' => 'Fin (point)', 'level' => 'point'],
        ['key' => 'crs', 'label' => 'Système de coordonnées', 'level' => 'point', 'help' => 'EPSG:4326, EPSG:2154 (Lambert 93), EPSG:326xx (UTM)…'],
        ['key' => 'x', 'label' => 'X / Est', 'level' => 'point'],
        ['key' => 'y', 'label' => 'Y / Nord', 'level' => 'point'],
    ];

    public function __construct(
        private DatasetWriter $writer,
        private DatasetRepository $datasets,
        private EntityManagerInterface $em,
        private Permissions $perm,
    ) {}

    /** Colonnes du template du site, après personnalisation par le responsable. */
    public function columns(Site $site): array
    {
        $custom = $site->getImportTemplate()['columns'] ?? [];
        $cols = [];
        foreach (self::CORE as $c) {
            $cols[] = $c + ['required' => false, 'group' => 'core', 'type' => 'text', 'options' => [], 'help' => null, 'example' => null];
        }
        foreach ($site->getFields() as $f) {
            $cols[] = [
                'key' => $f['key'], 'label' => $f['label'] . (!empty($f['unit']) ? " ({$f['unit']})" : ''),
                'level' => $f['scope'] ?? 'dataset', 'required' => (bool) ($f['required'] ?? false), 'group' => 'custom',
                'type' => $f['type'], 'options' => $f['options'] ?? [], 'help' => $f['help'] ?? null, 'example' => null,
            ];
        }
        foreach ($cols as &$c) {
            $o = $custom[$c['key']] ?? [];
            $c['include'] = $c['required'] || \in_array($c['key'], ['longitude', 'latitude'], true) ? true : (bool) ($o['include'] ?? true);
            if (!empty($o['label'])) {
                $c['label'] = (string) $o['label'];
            }
            if (isset($o['example']) && $o['example'] !== '') {
                $c['example'] = (string) $o['example'];
            }
        }
        unset($c);
        return $cols;
    }

    /**
     * Regroupe les lignes en jeux de données.
     * @return list<array{ref:string, datasetId:?string, rows:int[], payload:array}>
     */
    public function group(Site $site, array $rows): array
    {
        if (\count($rows) > self::MAX_ROWS) {
            throw new ApiException(sprintf('Trop de lignes (%d max.)', self::MAX_ROWS));
        }
        $fields = $site->getFieldMap();
        $groups = [];
        foreach (array_values($rows) as $i => $row) {
            $row = array_map(static fn ($v) => \is_string($v) ? trim($v) : $v, (array) $row);
            if (!array_filter($row, static fn ($v) => $v !== null && $v !== '')) {
                continue; // ligne vide
            }
            $id = (string) ($row['dataset_id'] ?? '');
            $ref = (string) ($row['dataset_ref'] ?? '');
            $gk = $id !== '' ? "id:$id" : ($ref !== '' ? "ref:$ref" : "row:$i");
            if (!isset($groups[$gk])) {
                $groups[$gk] = ['ref' => $ref ?: ($id ?: 'ligne ' . ($i + 2)), 'datasetId' => $id ?: null, 'rows' => [], 'meta' => [], 'points' => []];
            }
            $g = &$groups[$gk];
            $g['rows'][] = $i;
            foreach ($row as $k => $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                $level = $this->levelOf($k, $fields);
                if ($level === 'dataset' && !\array_key_exists($k, $g['meta'])) {
                    $g['meta'][$k] = $v;
                }
            }
            $pp = [];
            foreach ($fields as $k => $f) {
                if (($f['scope'] ?? 'dataset') === 'point' && isset($row[$k]) && $row[$k] !== '') {
                    $pp[$k] = $row[$k];
                }
            }
            $g['points'][] = [
                'name' => $row['point_name'] ?? null,
                'longitude' => $row['longitude'] ?? null,
                'latitude' => $row['latitude'] ?? null,
                'elevation' => $row['elevation'] ?? null,
                'verticalDatum' => $row['vertical_datum'] ?? null,
                'observedAt' => $row['observed_at'] ?? null,
                'observedEnd' => $row['observed_end'] ?? null,
                'srcCrs' => $row['crs'] ?? null,
                'srcX' => $row['x'] ?? null,
                'srcY' => $row['y'] ?? null,
                'properties' => $pp,
            ];
            // Coordonnées déjà en WGS84 fournies en x/y
            $last = array_key_last($g['points']);
            $crs = mb_strtoupper((string) ($row['crs'] ?? ''));
            if (($g['points'][$last]['longitude'] ?? '') === '' && \in_array($crs, ['EPSG:4326', 'WGS84', '4326'], true)) {
                $g['points'][$last]['longitude'] = $row['x'] ?? null;
                $g['points'][$last]['latitude'] = $row['y'] ?? null;
            }
            unset($g);
        }

        $out = [];
        foreach ($groups as $g) {
            $m = $g['meta'];
            $props = [];
            foreach ($fields as $k => $f) {
                if (($f['scope'] ?? 'dataset') === 'dataset' && isset($m[$k])) {
                    $props[$k] = $m[$k];
                }
            }
            $out[] = [
                'ref' => $g['ref'],
                'datasetId' => $g['datasetId'],
                'rows' => $g['rows'],
                'payload' => [
                    'title' => $m['title'] ?? '',
                    'description' => $m['description'] ?? null,
                    'keywords' => isset($m['keywords']) ? preg_split('/\s*;\s*/', (string) $m['keywords']) : [],
                    'authors' => isset($m['authors']) ? $this->parseAuthors((string) $m['authors']) : [],
                    'license' => $m['license'] ?? null,
                    'accessRight' => isset($m['access_right']) ? mb_strtolower((string) $m['access_right']) : null,
                    'language' => $m['language'] ?? null,
                    'temporalStart' => $m['temporal_start'] ?? null,
                    'temporalEnd' => $m['temporal_end'] ?? null,
                    'doi' => $m['doi'] ?? null,
                    'properties' => $props,
                    'points' => $g['points'],
                ],
            ];
        }
        return $out;
    }

    /** Simulation : aucune écriture, erreurs ramenées aux numéros de ligne du fichier. */
    public function preview(Site $site, array $rows, User $user): array
    {
        $result = [];
        $valid = 0;
        foreach ($this->group($site, $rows) as $g) {
            [$dataset, $action, $err] = $this->target($site, $g, $user);
            $errors = $err ? ['_' => $err] : $this->writer->validate($dataset, $this->withDefaults($g['payload'], $site));
            if (!$errors) {
                $valid++;
            }
            $result[] = [
                'ref' => $g['ref'],
                'action' => $action,
                'title' => $g['payload']['title'],
                'points' => \count($g['payload']['points']),
                'lines' => array_map(static fn ($i) => $i + 2, $g['rows']),
                'errors' => $this->mapErrors($errors, $g['rows']),
            ];
        }
        return ['datasets' => $result, 'valid' => $valid, 'invalid' => \count($result) - $valid];
    }

    /**
     * Import effectif, tout ou rien.
     * @return array{created: string[], updated: string[]}
     */
    public function commit(Site $site, array $rows, User $user, string $status): array
    {
        $created = $updated = [];
        $errors = [];
        $this->em->wrapInTransaction(function () use ($site, $rows, $user, $status, &$created, &$updated, &$errors) {
            foreach ($this->group($site, $rows) as $g) {
                [$dataset, $action, $err] = $this->target($site, $g, $user);
                if ($err) {
                    $errors[$g['ref']] = ['_' => $err];
                    continue;
                }
                try {
                    $this->writer->apply($dataset, $this->withDefaults($g['payload'], $site));
                } catch (ApiException $e) {
                    $errors[$g['ref']] = $this->mapErrors($e->getDetails(), $g['rows']);
                    continue;
                }
                $dataset->setUpdatedBy($user);
                if ($action === 'create') {
                    $dataset->setStatus($status);
                    $this->em->persist($dataset);
                    $created[] = $dataset;
                } else {
                    if (!$this->perm->canManageSite($site) && $dataset->isPublished() && $site->setting('moderation')) {
                        $dataset->setStatus(Dataset::STATUS_SUBMITTED);
                    }
                    $updated[] = $dataset;
                }
            }
            if ($errors) {
                throw ApiException::validation($errors, 'Import annulé : des jeux contiennent des erreurs');
            }
            $this->em->flush();
        });
        return [
            'created' => array_map(static fn (Dataset $d) => (string) $d->getUuid(), $created),
            'updated' => array_map(static fn (Dataset $d) => (string) $d->getUuid(), $updated),
        ];
    }

    /** Lignes au format du template pour une liste de jeux (export / modèle d'après un jeu existant). */
    public function exportRows(Site $site, array $datasets, bool $withIds = true): array
    {
        $fields = $site->getFields();
        $rows = [];
        foreach ($datasets as $d) {
            /** @var Dataset $d */
            $base = [
                'dataset_id' => $withIds ? (string) $d->getUuid() : '',
                'dataset_ref' => 'DS-' . substr((string) $d->getUuid(), -8),
                'title' => $d->getTitle(),
                'description' => $d->getDescription(),
                'keywords' => implode(' ; ', $d->getKeywords()),
                'authors' => implode(' ; ', array_map(static fn ($a) => $a['name'] . (!empty($a['affiliation']) ? " ({$a['affiliation']})" : ''), $d->getAuthors())),
                'license' => $d->getLicense(),
                'access_right' => $d->getAccessRight(),
                'language' => $d->getLanguage(),
                'temporal_start' => $d->getTemporalStart()?->format('Y-m-d'),
                'temporal_end' => $d->getTemporalEnd()?->format('Y-m-d'),
                'doi' => $d->getDoi(),
            ];
            foreach ($fields as $f) {
                if (($f['scope'] ?? 'dataset') === 'dataset') {
                    $v = $d->getProperties()[$f['key']] ?? null;
                    $base[$f['key']] = \is_array($v) ? implode(' ; ', $v) : (\is_bool($v) ? ($v ? 'oui' : 'non') : $v);
                }
            }
            foreach ($d->getPoints() as $p) {
                $row = $base + [
                    'point_name' => $p->getName(),
                    'longitude' => $p->getLongitude(),
                    'latitude' => $p->getLatitude(),
                    'elevation' => $p->getElevation(),
                    'vertical_datum' => $p->getVerticalDatum(),
                    'observed_at' => $p->getObservedAt()?->format('Y-m-d\TH:i:sP'),
                    'observed_end' => $p->getObservedEnd()?->format('Y-m-d\TH:i:sP'),
                    'crs' => $p->getSrcCrs(),
                    'x' => $p->getSrcX(),
                    'y' => $p->getSrcY(),
                ];
                foreach ($fields as $f) {
                    if (($f['scope'] ?? 'dataset') === 'point') {
                        $v = $p->getProperties()[$f['key']] ?? null;
                        $row[$f['key']] = \is_array($v) ? implode(' ; ', $v) : (\is_bool($v) ? ($v ? 'oui' : 'non') : $v);
                    }
                }
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /** @return array{0: Dataset, 1: string, 2: ?string} */
    private function target(Site $site, array $g, User $user): array
    {
        if ($g['datasetId']) {
            $d = $this->datasets->findOneInSite($site, $g['datasetId']);
            if (!$d) {
                return [new Dataset($site, $user), 'update', "Jeu {$g['datasetId']} introuvable sur ce site"];
            }
            if (!$this->perm->canEdit($d)) {
                return [$d, 'update', "Vous n'avez pas le droit de modifier le jeu {$g['datasetId']}"];
            }
            return [$d, 'update', null];
        }
        return [new Dataset($site, $user), 'create', null];
    }

    private function withDefaults(array $payload, Site $site): array
    {
        $payload['accessRight'] = $payload['accessRight'] ?: $site->setting('defaultAccessRight');
        $payload['license'] = $payload['license'] ?: $site->setting('defaultLicense');
        return $payload;
    }

    /** points[3].latitude → "ligne 5 : …" */
    private function mapErrors(array $errors, array $rows): array
    {
        $out = [];
        foreach ($errors as $k => $msg) {
            if (preg_match('/^points\[(\d+)\]\.(.+)$/', (string) $k, $m) && isset($rows[(int) $m[1]])) {
                $out['ligne ' . ($rows[(int) $m[1]] + 2) . ' · ' . $m[2]] = $msg;
            } else {
                $out[(string) $k] = $msg;
            }
        }
        return $out;
    }

    private function levelOf(string $key, array $fields): string
    {
        foreach (self::CORE as $c) {
            if ($c['key'] === $key) {
                return $c['level'];
            }
        }
        return isset($fields[$key]) ? ($fields[$key]['scope'] ?? 'dataset') : 'ignored';
    }

    private function parseAuthors(string $s): array
    {
        $out = [];
        foreach (preg_split('/\s*;\s*/', $s) as $a) {
            if ($a === '') {
                continue;
            }
            if (preg_match('/^(.*?)\s*\((.*)\)\s*$/', $a, $m)) {
                $out[] = ['name' => $m[1], 'affiliation' => $m[2]];
            } else {
                $out[] = ['name' => $a];
            }
        }
        return $out;
    }
}
