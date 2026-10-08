<?php

namespace App\Service;

use App\Entity\Dataset;
use App\Entity\SamplingPoint;
use App\Exception\ApiException;

/**
 * Applique un payload JSON (formulaire ou ligne d'import regroupée) à un Dataset,
 * avec validation complète. Lève ApiException 422 avec le détail des erreurs.
 *
 * Payload :
 * {title, description, keywords[], authors[{name, affiliation, orcid}], license, accessRight,
 *  language, temporalStart, temporalEnd, doi, ordarUrl, properties{},
 *  points[{id?, name, longitude, latitude, elevation, verticalDatum, observedAt, observedEnd,
 *          srcCrs, srcX, srcY, properties{}}]}
 */
final class DatasetWriter
{
    public const MAX_POINTS = 5000;

    public function __construct(private FieldSchema $schema) {}

    /** @return array<string,string> erreurs (vide si OK) — n'altère pas l'entité (mode lecture seule) */
    public function validate(Dataset $dataset, array $payload): array
    {
        try {
            $this->doApply($dataset, $payload, false);
        } catch (ApiException $e) {
            return $e->getDetails() ?: ['_' => $e->getMessage()];
        }
        return [];
    }

    public function apply(Dataset $dataset, array $payload): void
    {
        $this->doApply($dataset, $payload, true);
    }

