<?php

namespace App\Service;

use App\Entity\Dataset;
use App\Entity\SamplingPoint;
use App\Exception\ApiException;

/**
 * Applique un payload JSON (formulaire ou ligne d'import regroupée) à un Dataset,
 * avec validation complète. Lève ApiException 422 avec le détail des erreurs.
 *
 * Mode $lenient (reprise de données) : rien n'est rejeté et rien n'est deviné.
 *  - une valeur absente reste vide (pas de licence / droit d'accès / mots-clés par défaut) ;
 *  - une valeur invalide est conservée telle quelle chaque fois que c'est possible ;
 *    elle n'est vidée que si le stockage l'impose (date illisible, nombre non numérique,
 *    licence plus longue que la colonne, droit d'accès hors énumération, point sans coordonnées) ;
 *  - tous les problèmes sont renvoyés, champ par champ, avec la valeur d'origine :
 *      array<string, array{kind: 'missing'|'invalid'|'adjusted'|'dropped', message: string, original: ?string}>
 *    (clés : "title", "license", "properties.xxx", "points[3].longitude", "points[3]"…)
 *  - l'appelant décide du statut ; un résumé est écrit dans reviewComment (STORE_REVIEW_COMMENT).
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

    /** Mettre à false si reviewComment est affiché publiquement : le rapport CSV contient tout. */
    private const STORE_REVIEW_COMMENT = true;

    public function __construct(private FieldSchema $schema) {}

    /** @return array<string,string> erreurs (vide si OK) — n'altère pas l'entité (mode lecture seule) */
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
     * @return array en mode $lenient : problèmes rencontrés, voir ci-dessus (vide si tout est renseigné et valide) ;
     *               sinon toujours vide (une ApiException est levée en cas d'erreur)
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
        $errors = []; // clé => message : bloquant hors reprise
        $issues = []; // clé => {kind, message, original} : uniquement en reprise

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

        // --- Titre ------------------------------------------------------------------------
        $title = trim((string) ($p['title'] ?? ''));
        if ($title === '') {
            $fail('title', 'Le titre est obligatoire', null, 'missing');
        } elseif (mb_strlen($title) > 500) {
            $fail('title', 'Titre trop long (500 caractères max.)', $title);
            if ($lenient) {
                $title = mb_substr($title, 0, 500); // limite de colonne : le titre complet reste dans le rapport
            }
        }

        $description = trim((string) ($p['description'] ?? '')) ?: null;
        if ($description === null) {
            $note('description', 'missing', 'Description absente dans la source');
        }

        // --- Mots-clés --------------------------------------------------------------------
        $keywords = $p['keywords'] ?? [];
        if (\is_string($keywords)) {
            $keywords = preg_split('/\s*[;,]\s*/', $keywords);
        }
        $keywords = array_values(array_unique(array_filter(array_map(
            static fn ($k) => mb_substr(trim((string) $k), 0, 120), (array) $keywords
        ), static fn ($k) => $k !== '')));
        if (!$keywords) {
            if ($lenient) {
                $note('keywords', 'missing', 'Aucun mot-clé dans la source');
            } elseif (!empty($settings['requireKeywords'])) {
                $errors['keywords'] = 'Au moins un mot-clé est requis';
            }
        }

        // --- Auteurs ----------------------------------------------------------------------
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
                    // la valeur est conservée telle quelle (aucune contrainte de stockage)
                    $fail("authors[$i].orcid", "ORCID invalide (0000-0000-0000-0000) pour l'auteur « $name »", $orcid);
                }
            }
            $authors[] = array_filter([
                'name' => mb_substr($name, 0, 255),
                'affiliation' => trim((string) ($a['affiliation'] ?? '')) ?: null,
                'orcid' => $orcid,
            ], static fn ($v) => $v !== null);
        }
        if (!$authors) {
            $note('authors', 'missing', 'Aucun auteur dans la source');
        }

        // --- Droit d'accès ----------------------------------------------------------------
        $access = $p['accessRight'] ?? null;
        if ($access === null) {
            if ($lenient) {
                // Colonne obligatoire : valeur technique la plus protectrice, signalée dans le rapport.
                // Si accessRight est nullable dans l'entité, remplacer par null.
                $access = Dataset::ACCESS_RESTRICTED;
                $note('accessRight', 'adjusted', "Droit d'accès absent dans la source : « restricted » appliqué (colonne obligatoire)");
            } else {
                $access = $settings['defaultAccessRight'] ?? Dataset::ACCESS_OPEN;
            }
        }
        if (!\in_array($access, [Dataset::ACCESS_OPEN, Dataset::ACCESS_RESTRICTED], true)) {
            $fail('accessRight', 'Valeur attendue : open ou restricted', $access);
            if ($lenient) {
                $access = Dataset::ACCESS_RESTRICTED; // énumération : la valeur d'origine n'est pas stockable
            }
        }

        // --- Licence ----------------------------------------------------------------------
        $license = trim((string) ($p['license'] ?? ($lenient ? '' : ($settings['defaultLicense'] ?? '')))) ?: null;
        if ($license === null) {
            $note('license', 'missing', 'Licence absente dans la source (laissée vide)');
        } elseif (mb_strlen($license) > 40) {
            $fail('license', 'Licence trop longue (40 caractères max.)', $license);
            if ($lenient) {
                $license = null; // limite de colonne : la valeur d'origine reste dans le rapport
            }
        }

        // --- Langue -----------------------------------------------------------------------
        $language = trim((string) ($p['language'] ?? '')) ?: null;
        if ($language === null) {
            $note('language', 'missing', 'Langue absente dans la source');
        } elseif (!preg_match('/^[a-z]{2,3}(-[A-Za-z]{2})?$/', $language)) {
            $fail('language', 'Code langue ISO attendu (fr, en…)', $language);
            if ($lenient) {
                $language = null; // colonne courte : la valeur d'origine reste dans le rapport
            }
        }

        // --- Période ----------------------------------------------------------------------
        $tStart = $tEnd = null;
        try {
            $tStart = DateParser::parse($p['temporalStart'] ?? null);
        } catch (\InvalidArgumentException $e) {
            $fail('temporalStart', $e->getMessage(), $p['temporalStart'] ?? null); // date illisible : non stockable
        }
        try {
            $tEnd = DateParser::parse($p['temporalEnd'] ?? null, true);
        } catch (\InvalidArgumentException $e) {
            $fail('temporalEnd', $e->getMessage(), $p['temporalEnd'] ?? null);
        }
        if ($tStart && $tEnd && $tEnd < $tStart) {
            // en reprise, les deux dates sont conservées telles quelles
            $fail('temporalEnd', 'La fin de période précède le début', $p['temporalEnd'] ?? null);
        }

        // --- DOI / ORDaR ------------------------------------------------------------------
        $doi = trim((string) ($p['doi'] ?? '')) ?: null;
        if ($doi !== null) {
            $doi = preg_replace('#^https?://(dx\.)?doi\.org/#i', '', $doi);
            if (!preg_match('#^10\.\d{4,9}/\S+$#', $doi)) {
                $fail('doi', 'DOI invalide (10.xxxx/…)', $doi); // valeur conservée
            }
        }
        $ordarUrl = trim((string) ($p['ordarUrl'] ?? '')) ?: null;
        if ($ordarUrl !== null && !preg_match('#^https?://#i', $ordarUrl)) {
            $fail('ordarUrl', 'URL http(s) attendue', $ordarUrl); // valeur conservée
        }

        // --- Champs spécifiques du jeu ----------------------------------------------------
        [$props, $propErrors, $propOrigins] = $this->schema->validateValues(
            $fields, (array) ($p['properties'] ?? []), 'dataset', 'properties', $lenient
        );
        foreach ($propErrors as $k => $m) {
            $fail($k, $m, $propOrigins[$k] ?? null, isset($propOrigins[$k]) ? 'invalid' : 'missing');
        }

        // --- Points de prélèvement --------------------------------------------------------
        $pointsIn = array_values((array) ($p['points'] ?? []));
        if (!$pointsIn) {
            $fail('points', 'Au moins un point géolocalisé est requis', null, 'missing');
        }
        if (\count($pointsIn) > self::MAX_POINTS) {
            $n = \count($pointsIn);
            $fail(
                'points',
                $lenient
                    ? sprintf('Trop de points : %d dans la source, seuls les %d premiers sont repris', $n, self::MAX_POINTS)
                    : sprintf('Trop de points (%d max.)', self::MAX_POINTS),
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
                $fail("$pfx.longitude", 'Longitude WGS84 invalide (-180 à 180)', $rawLon, $kindOf($rawLon));
            }
            if ($latBad) {
                $fail("$pfx.latitude", 'Latitude WGS84 invalide (-90 à 90)', $rawLat, $kindOf($rawLat));
            }
            $elevRaw = $pi['elevation'] ?? null;
            $elev = $this->num($elevRaw);
            if ($elevRaw !== null && $elevRaw !== '' && ($elev === null || $elev < -12000 || $elev > 9000)) {
                // Valeur numérique hors plage : conservée. Valeur non numérique : $elev est déjà null
                // (colonne numérique), la valeur d'origine reste dans le rapport.
                $fail("$pfx.elevation", 'Altitude invalide (m, entre -12000 et 9000)', $elevRaw);
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
                $fail("$pfx.observedEnd", 'La fin précède le début', $pi['observedEnd'] ?? null); // valeurs conservées
            }
            [$pprops, $pErr, $pOrigins] = $this->schema->validateValues(
                $fields, (array) ($pi['properties'] ?? []), 'point', "$pfx.properties", $lenient
            );
            foreach ($pErr as $k => $m) {
                $fail($k, $m, $pOrigins[$k] ?? null, isset($pOrigins[$k]) ? 'invalid' : 'missing');
            }
            if ($lonBad || $latBad) {
                // Un point sans coordonnées WGS84 valides ne peut pas être enregistré : on garde
                // toutes ses valeurs d'origine dans le rapport.
                $note($pfx, 'dropped', 'Point non enregistré : coordonnées WGS84 absentes ou invalides', json_encode(array_filter([
                    'nom' => $pi['name'] ?? null,
                    'longitude' => $rawLon,
                    'latitude' => $rawLat,
                    'systeme' => $pi['_crs'] ?? null,
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

        if ($lenient && $issues && self::STORE_REVIEW_COMMENT) {
            $lines = [];
            foreach (array_slice($issues, 0, 30, true) as $k => $i) {
                $line = "- $k : {$i['message']}";
                if ($i['original'] !== null && $i['original'] !== '') {
                    $line .= ' — valeur d\'origine : « ' . mb_substr($i['original'], 0, 200) . ' »';
                }
                $lines[] = $line;
            }
            if (\count($issues) > 30) {
                $lines[] = '- … ' . (\count($issues) - 30) . ' autre(s) (voir le rapport CSV de la reprise)';
            }
            $d->setReviewComment(
                "Reprise geo-samples : valeurs absentes ou invalides dans la source (laissées vides ou conservées telles quelles) :\n"
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