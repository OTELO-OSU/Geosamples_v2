<?php

namespace App\Service;

use App\Entity\Dataset;
use App\Entity\SamplingPoint;
use App\Exception\ApiException;

/**
 * Apply a JSON payload (form or grouped import row) to a Dataset, validating it fully.
 * Throws ApiException 422 with error details.
 *
 * In $lenient mode (data migration), nothing is rejected or guessed.
 *  - a missing value remains empty (no default license / access right / keywords);
 *  - an invalid value is preserved as-is whenever possible;
 *    a value is cleared only when storage requires it (unreadable date, non-numeric number,
 *    license exceeds column length, access right is outside the enum, point has no coordinates);
 *  - all issues are returned, field by field, with the original value:
 *      array<string, array{kind: 'missing'|'invalid'|'adjusted'|'dropped', message: string, original: ?string}>
 *    (keys: "title", "license", "properties.xxx", "points[3].longitude", "points[3]"...)
 *  - the caller decides the status; a summary is written to reviewComment (STORE_REVIEW_COMMENT).
 *
 * Payload:
 * {title, description, keywords[], authors[{name, affiliation, orcid}], license, accessRight,
 *  language, temporalStart, temporalEnd, doi, ordarUrl, properties{},
 *  points[{id?, name, longitude, latitude, elevation, verticalDatum, observedAt, observedEnd,
 *          srcCrs, srcX, srcY, properties{}}]}
 */
final class DatasetWriter
{
    public const MAX_POINTS = 5000;

    /** Set to false if reviewComment is publicly displayed: the CSV report contains everything. */
    private const STORE_REVIEW_COMMENT = true;

    public function __construct(private FieldSchema $schema) {}

    /** @return array<string,string> errors (empty if OK) — does not modify the entity (read-only mode) */
    public function validate(Dataset $dataset, array $payload): array
    {
        try {
            $this->doApply($dataset, $payload, false, false);
        } catch (ApiException $e) {
            return $e->getDetails() ?: ['_' => $e->getMessage()];
        }
        return [];
    }

    /**
     * @return array in $lenient mode: encountered issues, see above (empty if all values are present and valid);
     *               otherwise always empty (an ApiException is thrown on error)
     */
    public function apply(Dataset $dataset, array $payload, bool $lenient = false): array
    {
        return $this->doApply($dataset, $payload, true, $lenient);
    }

    private static function raw(mixed $v): string
    {
        return \is_scalar($v) || $v === null ? (string) $v : (string) json_encode($v, JSON_UNESCAPED_UNICODE);
    }

