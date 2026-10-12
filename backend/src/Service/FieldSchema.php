<?php

namespace App\Service;

use App\Exception\ApiException;

/**
 * Schéma des champs spécifiques d'un site : validation de la définition
 * (écran de configuration) et contrôle/normalisation des valeurs saisies.
 */
final class FieldSchema
{
    public const TYPES = ['text', 'textarea', 'number', 'integer', 'date', 'select', 'multiselect', 'boolean', 'url'];
    public const FILTERS = [null, 'select', 'range', 'text'];
    public const SCOPES = ['dataset', 'point'];

    /** Clés réservées aux colonnes communes (évite les collisions dans l'import) */
    public const RESERVED = [
        'dataset_id', 'dataset_ref', 'title', 'description', 'keywords', 'authors', 'license', 'access_right',
        'language', 'temporal_start', 'temporal_end', 'doi', 'point_name', 'longitude', 'latitude', 'elevation',
        'vertical_datum', 'observed_at', 'observed_end', 'crs', 'x', 'y', 'q', 'from', 'to', 'bbox', 'status',
    ];

    /** Valide et normalise la liste de définitions de champs. */
    public function normalizeDefinitions(array $fields): array
    {
        $out = [];
        $errors = [];
        $seen = [];
        foreach (array_values($fields) as $i => $f) {
            if (!\is_array($f)) {
                $errors["fields[$i]"] = 'Définition invalide';
                continue;
            }
            $key = trim((string) ($f['key'] ?? ''));
            if (!preg_match('/^[a-z][a-z0-9_]{0,62}$/', $key)) {
                $errors["fields[$i].key"] = 'Clé invalide (minuscules, chiffres, _ ; commence par une lettre)';
                continue;
            }
            if (\in_array($key, self::RESERVED, true)) {
                $errors["fields[$i].key"] = "La clé « $key » est réservée";
                continue;
            }
            if (isset($seen[$key])) {
                $errors["fields[$i].key"] = "Clé « $key » en double";
                continue;
            }
            $seen[$key] = true;
            $type = $f['type'] ?? 'text';
            if (!\in_array($type, self::TYPES, true)) {
                $errors["fields[$i].type"] = 'Type inconnu';
                continue;
            }
            $filter = $f['filter'] ?? null;
            if ($filter === '' || $filter === false) {
                $filter = null;
            }
            if (!\in_array($filter, self::FILTERS, true)) {
                $errors["fields[$i].filter"] = 'Type de filtre inconnu';
                continue;
            }
            $options = array_values(array_filter(array_map(
                static fn ($o) => trim((string) (\is_array($o) ? ($o['value'] ?? '') : $o)),
                (array) ($f['options'] ?? [])
            ), static fn ($o) => $o !== ''));
            if (\in_array($type, ['select', 'multiselect'], true) && !$options) {
                $errors["fields[$i].options"] = 'Une liste de valeurs est requise';
                continue;
            }
            $scope = $f['scope'] ?? 'dataset';
            $out[] = [
                'key' => $key,
                'label' => trim((string) ($f['label'] ?? $key)) ?: $key,
                'type' => $type,
                'required' => (bool) ($f['required'] ?? false),
                'options' => $options,
                'unit' => trim((string) ($f['unit'] ?? '')) ?: null,
                'help' => trim((string) ($f['help'] ?? '')) ?: null,
                'group' => trim((string) ($f['group'] ?? '')) ?: null,
                'filter' => $filter,
                'popup' => (bool) ($f['popup'] ?? false),
                'scope' => \in_array($scope, self::SCOPES, true) ? $scope : 'dataset',
            ];
        }
        if ($errors) {
            throw ApiException::validation($errors, 'Définition des champs invalide');
        }
        return $out;
    }

