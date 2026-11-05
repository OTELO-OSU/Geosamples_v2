<?php

namespace App\Service;

use App\Exception\ApiException;

/**
 * Schema for site-specific fields: validates definitions (configuration screen)
 * and checks/normalizes entered values.
 */
final class FieldSchema
{
    public const TYPES = ['text', 'textarea', 'number', 'integer', 'date', 'select', 'multiselect', 'boolean', 'url'];
    public const FILTERS = [null, 'select', 'range', 'text'];
    public const SCOPES = ['dataset', 'point'];

    /** Keys reserved for common columns (prevents import collisions). */
    public const RESERVED = [
        'dataset_id', 'dataset_ref', 'title', 'description', 'keywords', 'authors', 'license', 'access_right',
        'language', 'temporal_start', 'temporal_end', 'doi', 'point_name', 'longitude', 'latitude', 'elevation',
        'vertical_datum', 'observed_at', 'observed_end', 'crs', 'x', 'y', 'q', 'from', 'to', 'bbox', 'status',
    ];

    /** Validate and normalize the list of field definitions. */
    public function normalizeDefinitions(array $fields): array
    {
        $out = [];
        $errors = [];
        $seen = [];
        foreach (array_values($fields) as $i => $f) {
            if (!\is_array($f)) {
                $errors["fields[$i]"] = 'Invalid definition';
                continue;
            }
            $key = trim((string) ($f['key'] ?? ''));
            if (!preg_match('/^[a-z][a-z0-9_]{0,62}$/', $key)) {
                $errors["fields[$i].key"] = 'Invalid key (lowercase letters, digits, and _; must start with a letter)';
                continue;
            }
            if (\in_array($key, self::RESERVED, true)) {
                $errors["fields[$i].key"] = "The key “$key” is reserved";
                continue;
            }
            if (isset($seen[$key])) {
                $errors["fields[$i].key"] = "Duplicate key “$key”";
                continue;
            }
            $seen[$key] = true;
            $type = $f['type'] ?? 'text';
            if (!\in_array($type, self::TYPES, true)) {
                $errors["fields[$i].type"] = 'Unknown type';
                continue;
            }
            $filter = $f['filter'] ?? null;
            if ($filter === '' || $filter === false) {
                $filter = null;
            }
            if (!\in_array($filter, self::FILTERS, true)) {
                $errors["fields[$i].filter"] = 'Unknown filter type';
                continue;
            }
            $options = array_values(array_filter(array_map(
                static fn ($o) => trim((string) (\is_array($o) ? ($o['value'] ?? '') : $o)),
                (array) ($f['options'] ?? [])
            ), static fn ($o) => $o !== ''));
            if (\in_array($type, ['select', 'multiselect'], true) && !$options) {
                $errors["fields[$i].options"] = 'A list of values is required';
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
            throw ApiException::validation($errors, 'Invalid field definition');
        }
        return $out;
    }

    /**
     * Validate the values in a properties object for the given scope.
     *
     * $keepInvalid (data migration): a value that fails validation is preserved as-is
     * instead of being discarded; only an error is reported.
     *
     * @return array{0: array, 1: array<string,string>, 2: array<string,string>}
     *         [values, errors, original values for invalid fields (same keys as errors)]
     *         An error without an original value means a required field is missing.
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
                    $errors["$prefix.$key"] = sprintf('“%s” is required', $f['label']);
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
                    throw new \InvalidArgumentException('number expected');
                }
                return (float) $v;
            case 'integer':
                $v = \is_string($raw) ? str_replace(' ', '', trim($raw)) : $raw;
                if (filter_var($v, FILTER_VALIDATE_INT) === false) {
                    throw new \InvalidArgumentException('integer expected');
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
                throw new \InvalidArgumentException('yes/no expected');
            case 'date':
                $d = DateParser::parse($raw);
                return $d?->format('Y-m-d');
            case 'select':
                $v = trim((string) $raw);
                $match = $this->matchOption($f['options'], $v);
                if ($match === null) {
                    throw new \InvalidArgumentException("value “$v” is not in the list");
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
                        throw new \InvalidArgumentException("value “$item” is not in the list");
                    }
                    $out[] = $match;
                }
                return array_values(array_unique($out));
            case 'url':
                $v = trim((string) $raw);
                if (!filter_var($v, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $v)) {
                    throw new \InvalidArgumentException('http(s) URL expected');
                }
                return $v;
            default:
                $v = trim((string) $raw);
                if (mb_strlen($v) > 10000) {
                    throw new \InvalidArgumentException('text is too long');
                }
                return $v;
        }
    }

    /** Match allowed values case-insensitively and without regard to accents. */
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