    private function doApply(Dataset $d, array $p, bool $write): void
    {
        $site = $d->getSite();
        $settings = $site->getSettings();
        $fields = $site->getFields();
        $errors = [];

        $title = trim((string) ($p['title'] ?? ''));
        if ($title === '') {
            $errors['title'] = 'Le titre est obligatoire';
        } elseif (mb_strlen($title) > 500) {
            $errors['title'] = 'Titre trop long (500 caractères max.)';
        }

        $description = trim((string) ($p['description'] ?? '')) ?: null;

        $keywords = $p['keywords'] ?? [];
        if (\is_string($keywords)) {
            $keywords = preg_split('/\s*[;,]\s*/', $keywords);
        }
        $keywords = array_values(array_unique(array_filter(array_map(
            static fn ($k) => mb_substr(trim((string) $k), 0, 120), (array) $keywords
        ), static fn ($k) => $k !== '')));
        if (!$keywords && !empty($settings['requireKeywords'])) {
            $errors['keywords'] = 'Au moins un mot-clé est requis';
        }

        $authors = [];
        foreach (array_values((array) ($p['authors'] ?? [])) as $i => $a) {
            if (\is_string($a)) {
                $a = ['name' => $a];
            }
            $name = trim((string) ($a['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $orcid = trim((string) ($a['orcid'] ?? '')) ?: null;
            if ($orcid !== null) {
                $orcid = preg_replace('#^https?://orcid\.org/#', '', $orcid);
                if (!preg_match('/^\d{4}-\d{4}-\d{4}-\d{3}[\dX]$/', $orcid)) {
                    $errors["authors[$i].orcid"] = 'ORCID invalide (0000-0000-0000-0000)';
                }
            }
            $authors[] = array_filter([
                'name' => mb_substr($name, 0, 255),
                'affiliation' => trim((string) ($a['affiliation'] ?? '')) ?: null,
                'orcid' => $orcid,
            ], static fn ($v) => $v !== null);
        }

        $access = $p['accessRight'] ?? $settings['defaultAccessRight'] ?? Dataset::ACCESS_OPEN;
        if (!\in_array($access, [Dataset::ACCESS_OPEN, Dataset::ACCESS_RESTRICTED], true)) {
            $errors['accessRight'] = 'Valeur attendue : open ou restricted';
        }
        $license = trim((string) ($p['license'] ?? $settings['defaultLicense'] ?? '')) ?: null;
        if ($license !== null && mb_strlen($license) > 40) {
            $errors['license'] = 'Licence trop longue';
        }
        $language = trim((string) ($p['language'] ?? '')) ?: null;
        if ($language !== null && !preg_match('/^[a-z]{2,3}(-[A-Za-z]{2})?$/', $language)) {
            $errors['language'] = 'Code langue ISO attendu (fr, en…)';
        }

        $tStart = $tEnd = null;
        try {
            $tStart = DateParser::parse($p['temporalStart'] ?? null);
        } catch (\InvalidArgumentException $e) {
            $errors['temporalStart'] = $e->getMessage();
        }
        try {
            $tEnd = DateParser::parse($p['temporalEnd'] ?? null, true);
        } catch (\InvalidArgumentException $e) {
            $errors['temporalEnd'] = $e->getMessage();
        }
        if ($tStart && $tEnd && $tEnd < $tStart) {
            $errors['temporalEnd'] = 'La fin de période précède le début';
        }

        $doi = trim((string) ($p['doi'] ?? '')) ?: null;
        if ($doi !== null) {
            $doi = preg_replace('#^https?://(dx\.)?doi\.org/#i', '', $doi);
            if (!preg_match('#^10\.\d{4,9}/\S+$#', $doi)) {
                $errors['doi'] = 'DOI invalide (10.xxxx/…)';
            }
        }
        $ordarUrl = trim((string) ($p['ordarUrl'] ?? '')) ?: null;
        if ($ordarUrl !== null && !preg_match('#^https?://#i', $ordarUrl)) {
            $errors['ordarUrl'] = 'URL http(s) attendue';
        }

        [$props, $propErrors] = $this->schema->validateValues($fields, (array) ($p['properties'] ?? []), 'dataset');
        $errors += $propErrors;

        // --- Points de prélèvement --------------------------------------------------------
        $pointsIn = array_values((array) ($p['points'] ?? []));
        if (!$pointsIn) {
            $errors['points'] = 'Au moins un point géolocalisé est requis';
        }
        if (\count($pointsIn) > self::MAX_POINTS) {
            $errors['points'] = sprintf('Trop de points (%d max.)', self::MAX_POINTS);
            $pointsIn = [];
        }
        $existing = [];
        foreach ($d->getPoints() as $pt) {
            if ($pt->getId()) {
                $existing[$pt->getId()] = $pt;
            }
        }
        $defaultDatum = $settings['verticalDatum'] ?? null;
        $pointsOut = [];
        foreach ($pointsIn as $i => $pi) {
            $pfx = "points[$i]";
            $lon = $this->num($pi['longitude'] ?? null);
            $lat = $this->num($pi['latitude'] ?? null);
            if ($lon === null || $lon < -180 || $lon > 180) {
                $errors["$pfx.longitude"] = 'Longitude WGS84 invalide (-180 à 180)';
            }
            if ($lat === null || $lat < -90 || $lat > 90) {
                $errors["$pfx.latitude"] = 'Latitude WGS84 invalide (-90 à 90)';
            }
            $elevRaw = $pi['elevation'] ?? null;
            $elev = $this->num($elevRaw);
            if ($elevRaw !== null && $elevRaw !== '' && ($elev === null || $elev < -12000 || $elev > 9000)) {
                $errors["$pfx.elevation"] = 'Altitude invalide (m, entre -12000 et 9000)';
            }
            $obs = $obsEnd = null;
            try {
                $obs = DateParser::parse($pi['observedAt'] ?? null);
            } catch (\InvalidArgumentException $e) {
                $errors["$pfx.observedAt"] = $e->getMessage();
            }
            try {
                $obsEnd = DateParser::parse($pi['observedEnd'] ?? null, true);
            } catch (\InvalidArgumentException $e) {
                $errors["$pfx.observedEnd"] = $e->getMessage();
            }
            if ($obs && $obsEnd && $obsEnd < $obs) {
                $errors["$pfx.observedEnd"] = 'La fin précède le début';
            }
            [$pprops, $pErr] = $this->schema->validateValues($fields, (array) ($pi['properties'] ?? []), 'point', "$pfx.properties");
            $errors += $pErr;
            if ($lon === null || $lat === null) {
                continue;
            }
            $pointsOut[] = [
                'id' => isset($pi['id']) ? (int) $pi['id'] : null,
                'lon' => $lon, 'lat' => $lat, 'elev' => $elev,
                'name' => mb_substr(trim((string) ($pi['name'] ?? '')), 0, 255) ?: null,
                'datum' => trim((string) ($pi['verticalDatum'] ?? '')) ?: ($elev !== null ? $defaultDatum : null),
                'obs' => $obs, 'obsEnd' => $obsEnd,
                'srcCrs' => trim((string) ($pi['srcCrs'] ?? '')) ?: null,
                'srcX' => $this->num($pi['srcX'] ?? null), 'srcY' => $this->num($pi['srcY'] ?? null),
                'props' => $pprops, 'pos' => $i,
            ];
        }

        if ($errors) {
            throw ApiException::validation($errors);
        }
        if (!$write) {
            return;
        }

        $d->setTitle($title)->setDescription($description)->setKeywords($keywords)->setAuthors($authors)
          ->setAccessRight($access)->setLicense($license)->setLanguage($language)
          ->setTemporalStart($tStart)->setTemporalEnd($tEnd)
          ->setDoi($doi)->setOrdarUrl($ordarUrl)->setProperties($props);

        $points = [];
        foreach ($pointsOut as $x) {
            $pt = ($x['id'] && isset($existing[$x['id']])) ? $existing[$x['id']] : new SamplingPoint($x['lon'], $x['lat']);
            $pt->setLongitude($x['lon'])->setLatitude($x['lat'])->setName($x['name'])
               ->setElevation($x['elev'])->setVerticalDatum($x['datum'])
               ->setObservedAt($x['obs'])->setObservedEnd($x['obsEnd'])
               ->setSrcCrs($x['srcCrs'])->setSrcX($x['srcX'])->setSrcY($x['srcY'])
               ->setProperties($x['props'])->setPosition($x['pos']);
            $points[] = $pt;
        }

        // Si aucune période n'est donnée au niveau du jeu, on la déduit des points.
        if (!$tStart) {
            $dates = [];
            foreach ($points as $pt) {
                if ($pt->getObservedAt()) { $dates[] = $pt->getObservedAt(); }
                if ($pt->getObservedEnd()) { $dates[] = $pt->getObservedEnd(); }
            }
            if ($dates) {
                $d->setTemporalStart(min($dates));
                $max = max($dates);
                $d->setTemporalEnd($max > min($dates) ? $max : null);
            }
        }

        $keep = array_map('spl_object_id', $points);
        foreach ($d->getPoints()->toArray() as $old) {
            if (!\in_array(spl_object_id($old), $keep, true)) {
                $d->removePoint($old);
            }
        }
        foreach ($points as $pt) {
            $d->addPoint($pt);
        }
    }

    private function num(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (\is_string($v)) {
            $v = str_replace([',', ' ', "\u{00A0}"], ['.', '', ''], trim($v));
        }
        return is_numeric($v) ? (float) $v : null;
    }
}