    /**
     * Contrôle les valeurs d'un objet properties pour la portée donnée.
     *
     * $keepInvalid (reprise de données) : une valeur qui ne passe pas la validation est
     * conservée telle quelle au lieu d'être écartée ; l'erreur est seulement signalée.
     *
     * @return array{0: array, 1: array<string,string>, 2: array<string,string>}
     *         [valeurs, erreurs, valeurs d'origine des champs invalides (même clés que les erreurs)]
     *         Une erreur sans valeur d'origine = champ obligatoire absent.
     */
    public function validateValues(array $fields, array $values, string $scope, string $prefix = 'properties', bool $keepInvalid = false): array
    {
        $clean = [];
        $errors = [];
        $origins = [];
        foreach ($fields as $f) {
            if (($f['scope'] ?? 'dataset') !== $scope) {
                continue;
            }
            $key = $f['key'];
            $raw = $values[$key] ?? null;
            $empty = $raw === null || $raw === '' || $raw === [];
            if ($empty) {
                if (!empty($f['required'])) {
                    $errors["$prefix.$key"] = sprintf('« %s » est obligatoire', $f['label']);
                }
                continue;
            }
            try {
                $clean[$key] = $this->coerce($f, $raw);
            } catch (\InvalidArgumentException $e) {
                $errors["$prefix.$key"] = sprintf('« %s » : %s', $f['label'], $e->getMessage());
                $origins["$prefix.$key"] = \is_scalar($raw) ? (string) $raw : (string) json_encode($raw, JSON_UNESCAPED_UNICODE);
                if ($keepInvalid) {
                    $clean[$key] = $raw;
                }
            }
        }
        return [$clean, $errors, $origins];
    }

    private function coerce(array $f, mixed $raw): mixed
    {
        switch ($f['type']) {
            case 'number':
                $v = \is_string($raw) ? str_replace([',', ' '], ['.', ''], trim($raw)) : $raw;
                if (!is_numeric($v)) {
                    throw new \InvalidArgumentException('nombre attendu');
                }
                return (float) $v;
            case 'integer':
                $v = \is_string($raw) ? str_replace(' ', '', trim($raw)) : $raw;
                if (filter_var($v, FILTER_VALIDATE_INT) === false) {
                    throw new \InvalidArgumentException('entier attendu');
                }
                return (int) $v;
            case 'boolean':
                if (\is_bool($raw)) {
                    return $raw;
                }
                $s = mb_strtolower(trim((string) $raw));
                if (\in_array($s, ['1', 'true', 'oui', 'yes', 'o', 'y', 'vrai'], true)) {
                    return true;
                }
                if (\in_array($s, ['0', 'false', 'non', 'no', 'n', 'faux'], true)) {
                    return false;
                }
                throw new \InvalidArgumentException('oui/non attendu');
            case 'date':
                $d = DateParser::parse($raw);
                return $d?->format('Y-m-d');
            case 'select':
                $v = trim((string) $raw);
                $match = $this->matchOption($f['options'], $v);
                if ($match === null) {
                    throw new \InvalidArgumentException("valeur « $v » hors liste");
                }
                return $match;
            case 'multiselect':
                $list = \is_array($raw) ? $raw : preg_split('/\s*[;|]\s*/', (string) $raw);
                $out = [];
                foreach ($list as $item) {
                    $item = trim((string) $item);
                    if ($item === '') {
                        continue;
                    }
                    $match = $this->matchOption($f['options'], $item);
                    if ($match === null) {
                        throw new \InvalidArgumentException("valeur « $item » hors liste");
                    }
                    $out[] = $match;
                }
                return array_values(array_unique($out));
            case 'url':
                $v = trim((string) $raw);
                if (!filter_var($v, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $v)) {
                    throw new \InvalidArgumentException('URL http(s) attendue');
                }
                return $v;
            default:
                $v = trim((string) $raw);
                if (mb_strlen($v) > 10000) {
                    throw new \InvalidArgumentException('texte trop long');
                }
                return $v;
        }
    }

    /** Correspondance insensible à la casse et aux accents avec les valeurs autorisées. */
    private function matchOption(array $options, string $value): ?string
    {
        if (\in_array($value, $options, true)) {
            return $value;
        }
        $norm = static fn (string $s) => mb_strtolower(trim(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s));
        $nv = $norm($value);
        foreach ($options as $o) {
            if ($norm($o) === $nv) {
                return $o;
            }
        }
        return null;
    }
}