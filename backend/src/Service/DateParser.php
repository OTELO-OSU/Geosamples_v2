<?php

namespace App\Service;

/**
 * Analyse souple des dates saisies ou importées :
 *  "2021", "2021-06", "2021-06-15", "15/06/2021", "2021-06-15T10:30", ISO 8601…
 * Une date partielle utilisée comme borne de fin est étendue à la fin de la période.
 */
final class DateParser
{
    public static function parse(mixed $value, bool $isEnd = false): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }
        $utc = new \DateTimeZone('UTC');
        if (preg_match('/^(\d{4})$/', $s, $m)) {
            return $isEnd
                ? new \DateTimeImmutable("{$m[1]}-12-31 23:59:59", $utc)
                : new \DateTimeImmutable("{$m[1]}-01-01 00:00:00", $utc);
        }
        if (preg_match('/^(\d{4})-(\d{1,2})$/', $s, $m)) {
            $d = new \DateTimeImmutable(sprintf('%s-%02d-01 00:00:00', $m[1], $m[2]), $utc);
            return $isEnd ? $d->modify('last day of this month')->setTime(23, 59, 59) : $d;
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$#', $s, $m)) {
            $s = sprintf('%s-%02d-%02d %02d:%02d:%02d', $m[3], $m[2], $m[1], $m[4] ?? 0, $m[5] ?? 0, $m[6] ?? 0);
            $d = new \DateTimeImmutable($s, $utc);
            return ($isEnd && !isset($m[4])) ? $d->setTime(23, 59, 59) : $d;
        }
        if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $s)) {
            $d = new \DateTimeImmutable($s . ' 00:00:00', $utc);
            return $isEnd ? $d->setTime(23, 59, 59) : $d;
        }
        // Numéro de série Excel (jours depuis 1899-12-30)
        if (preg_match('/^\d{5}(\.\d+)?$/', $s)) {
            $days = (float) $s;
            return (new \DateTimeImmutable('1899-12-30 00:00:00', $utc))->modify(sprintf('+%d seconds', (int) round($days * 86400)));
        }
        try {
            return new \DateTimeImmutable($s, $utc);
        } catch (\Exception) {
            throw new \InvalidArgumentException("Date non reconnue : « $s »");
        }
    }

    public static function format(?\DateTimeInterface $d): ?string
    {
        return $d?->format(\DateTimeInterface::ATOM);
    }
}
