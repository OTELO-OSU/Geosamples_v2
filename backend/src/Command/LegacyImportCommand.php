<?php

namespace App\Command;

use App\Entity\Dataset;
use App\Entity\Site;
use App\Exception\ApiException;
use App\Repository\SiteRepository;
use App\Service\DatasetWriter;
use App\Service\FieldSchema;
use App\Service\FileStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Reprise des données de l'ancienne application geo-samples (MongoDB / Elasticsearch).
 *
 * Entrée : export JSON des documents (mongoexport --jsonArray, ou NDJSON, ou réponse
 * _search Elasticsearch). Structure attendue (cf. geo-samples/Docs/Organisation.md) :
 *   INTRO.TITLE, INTRO.DATA_DESCRIPTION, INTRO.KEYWORDS, INTRO.FILE_CREATOR[], INTRO.LANGUAGE,
 *   INTRO.LICENSE, INTRO.ACCESS_RIGHT, INTRO.SAMPLING_DATE, INTRO.SAMPLING_POINT[],
 *   INTRO.SUPPLEMENTARY_FIELDS{}, INTRO.MEASUREMENT[], DATA.FILES[]
 *
 *   php bin/console app:legacy:import nometal export.json --dry-run
 *   php bin/console app:legacy:import nometal export.json --create-fields --files-dir=/srv/geosamples/files
 */
#[AsCommand(name: 'app:legacy:import', description: "Importe un export JSON de l'ancienne application geo-samples")]
final class LegacyImportCommand extends Command
{
    public function __construct(
        private SiteRepository $sites,
        private DatasetWriter $writer,
        private FieldSchema $schema,
        private FileStorage $storage,
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('site', InputArgument::REQUIRED, 'Slug du site cible')
            ->addArgument('file', InputArgument::REQUIRED, 'Export JSON / NDJSON')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Contrôle sans écriture')
            ->addOption('create-fields', null, InputOption::VALUE_NONE, 'Crée en champs texte les SUPPLEMENTARY_FIELDS inconnus du site')
            ->addOption('files-dir', null, InputOption::VALUE_REQUIRED, 'Répertoire des fichiers de l\'ancienne application (recherche par nom)')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Statut des jeux importés', Dataset::STATUS_PUBLISHED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $site = $this->sites->findOneBy(['slug' => $input->getArgument('site')]);
        if (!$site) {
            $io->error('Site introuvable');
            return Command::FAILURE;
        }
        $docs = $this->readDocs((string) $input->getArgument('file'));
        $io->writeln(\count($docs) . ' document(s) lu(s)');

        if ($input->getOption('create-fields')) {
            $this->createFields($site, $docs, $io, (bool) $input->getOption('dry-run'));
        }
        $fileIndex = $input->getOption('files-dir') ? $this->indexFiles((string) $input->getOption('files-dir')) : [];

        $ok = 0;
        $errors = [];
        foreach ($docs as $i => $doc) {
            $payload = $this->toPayload($doc, $site);
            $d = new Dataset($site, null);
            try {
                $this->writer->apply($d, $payload);
            } catch (ApiException $e) {
                $errors[] = [$i + 1, $payload['title'] ?: '—', implode(' ; ', array_map(static fn ($k, $v) => "$k : $v", array_keys($e->getDetails()), $e->getDetails()))];
                continue;
            }
            $ok++;
            if ($input->getOption('dry-run')) {
                continue;
            }
            $d->setStatus((string) $input->getOption('status'));
            $this->em->persist($d);
            foreach ((array) ($doc['DATA']['FILES'] ?? []) as $f) {
                $name = basename((string) ($f['DATA_URL'] ?? ''));
                if ($name !== '' && isset($fileIndex[$name])) {
                    $this->storage->store($d, new UploadedFile($fileIndex[$name], $name, null, null, true));
                }
            }
            if ($ok % 200 === 0) {
                $this->em->flush();
            }
        }
        if (!$input->getOption('dry-run')) {
            $this->em->flush();
        }
        if ($errors) {
            $io->warning(\count($errors) . ' document(s) rejeté(s)');
            $io->table(['#', 'Titre', 'Erreurs'], \array_slice($errors, 0, 50));
        }
        $io->success(sprintf('%d jeu(x) %s', $ok, $input->getOption('dry-run') ? 'valides (simulation)' : 'importés'));
        return Command::SUCCESS;
    }

    private function readDocs(string $file): array
    {
        $raw = file_get_contents($file);
        $json = json_decode($raw, true);
        if (\is_array($json)) {
            $docs = $json['hits']['hits'] ?? $json;
        } else {
            $docs = array_values(array_filter(array_map(static fn ($l) => json_decode($l, true), preg_split('/\R/', $raw))));
        }
        return array_map(static fn ($d) => $d['_source'] ?? $d, $docs);
    }