    private function doApply(Dataset $d, array $p, bool $write, bool $lenient): array
    {
        $site = $d->getSite();
        $settings = $site->getSettings();
        $fields = $site->getFields();
        $errors = []; // key => message: blocks import outside migration mode
        $issues = []; // key => {kind, message, original}: migration mode only

        $note = function (string $key, string $kind, string $message, mixed $original = null) use (&$issues, $lenient): void {
            if ($lenient) {
                $issues[$key] = ['kind' => $kind, 'message' => $message, 'original' => $original === null ? null : self::raw($original)];
            }
        };
        $fail = function (string $key, string $message, mixed $original = null, string $kind = 'invalid') use (&$errors, $note): void {
            $errors[$key] = $message;
            $note($key, $kind, $message, $original);
        };
        $kindOf = static fn (mixed $v): string => ($v === null || $v === '') ? 'missing' : 'invalid';

        // --- Title -----------------------------------------------------------------------
        $title = trim((string) ($p['title'] ?? ''));
        if ($title === '') {
            $fail('title', 'Title is required', null, 'missing');
        } elseif (mb_strlen($title) > 500) {
            $fail('title', 'Title is too long (max. 500 characters)', $title);
            if ($lenient) {
                $title = mb_substr($title, 0, 500); // Column limit: the full title remains in the report.
            }
        }

        $description = trim((string) ($p['description'] ?? '')) ?: null;
        if ($description === null) {
            $note('description', 'missing', 'Description missing from source');
        }

        // --- Keywords --------------------------------------------------------------------
        $keywords = $p['keywords'] ?? [];
        if (\is_string($keywords)) {
            $keywords = preg_split('/\s*[;,]\s*/', $keywords);
        }
        $keywords = array_values(array_unique(array_filter(array_map(
            static fn ($k) => mb_substr(trim((string) $k), 0, 120), (array) $keywords
        ), static fn ($k) => $k !== '')));
        if (!$keywords) {
            if ($lenient) {
                $note('keywords', 'missing', 'No keywords in the source');
            } elseif (!empty($settings['requireKeywords'])) {
                $errors['keywords'] = 'At least one keyword is required';
            }
        }

        // --- Authors ---------------------------------------------------------------------
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
                    // Keep the value as-is (there is no storage constraint).
                    $fail("authors[$i].orcid", "Invalid ORCID (0000-0000-0000-0000) for author “$name”", $orcid);
                }
            }
            $authors[] = array_filter([
                'name' => mb_substr($name, 0, 255),
                'affiliation' => trim((string) ($a['affiliation'] ?? '')) ?: null,
                'orcid' => $orcid,
            ], static fn ($v) => $v !== null);
        }
        if (!$authors) {
            $note('authors', 'missing', 'No authors in the source');
        }

        // --- Access right ----------------------------------------------------------------
        $access = $p['accessRight'] ?? null;
        if ($access === null) {
            if ($lenient) {
                // Required column: use the most restrictive technical value and report it.
                // If accessRight is nullable in the entity, use null instead.
                $access = Dataset::ACCESS_RESTRICTED;
                $note('accessRight', 'adjusted', "Access right missing from source: “restricted” applied (required column)");
            } else {
                $access = $settings['defaultAccessRight'] ?? Dataset::ACCESS_OPEN;
            }
        }
        if (!\in_array($access, [Dataset::ACCESS_OPEN, Dataset::ACCESS_RESTRICTED], true)) {
            $fail('accessRight', 'Expected value: open or restricted', $access);
            if ($lenient) {
                $access = Dataset::ACCESS_RESTRICTED; // Enum: the original value cannot be stored.
            }
        }

        // --- License ---------------------------------------------------------------------
        $license = trim((string) ($p['license'] ?? ($lenient ? '' : ($settings['defaultLicense'] ?? '')))) ?: null;
        if ($license === null) {
            $note('license', 'missing', 'License missing from source (left blank)');
        } elseif (mb_strlen($license) > 40) {
            $fail('license', 'License is too long (max. 40 characters)', $license);
            if ($lenient) {
                $license = null; // Column limit: the original value remains in the report.
            }
        }

        // --- Language --------------------------------------------------------------------
        $language = trim((string) ($p['language'] ?? '')) ?: null;
        if ($language === null) {
            $note('language', 'missing', 'Language missing from source');
        } elseif (!preg_match('/^[a-z]{2,3}(-[A-Za-z]{2})?$/', $language)) {
            $fail('language', 'Expected ISO language code (fr, en...)', $language);
            if ($lenient) {
                $language = null; // Short column: the original value remains in the report.
            }
        }

        // --- Period ----------------------------------------------------------------------
        $tStart = $tEnd = null;
        try {
            $tStart = DateParser::parse($p['temporalStart'] ?? null);
        } catch (\InvalidArgumentException $e) {
            $fail('temporalStart', $e->getMessage(), $p['temporalStart'] ?? null); // Unreadable date: cannot be stored.
        }
        try {
            $tEnd = DateParser::parse($p['temporalEnd'] ?? null, true);
        } catch (\InvalidArgumentException $e) {
            $fail('temporalEnd', $e->getMessage(), $p['temporalEnd'] ?? null);
        }
        if ($tStart && $tEnd && $tEnd < $tStart) {
            // In migration mode, keep both dates as-is.
            $fail('temporalEnd', 'Period end is before period start', $p['temporalEnd'] ?? null);
        }

        // --- DOI / ORDaR -----------------------------------------------------------------
        $doi = trim((string) ($p['doi'] ?? '')) ?: null;
        if ($doi !== null) {
            $doi = preg_replace('#^https?://(dx\.)?doi\.org/#i', '', $doi);
            if (!preg_match('#^10\.\d{4,9}/\S+$#', $doi)) {
                $fail('doi', 'Invalid DOI (10.xxxx/...)', $doi); // Value is retained.
            }
        }
        $ordarUrl = trim((string) ($p['ordarUrl'] ?? '')) ?: null;
        if ($ordarUrl !== null && !preg_match('#^https?://#i', $ordarUrl)) {
            $fail('ordarUrl', 'Expected http(s) URL', $ordarUrl); // Value is retained.
        }

        // --- Dataset-specific fields -----------------------------------------------------
        [$props, $propErrors, $propOrigins] = $this->schema->validateValues(
            $fields, (array) ($p['properties'] ?? []), 'dataset', 'properties', $lenient
        );
        foreach ($propErrors as $k => $m) {
            $fail($k, $m, $propOrigins[$k] ?? null, isset($propOrigins[$k]) ? 'invalid' : 'missing');
        }

        // --- Sampling points -------------------------------------------------------------
        $pointsIn = array_values((array) ($p['points'] ?? []));
        if (!$pointsIn) {
            $fail('points', 'At least one geolocated point is required', null, 'missing');
        }
        if (\count($pointsIn) > self::MAX_POINTS) {
            $n = \count($pointsIn);
            $fail(
                'points',
                $lenient
                    ? sprintf('Too many points: %d in source, only the first %d will be imported', $n, self::MAX_POINTS)
                    : sprintf('Too many points (%d max.)', self::MAX_POINTS),
                "$n points"
            );
            $pointsIn = $lenient ? \array_slice($pointsIn, 0, self::MAX_POINTS) : [];
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
            $pi = (array) $pi;
            $pfx = "points[$i]";
            $rawLon = $pi['longitude'] ?? null;
            $rawLat = $pi['latitude'] ?? null;
            $lon = $this->num($rawLon);
            $lat = $this->num($rawLat);
            $lonBad = $lon === null || $lon < -180 || $lon > 180;
            $latBad = $lat === null || $lat < -90 || $lat > 90;
            if ($lonBad) {
                $fail("$pfx.longitude", 'Invalid WGS84 longitude (-180 to 180)', $rawLon, $kindOf($rawLon));
            }
            if ($latBad) {
                $fail("$pfx.latitude", 'Invalid WGS84 latitude (-90 to 90)', $rawLat, $kindOf($rawLat));
            }
            $elevRaw = $pi['elevation'] ?? null;
            $elev = $this->num($elevRaw);
            if ($elevRaw !== null && $elevRaw !== '' && ($elev === null || $elev < -12000 || $elev > 9000)) {
                // Out-of-range numeric value: retained. Non-numeric value: $elev is already null
                // (numeric column), and the original value remains in the report.
                $fail("$pfx.elevation", 'Invalid elevation (m, between -12000 and 9000)', $elevRaw);
            }
            $obs = $obsEnd = null;
            try {
                $obs = DateParser::parse($pi['observedAt'] ?? null);
            } catch (\InvalidArgumentException $e) {
                $fail("$pfx.observedAt", $e->getMessage(), $pi['observedAt'] ?? null);
            }
            try {
                $obsEnd = DateParser::parse($pi['observedEnd'] ?? null, true);
            } catch (\InvalidArgumentException $e) {
                $fail("$pfx.observedEnd", $e->getMessage(), $pi['observedEnd'] ?? null);
            }
            if ($obs && $obsEnd && $obsEnd < $obs) {
                $fail("$pfx.observedEnd", 'End date is before start date', $pi['observedEnd'] ?? null); // Preserve input values.
            }
            [$pprops, $pErr, $pOrigins] = $this->schema->validateValues(
                $fields, (array) ($pi['properties'] ?? []), 'point', "$pfx.properties", $lenient
            );
            foreach ($pErr as $k => $m) {
                $fail($k, $m, $pOrigins[$k] ?? null, isset($pOrigins[$k]) ? 'invalid' : 'missing');
            }
            if ($lonBad || $latBad) {
                // A point without valid WGS84 coordinates cannot be saved; retain all
                // its original values in the report.
                $note($pfx, 'dropped', 'Point not saved: WGS84 coordinates are missing or invalid', json_encode(array_filter([
                    'name' => $pi['name'] ?? null,
                    'longitude' => $rawLon,
                    'latitude' => $rawLat,
                    'coordinate_system' => $pi['_crs'] ?? null,
                    'srcCrs' => $pi['srcCrs'] ?? null,
                    'srcX' => $pi['srcX'] ?? null,
                    'srcY' => $pi['srcY'] ?? null,
                ], static fn ($v) => $v !== null && $v !== ''), JSON_UNESCAPED_UNICODE));
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

        if ($errors && !$lenient) {
            throw ApiException::validation($errors);
        }
        if (!$write) {
            return $lenient ? $issues : [];
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

        // If no dataset-level period is provided, derive it from the points.
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

        if ($lenient && $issues && self::STORE_REVIEW_COMMENT) {
            $lines = [];
            foreach (array_slice($issues, 0, 30, true) as $k => $i) {
                $line = "- $k: {$i['message']}";
                if ($i['original'] !== null && $i['original'] !== '') {
                    $line .= ' — original value: “' . mb_substr($i['original'], 0, 200) . '”';
                }
                $lines[] = $line;
            }
            if (\count($issues) > 30) {
                $lines[] = '- ... ' . (\count($issues) - 30) . ' more (see the migration CSV report)';
            }
            $d->setReviewComment(
                "geo-samples migration: values missing or invalid in source (left blank or preserved as-is):\n"
                . implode("\n", $lines)
            );
        }

        return $lenient ? $issues : [];
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