    private function toPayload(array $doc, Site $site): array
    {
        $in = $doc['INTRO'] ?? [];
        $fields = $site->getFieldMap();
        $first = static fn ($v) => \is_array($v) ? (reset($v) ?: null) : $v;

        $keywords = [];
        foreach ((array) ($in['KEYWORDS'] ?? $in['KEYWORD'] ?? []) as $k) {
            $keywords[] = \is_array($k) ? ($k['NAME'] ?? reset($k)) : $k;
        }
        $authors = [];
        foreach ((array) ($in['FILE_CREATOR'] ?? []) as $c) {
            $name = $c['DISPLAY_NAME'] ?? trim(($c['FIRST_NAME'] ?? '') . ' ' . ($c['NAME'] ?? ''));
            if ($name) {
                $authors[] = ['name' => $name];
            }
        }
        $props = [];
        foreach ((array) ($in['SUPPLEMENTARY_FIELDS'] ?? []) as $k => $v) {
            $key = $this->key((string) $k);
            if (isset($fields[$key]) && ($fields[$key]['scope'] ?? 'dataset') === 'dataset' && !\is_array($v)) {
                $props[$key] = $v;
            }
        }
        if (isset($fields['measurements'])) {
            $props['measurements'] = implode(' ; ', array_map(static fn ($m) => trim(($m['ABBREVIATION'] ?? '') . ' ' . (isset($m['NATURE']) ? "({$m['NATURE']}, " . ($m['UNIT'] ?? '') . ')' : '')), array_filter((array) ($in['MEASUREMENT'] ?? []), 'is_array')));
        }
        $date = $first($in['SAMPLING_DATE'] ?? null);
        $points = [];
        foreach ((array) ($in['SAMPLING_POINT'] ?? []) as $p) {
            $lon = $this->num($p['LONGITUDE'] ?? null);
            $lat = $this->num($p['LATITUDE'] ?? null);
            $crs = mb_strtolower((string) ($p['COORDINATE_SYSTEM'] ?? ''));
            $src = [];
            if ($lon !== null && $lat !== null && str_contains($crs, 'lambert')) {
                $src = ['srcCrs' => 'EPSG:2154', 'srcX' => $lon, 'srcY' => $lat];
                [$lon, $lat] = self::lambert93ToWgs84($lon, $lat);
            }
            $points[] = [
                'name' => $p['NAME'] ?? $p['ABBREVIATION'] ?? null,
                'longitude' => $lon, 'latitude' => $lat,
                'elevation' => $this->num($p['ELEVATION'] ?? $p['ELEVATION_M'] ?? null),
                'observedAt' => $date,
            ] + $src;
        }
        $lang = mb_strtoupper((string) ($in['LANGUAGE'] ?? ''));
        return [
            'title' => (string) ($in['TITLE'] ?? ''),
            'description' => $in['DATA_DESCRIPTION'] ?? null,
            'keywords' => $keywords,
            'authors' => $authors,
            'language' => $lang === 'FRENCH' ? 'fr' : ($lang === 'ENGLISH' ? 'en' : null),
            'license' => $in['LICENSE'] ?? null,
            'accessRight' => preg_match('/open/i', (string) ($in['ACCESS_RIGHT'] ?? 'open')) ? 'open' : 'restricted',
            'temporalStart' => $date,
            'properties' => $props,
            'points' => $points,
        ];
    }

    private function createFields(Site $site, array $docs, SymfonyStyle $io, bool $dry): void
    {
        $existing = $site->getFieldMap();
        $new = [];
        foreach ($docs as $doc) {
            foreach ((array) ($doc['INTRO']['SUPPLEMENTARY_FIELDS'] ?? []) as $k => $v) {
                $key = $this->key((string) $k);
                if (!\is_array($v) && !isset($existing[$key]) && !isset($new[$key]) && !\in_array($key, FieldSchema::RESERVED, true)) {
                    $new[$key] = ['key' => $key, 'label' => ucfirst(mb_strtolower(str_replace('_', ' ', (string) $k))), 'type' => 'text', 'group' => 'Reprise geo-samples'];
                }
            }
        }
        if (!$new) {
            return;
        }
        $io->writeln('Champs créés : ' . implode(', ', array_keys($new)));
        if (!$dry) {
            $site->setFields($this->schema->normalizeDefinitions(array_merge($site->getFields(), array_values($new))));
            $this->em->flush();
        }
    }

    private function indexFiles(string $dir): array
    {
        $idx = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $idx[$f->getFilename()] ??= $f->getPathname();
        }
        return $idx;
    }

    private function key(string $k): string
    {
        $k = preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $k) ?: $k));
        $k = trim((string) $k, '_');
        return preg_match('/^[a-z]/', $k) ? substr($k, 0, 63) : 'f_' . substr($k, 0, 60);
    }

    private function num(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        $v = str_replace([',', ' '], ['.', ''], (string) $v);
        return is_numeric($v) ? (float) $v : null;
    }

    /** Lambert-93 (RGF93, GRS80) → WGS84 ; écart RGF93/WGS84 négligeable à cette échelle. */
    public static function lambert93ToWgs84(float $x, float $y): array
    {
        $n = 0.7256077650532670; $c = 11754255.426096; $xs = 700000.0; $ys = 12655612.049876;
        $e = 0.0818191910428158; $lon0 = 3 * M_PI / 180;
        $dx = $x - $xs; $dy = $y - $ys;
        $r = sqrt($dx * $dx + $dy * $dy);
        $gamma = atan($dx / -$dy);
        $lon = $lon0 + $gamma / $n;
        $latIso = -1 / $n * log(abs($r / $c));
        $lat = 2 * atan(exp($latIso)) - M_PI / 2;
        for ($i = 0; $i < 10; $i++) {
            $lat = 2 * atan(((1 + $e * sin($lat)) / (1 - $e * sin($lat))) ** ($e / 2) * exp($latIso)) - M_PI / 2;
        }
        return [round($lon * 180 / M_PI, 7), round($lat * 180 / M_PI, 7)];
    }
}
