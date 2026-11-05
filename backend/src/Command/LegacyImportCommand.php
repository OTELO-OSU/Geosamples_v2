<?php

namespace App\Command;

use App\Entity\Dataset;
use App\Entity\Site;
use App\Exception\ApiException;
use App\Service\DatasetWriter;
use App\Service\FieldSchema;
use App\Service\FileStorage;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Import data from the legacy geo-samples application (MongoDB / Elasticsearch).
 *
 * Input: JSON document export (mongoexport --jsonArray, NDJSON, or an Elasticsearch
 * _search response). Expected structure (see geo-samples/Docs/Organisation.md):
 *   INTRO.TITLE, INTRO.DATA_DESCRIPTION, INTRO.KEYWORDS, INTRO.FILE_CREATOR[], INTRO.LANGUAGE,
 *   INTRO.LICENSE, INTRO.ACCESS_RIGHT, INTRO.SAMPLING_DATE, INTRO.SAMPLING_POINT[],
 *   INTRO.SUPPLEMENTARY_FIELDS{}, INTRO.MEASUREMENT[], DATA.FILES[]
 *
 * Grouping: in the legacy application, each sample was a document. By default, documents
 * with the same title, description, and authors are grouped into ONE dataset (one point per
 * document). Additional fields whose values vary within a group become "point" fields.
 * --no-group disables grouping.
 *
 * No documents are rejected and NO datasets are set to draft: all datasets receive the
 * --status value. Missing values remain empty (nothing is guessed); invalid values are
 * retained unless storage disallows them. Every missing, invalid, or dropped field is listed
 * per dataset in the CSV report (--report) and summarized in reviewComment.
 *
 *   php bin/console app:legacy:import nometal export.json --dry-run
 *   php bin/console app:legacy:import nometal export.json --create-fields --files-dir=/srv/geosamples/files --report=/tmp/nometal.csv
 */
#[AsCommand(name: 'app:legacy:import', description: 'Import a JSON export from the legacy geo-samples application')]
final class LegacyImportCommand extends Command
{
    private const IMPORT_GROUP = 'Legacy geo-samples import';
    private const PREVIOUS_IMPORT_GROUP = 'Reprise geo-samples';

    private const REPORT_HEADER = ['#', 'dataset_id', 'source_ids', 'title', 'source_document', 'point', 'field', 'type', 'issue', 'original_value'];

    private const KIND_LABELS = [
        'missing' => 'missing',   // absent from source, left empty
        'invalid' => 'invalid',   // invalid value (retained or cleared depending on storage)
        'adjusted' => 'adjusted', // technical adjustment required by storage
        'dropped' => 'dropped',   // item not imported (point without coordinates, field outside schema...)
        'failed' => 'failed',
    ];

    /** Fields added "virtually" during --dry-run to avoid false out-of-schema errors. */
    private array $virtualFields = [];

    public function __construct(
        private ManagerRegistry $registry,
        private DatasetWriter $writer,
        private FieldSchema $schema,
        private FileStorage $storage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('site', InputArgument::REQUIRED, 'Target site slug')
            ->addArgument('file', InputArgument::REQUIRED, 'JSON / NDJSON export')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate without writing')
            ->addOption('create-fields', null, InputOption::VALUE_NONE, 'Create text fields for SUPPLEMENTARY_FIELDS not defined on the site')
            ->addOption('files-dir', null, InputOption::VALUE_REQUIRED, 'Legacy application file directory (look up by filename)')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Status for all imported datasets', Dataset::STATUS_PUBLISHED)
            ->addOption('report', null, InputOption::VALUE_REQUIRED, 'Write a CSV report of missing, invalid, or dropped fields for each dataset')
            ->addOption('no-group', null, InputOption::VALUE_NONE, 'Create one dataset per document (do not group by title)')
            ->addOption('truncate', null, InputOption::VALUE_NONE, 'DESTRUCTIVE: empty the dataset table (TRUNCATE … RESTART IDENTITY CASCADE, all sites) before import');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $slug = (string) $input->getArgument('site');
        $dry = (bool) $input->getOption('dry-run');
        $status = (string) $input->getOption('status');

        $site = $this->findSite($slug);
        if (!$site) {
            $io->error('Site not found');
            return Command::FAILURE;
        }
        $skipped = 0;
        $docs = $this->readDocs((string) $input->getArgument('file'), $skipped);
        $io->writeln(\count($docs) . ' document(s) read');
        if ($skipped) {
            $io->warning($skipped . ' unreadable line(s) (invalid JSON) skipped in the input file');
        }

        if ($input->getOption('truncate')) {
            $table = $this->em()->getClassMetadata(Dataset::class)->getTableName();
            if ($dry) {
                $io->writeln(sprintf('Dry run: table "%s" would be emptied (TRUNCATE … RESTART IDENTITY CASCADE)', $table));
            } else {
                $this->em()->getConnection()->executeStatement(sprintf('TRUNCATE "%s" RESTART IDENTITY CASCADE', $table));
                $this->em()->clear();
                $site = $this->findSite($slug) ?? $site;
                $io->writeln(sprintf('Table "%s" emptied (along with dependent tables)', $table));
            }
        }

        $groups = $this->groupDocs($docs, !$input->getOption('no-group'));
        $io->writeln(sprintf('%d dataset(s) after grouping (%d document(s))', \count($groups), \count($docs)));

        if ($input->getOption('create-fields')) {
            $this->createFields($site, $groups, $io, $dry);
            $site = $this->findSite($slug) ?? $site;
        }
        $fileIndex = $input->getOption('files-dir') ? $this->indexFiles((string) $input->getOption('files-dir')) : [];

        $seq = 0;          // dataset number (included in the report)
        $ok = 0;
        $withIssues = 0;
        $errors = [];
        $rows = [];        // report rows: one row per (dataset, problematic field)

        foreach ($groups as $members) {
            $chunks = $this->chunkMembers($members);
            foreach ($chunks as $ci => $chunk) {
                $seq++;
                $ids = array_map(static fn ($m) => (string) ($m['_id'] ?? ''), $chunk);
                $idLabel = implode(',', \array_slice($ids, 0, 3)) . (\count($ids) > 3 ? ' …(+' . (\count($ids) - 3) . ')' : '');
                $payload = ['title' => (string) ($chunk[0]['INTRO']['TITLE'] ?? ''), 'points' => []];
                $datasetId = null;

                try {
                    $payload = $this->groupPayload($chunk, $site);
                    if (\count($chunks) > 1) {
                        $payload['title'] .= sprintf(' (part %d/%d)', $ci + 1, \count($chunks));
                    }
                    $d = new Dataset($site, null);
                    $issues = $this->writer->apply($d, $payload, true);

                    // Keep source metadata even when it is not defined as a configurable Site field.
                    $this->preservePayloadProperties($d, $payload);
                    $this->preservePointHoleKeys($d, $payload);

                    if (!$dry) {
                        $d->setStatus($status);
                        $em = $this->em();
                        $em->persist($d);
                        $em->flush();
                        $datasetId = method_exists($d, 'getId') ? $d->getId() : null;
                        $issues += $this->attachFiles($d, $chunk, $fileIndex);
                        $this->em()->flush();
                    }
                } catch (\Throwable $e) {
                    $msg = $this->errorMessage($e);
                    $site = $this->recover($slug, $site);
                    $title = ($payload['title'] ?? '') !== '' ? $payload['title'] : '—';
                    $fallbackId = null;
                    if (!$dry) {
                        try {
                            $fallbackId = $this->persistFallback($site, $chunk, $msg, $status);
                        } catch (\Throwable $e2) {
                            $site = $this->recover($slug, $site);
                        }
                    }
                    $errors[] = [$seq, $title, $msg . ($fallbackId ? " → minimal dataset created (id $fallbackId); source document saved in reviewComment" : '')];
                    $rows[] = [
                        $seq, $fallbackId ?? '', $idLabel, $title, $ids[0] ?? '', '', '(full dataset)',
                        self::KIND_LABELS['failed'], $msg, '',
                    ];
                    continue;
                }

                $ok++;
                $list = $this->issueList($issues, $payload, $chunk);
                if ($list) {
                    $withIssues++;
                    $title = ($payload['title'] ?? '') !== '' ? $payload['title'] : '—';
                    foreach ($list as [$field, $doc, $point, $kind, $message, $original]) {
                        $rows[] = [
                            $seq, $datasetId ?? '', $idLabel, $title, $doc, $point, $field,
                            self::KIND_LABELS[$kind] ?? $kind, $message, mb_substr((string) $original, 0, 2000),
                        ];
                    }
                }
                if (!$dry && $ok % 200 === 0) {
                    $this->em()->clear();
                    $site = $this->findSite($slug) ?? $site;
                }
            }
        }

        if ($rows && ($path = $input->getOption('report'))) {
            $this->writeReport((string) $path, $rows);
            $io->writeln(sprintf('Report: %d row(s) written to %s', \count($rows), $path));
        }
        if ($rows) {
            $io->warning(sprintf('%d dataset(s) have at least one missing, invalid, or dropped field (details: --report)', $withIssues));
            // Summary: number of affected datasets for each field.
            $perField = [];
            foreach ($rows as $r) {
                $perField[$r[6]][$r[0]] = true;
            }
            uasort($perField, static fn ($a, $b) => \count($b) <=> \count($a));
            $io->table(
                ['Field', 'Affected datasets'],
                array_map(static fn ($k, $v) => [$k, \count($v)], array_keys(\array_slice($perField, 0, 25, true)), \array_slice($perField, 0, 25))
            );
            $io->table(
                ['#', 'Title', 'Point', 'Field', 'Type', 'Issue'],
                array_map(static fn ($r) => [$r[0], mb_substr((string) $r[3], 0, 40), $r[5], $r[6], $r[7], mb_substr((string) $r[8], 0, 70)], \array_slice($rows, 0, 10))
            );
        }
        if ($errors) {
            $io->warning(\count($errors) . ' dataset(s) failed to import correctly (unexpected error)');
            $io->table(['#', 'Title', 'Errors'], \array_slice($errors, 0, 50));
        }
        $io->success(sprintf(
            '%d dataset(s) %s, including %d with missing or invalid fields',
            $ok,
            $dry ? 'importable (dry run)' : 'imported',
            $withIssues
        ));
        // Line read by migrate_all.sh for its summary (drafts remains 0 for compatibility).
        $io->writeln(sprintf(
            'IMPORT_RESULT read=%d imported=%d drafts=0 warnings=%d failed=%d dry=%d',
            \count($docs), $ok, $withIssues, \count($errors), $dry ? 1 : 0
        ));
        return $errors ? Command::FAILURE : Command::SUCCESS;
    }

    private function isStructured(mixed $raw): bool
    {
        if (!\is_array($raw)) {
            return false;
        }
        foreach ($raw as $x) {
            if (\is_array($x)) {
                return true;
            }
        }
        return false;
    }
    private function unwrap(mixed $raw): mixed
    {
        return (\is_array($raw) && \count($raw) === 1 && array_is_list($raw) && \is_array($raw[0])) ? $raw[0] : $raw;
    }

    // --------------------------------------------------------------------------------------
    // Retrieve the Doctrine manager each time (it is closed after an SQL error).
    // --------------------------------------------------------------------------------------

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $this->registry->getManager();
        return $em;
    }

    private function findSite(string $slug): ?Site
    {
        return $this->em()->getRepository(Site::class)->findOneBy(['slug' => $slug]);
    }

    /** Reopen a manager closed by an SQL error and reload the site. */
    private function recover(string $slug, Site $site): Site
    {
        if (!$this->em()->isOpen()) {
            $this->registry->resetManager();
            return $this->findSite($slug) ?? $site;
        }
        return $site;
    }

    private function errorMessage(\Throwable $e): string
    {
        if ($e instanceof ApiException && $e->getDetails()) {
            return implode(' ; ', array_map(static fn ($k, $v) => "$k : $v", array_keys($e->getDetails()), $e->getDetails()));
        }
        return $e->getMessage();
    }

    /**
    * Last resort: if normal mapping fails, create a minimal dataset and retain recoverable
    * sections in properties without copying the entire V1 document into reviewComment.
    */

    private function persistFallback(Site $site, array $chunk, string $reason, string $status): mixed
    {
        $d = new Dataset($site, null);
        $d->setTitle((string) ($chunk[0]['INTRO']['TITLE'] ?? ''));
        $d->setStatus($status);
        $d->setReviewComment(mb_substr("Automatic import failed: $reason", 0, 20000));

        $properties = ['source_document_ids' => []];
        foreach ($chunk as $sourceDoc) {
            $sourceId = (string) ($sourceDoc['_id'] ?? '');
            if ($sourceId !== '') {
                $properties['source_document_ids'][] = $sourceId;
            }
            $intro = \is_array($sourceDoc['INTRO'] ?? null) ? $sourceDoc['INTRO'] : [];
            foreach ($intro as $key => $value) {
                if ($value !== null && $value !== '' && $value !== []) {
                    $this->mergePreservedProperties($properties, [$this->key((string) $key) => $value]);
                }
            }
            $this->preserveSourceData($sourceDoc, $properties, true); // here FILES is kept too
        }
        $d->setProperties($properties);

        $em = $this->em();
        $em->persist($d);
        $em->flush();
        return $d->getId();
    }

    // --------------------------------------------------------------------------------------
    // Attachments
    // --------------------------------------------------------------------------------------

    /** @return array<string, array{kind:string,message:string,original:?string}> encountered issues */
    private function attachFiles(Dataset $d, array $chunk, array $fileIndex): array
    {
        $issues = [];
        $attached = [];
        foreach ($chunk as $m) {
            foreach ((array) ($m['DATA']['FILES'] ?? []) as $f) {
                $url = \is_array($f)
                    ? (string) ($f['DATA_URL'] ?? $f['URL'] ?? $f['FILE_URL'] ?? $f['NAME'] ?? $f['FILENAME'] ?? '')
                    : (string) $f;
                $name = rawurldecode(basename((string) parse_url($url, PHP_URL_PATH) ?: $url));
                if ($name === '' || isset($attached[$name])) {
                    continue;
                }
                $attached[$name] = true;
                if (!$fileIndex) {
                    $issues["files.$name"] = ['kind' => 'missing', 'message' => "File “$name” not attached: --files-dir was not provided", 'original' => $url];
                    continue;
                }
                $path = $fileIndex[$name] ?? $fileIndex[mb_strtolower($name)] ?? null;
                if (!$path) {
                    $issues["files.$name"] = ['kind' => 'missing', 'message' => "File “$name” not found in --files-dir", 'original' => $url];
                    continue;
                }
                $tmp = sys_get_temp_dir() . '/' . uniqid('imp_', true) . '_' . $name;
                try {
                    if (!copy($path, $tmp)) {
                        throw new \RuntimeException('copy failed');
                    }
                    $this->storage->store($d, new UploadedFile($tmp, $name, null, null, true));
                } catch (\Throwable $e) {
                    $issues["files.$name"] = ['kind' => 'failed', 'message' => "File “$name” not attached: " . $e->getMessage(), 'original' => $url];
                } finally {
                    if (is_file($tmp)) {
                        @unlink($tmp);
                    }
                }
            }
        }
        return $issues;
    }

    // --------------------------------------------------------------------------------------
    // Report
    // --------------------------------------------------------------------------------------

    /**
     * Convert writer issues into readable rows:
     * [field, source document, point, type, message, original value]
     * @return list<array{0:string,1:string,2:string,3:string,4:string,5:?string}>
     */
    private function issueList(array $issues, array $payload, array $chunk): array
    {
        $firstDoc = (string) ($chunk[0]['_id'] ?? '');
        $list = [];
        foreach ($issues as $key => $i) {
            $field = (string) $key;
            $doc = $firstDoc;
            $point = '';
            if (preg_match('/^points\[(\d+)\](?:\.(.+))?$/', $field, $m)) {
                $pt = $payload['points'][(int) $m[1]] ?? [];
                $doc = (string) ($pt['_src'] ?? $firstDoc);
                $name = trim((string) ($pt['name'] ?? ''));
                $point = '#' . ((int) $m[1] + 1) . ($name !== '' ? ' ' . $name : '');
                $field = $m[2] ?? 'point';
            } elseif (str_starts_with($field, 'files.')) {
                $field = 'files';
            }
            $list[] = [$field, $doc, $point, $i['kind'], $i['message'], $i['original'] ?? ''];
        }
        foreach ($payload['_unmapped'] ?? [] as $u) {
            $list[] = [
                'SUPPLEMENTARY_FIELDS.' . $u['key'], $u['doc'], '', 'adjusted',
                'Field retained in the dataset JSONB properties; --create-fields can also make it configurable', $u['value'],
            ];
        }
        return $list;
    }

    private function writeReport(string $path, array $rows): void
    {
        $fp = fopen($path, 'wb');
        if (!$fp) {
            return;
        }
        fwrite($fp, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel.
        fputcsv($fp, self::REPORT_HEADER, ';', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($fp, $row, ';', '"', '\\');
        }
        fclose($fp);
    }

    // --------------------------------------------------------------------------------------
    // Reading / grouping
    // --------------------------------------------------------------------------------------

    private function readDocs(string $file, int &$skipped): array
    {
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new \RuntimeException("Unable to read file: $file");
        }
        $skipped = 0;
        $json = json_decode($raw, true);
        if (\is_array($json)) {
            $isList = $json === [] || array_keys($json) === range(0, \count($json) - 1);
            if (isset($json['hits']['hits'])) {
                $docs = $json['hits']['hits'];
            } elseif ($isList) {
                $docs = $json;
            } else {
                $docs = [$json]; // single document
            }
        } else {
            $docs = [];
            foreach (preg_split('/\R/', $raw) as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $doc = json_decode($line, true);
                if (\is_array($doc)) {
                    $docs[] = $doc;
                } else {
                    $skipped++;
                }
            }
        }
        $out = [];
        foreach ($docs as $d) {
            if (\is_array($d)) {
                $out[] = $d['_source'] ?? $d;
            }
        }
        return $out;
    }

    private function authorName(array $c): string
    {
        return trim((string) ($c['DISPLAY_NAME'] ?? trim(($c['FIRST_NAME'] ?? '') . ' ' . ($c['NAME'] ?? ''))));
    }

    /**
     * Group documents with the same title, description, and authors (preserving appearance order).
     * Documents without a title remain separate.
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function groupDocs(array $docs, bool $group): array
    {
        if (!$group) {
            return array_map(static fn ($d) => [$d], $docs);
        }
        $groups = [];
        foreach ($docs as $doc) {
            $in = \is_array($doc['INTRO'] ?? null) ? $doc['INTRO'] : [];
            $title = mb_strtolower(trim((string) ($in['TITLE'] ?? '')));
            if ($title === '') {
                $groups[] = [$doc];
                continue;
            }
            $authors = [];
            foreach ((array) ($in['FILE_CREATOR'] ?? []) as $c) {
                if (\is_array($c) && ($n = $this->authorName($c)) !== '') {
                    $authors[] = mb_strtolower($n);
                }
            }
            sort($authors);
            $key = 'g' . md5($title . '|' . trim((string) ($in['DATA_DESCRIPTION'] ?? '')) . '|' . implode(';', $authors));
            $groups[$key][] = $doc;
        }
        return array_values($groups);
    }

    /**
     * Split a group into datasets with at most DatasetWriter::MAX_POINTS points
     * (counting points, not documents: a document may contain several).
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function chunkMembers(array $members): array
    {
        $chunks = [];
        $cur = [];
        $n = 0;
        foreach ($members as $m) {
            $c = max(1, \count((array) ($m['INTRO']['SAMPLING_POINT'] ?? [])));
            if ($cur && $n + $c > DatasetWriter::MAX_POINTS) {
                $chunks[] = $cur;
                $cur = [];
                $n = 0;
            }
            $cur[] = $m;
            $n += $c;
        }
        if ($cur) {
            $chunks[] = $cur;
        }
        return $chunks;
    }

    /** Dataset payload: metadata from the first document, points from all documents in the group. */
    private function groupPayload(array $members, Site $site): array
    {
        $payload = $this->toPayload($members[0], $site);
        if (\count($members) === 1) {
            return $payload;
        }
        $keywords = $payload['keywords'];
        $authors = [];
        foreach ($payload['authors'] as $a) {
            $authors[mb_strtolower($a['name'])] = $a;
        }
        foreach (\array_slice($members, 1) as $m) {
            $p = $this->toPayload($m, $site);
            foreach ($p['keywords'] as $k) {
                $keywords[] = $k;
            }
            foreach ($p['authors'] as $a) {
                $authors[mb_strtolower($a['name'])] ??= $a;
            }
            foreach ($p['points'] as $pt) {
                $payload['points'][] = $pt;
            }
            foreach ($p['_unmapped'] as $u) {
                $payload['_unmapped'][] = $u;
            }
            $this->mergePreservedProperties($payload['properties'], $p['properties']);
            $this->mergePreservedProperties($payload['_structured'], $p['_structured']);
            $payload['description'] ??= $p['description'];
        }
        $payload['keywords'] = array_values(array_unique($keywords));
        $payload['authors'] = array_values($authors);
        // The writer derives the period from the points' dates.
        $payload['temporalStart'] = null;
        return $payload;
    }

    /** Convert an array of values to text; leave scalars unchanged. */
    private function scalarize(mixed $v): mixed
    {
        if (!\is_array($v)) {
            return $v;
        }
        return implode(' ; ', array_map(
            static fn ($x) => \is_scalar($x) ? (string) $x : (string) json_encode($x, JSON_UNESCAPED_UNICODE),
            $v
        ));
    }

    private function toPayload(array $doc, Site $site): array
    {
        $in = \is_array($doc['INTRO'] ?? null) ? $doc['INTRO'] : [];
        $docId = (string) ($doc['_id'] ?? '');
        $fields = array_merge($site->getFieldMap(), $this->virtualFields);
        $first = static fn ($v) => \is_array($v) ? (reset($v) ?: null) : $v;
        $unmapped = [];

        // Keywords: use only values from the source (no fallback or guessing).
        $keywords = [];
        foreach ((array) ($in['KEYWORDS'] ?? $in['KEYWORD'] ?? []) as $k) {
            $keywords[] = \is_array($k) ? ($k['NAME'] ?? reset($k)) : $k;
        }

        $authors = [];
        foreach ((array) ($in['FILE_CREATOR'] ?? []) as $c) {
            if (\is_string($c)) {
                $c = ['DISPLAY_NAME' => $c];
            }
            if (!\is_array($c)) {
                continue;
            }
            $name = $this->authorName($c);
            if ($name !== '') {
                $author = ['name' => $name];
                $affiliation = $c['AFFILIATION'] ?? $c['INSTITUTION'] ?? null;
                $orcid = $c['ORCID'] ?? null;
                if (is_scalar($affiliation) && trim((string) $affiliation) !== '') {
                    $author['affiliation'] = trim((string) $affiliation);
                }
                if (is_scalar($orcid) && trim((string) $orcid) !== '') {
                    $author['orcid'] = trim((string) $orcid);
                }
                $authors[] = $author;
            }
        }

        // Additional fields: dataset-level or point-level according to the field definition.
        $props = [];
        $pointProps = [];
        $props['source_document_ids'] = $docId !== '' ? [$docId] : [];
        $structuredProps = [];

        $sf = (array) ($in['SUPPLEMENTARY_FIELDS'] ?? []);

        foreach ($sf as $k => $raw) {

            $v = $this->scalarize($raw);

            if ($v === null || $v === '') {
                continue;
            }
            $key = $this->key((string) $k);
            $structured = $this->isStructured($raw) ? $this->unwrap($raw) : null;

            if (!isset($fields[$key])) {
                $unmapped[] = ['key' => (string) $k, 'doc' => $docId, 'value' => (string) $v];
                $props[$key] = \is_array($raw) ? $this->unwrap($raw) : $v;
                continue;
            }
            if (($fields[$key]['scope'] ?? 'dataset') === 'point') {
                $pointProps[$key] = $v;
            } else {
                $props[$key] = $v;                      // still passes through the writer validation
                if ($structured !== null) {
                    $structuredProps[$key] = $structured; // restored after the writer
                }
            }
        }

        // Per-sample values belong to the point, so each ladder entry keeps its own values.
        $hole = $first($sf['HOLEID_POINT'] ?? null);
        if (\is_array($hole)) {
            $holeId = trim((string) ($hole['HOLEID'] ?? ''));
            if ($holeId !== '') {
                $pointProps['hole_id'] = $holeId;               // string, never numeric
            }
            foreach (['HOLEID_LATITUDE' => 'hole_latitude', 'HOLEID_LONGITUDE' => 'hole_longitude', 'HOLEID_ELEVATION' => 'hole_elevation'] as $src => $dst) {
                if (isset($hole[$src]) && $hole[$src] !== '') {
                    $pointProps[$dst] = $this->num($hole[$src]) ?? $hole[$src];
                }
            }
        }

        foreach (['SAMPLE_NAME' => 'sample_name', 'SAMPLE_DEPTH_FROM' => 'sample_depth_from', 'SAMPLE_DEPTH_TO' => 'sample_depth_to'] as $src => $dst) {

            if (isset($sf[$src]) && $sf[$src] !== '') {

                $pointProps[$dst] = $this->num($sf[$src]) ?? $sf[$src];

            }

        }

        $measurements = array_values(array_filter((array) ($in['MEASUREMENT'] ?? []), 'is_array'));

        if ($measurements) {
            // Raw structured measurements: always kept, restored after the writer (see _structured)
            $structuredProps['measurements'] = $measurements;

            if (isset($fields['measurements'])) {
                // Text summary for the configured site field (validated by the writer)
                $m = implode(' ; ', array_map(static fn ($m) => trim(($m['ABBREVIATION'] ?? '') . ' ' . (isset($m['NATURE']) ? "({$m['NATURE']}, " . ($m['UNIT'] ?? '') . ')' : '')), $measurements));
                if (($fields['measurements']['scope'] ?? 'dataset') === 'point') {
                    $pointProps['measurements'] = $m;
                } else {
                    $props['measurements'] = $m;
                }
            } else {
                $unmapped[] = ['key' => 'MEASUREMENT', 'doc' => $docId, 'value' => (string) json_encode($measurements, JSON_UNESCAPED_UNICODE)];
            }
        }

        $date = $first($in['SAMPLING_DATE'] ?? null);

        $points = [];
        foreach ((array) ($in['SAMPLING_POINT'] ?? []) as $p) {
            if (!\is_array($p)) {
                $p = ['NAME' => \is_scalar($p) ? (string) $p : null]; // will be reported: coordinates are missing
            }
            $rawLon = $p['LONGITUDE'] ?? null;
            $rawLat = $p['LATITUDE'] ?? null;
            $lon = $this->num($rawLon);
            $lat = $this->num($rawLat);
            $crs = mb_strtolower((string) ($p['COORDINATE_SYSTEM'] ?? ''));
            $src = [];
            if ($lon !== null && $lat !== null && str_contains($crs, 'lambert')) {
                $src = ['srcCrs' => 'EPSG:2154', 'srcX' => $lon, 'srcY' => $lat];
                [$lon, $lat] = self::lambert93ToWgs84($lon, $lat);
            }
            $rawElev = $p['ELEVATION'] ?? $p['ELEVATION_M'] ?? null;
            $thisPointProps = $pointProps;
            $knownPointKeys = ['NAME', 'ABBREVIATION', 'LONGITUDE', 'LATITUDE', 'ELEVATION', 'ELEVATION_M', 'COORDINATE_SYSTEM'];
            foreach ($p as $pointKey => $pointValue) {
                if (!in_array($pointKey, $knownPointKeys, true) && $pointValue !== null && $pointValue !== '') {
                    $thisPointProps['source_point_' . $this->key((string) $pointKey)] = $pointValue;
                }
            }

            $points[] = [
                'name' => $p['NAME'] ?? $p['ABBREVIATION'] ?? null,
                // Retain the original value if it is not numeric (the writer reports it).
                'longitude' => $lon ?? $rawLon, 'latitude' => $lat ?? $rawLat,
                'elevation' => $this->num($rawElev) ?? $rawElev,
                'observedAt' => $date,
                'properties' => $thisPointProps,
                '_src' => $docId,
                '_crs' => $p['COORDINATE_SYSTEM'] ?? null,
            ] + $src;
        }

        // Convert FRENCH/ENGLISH and ISO codes; pass other values through unchanged
        // (the writer reports them with their original values rather than discarding them).
        $langRaw = trim((string) ($in['LANGUAGE'] ?? ''));
        $language = null;
        if ($langRaw !== '') {
            $language = ['FRENCH' => 'fr', 'ENGLISH' => 'en'][mb_strtoupper($langRaw)]
                ?? (preg_match('/^[A-Za-z]{2,3}$/', $langRaw) ? mb_strtolower($langRaw) : $langRaw);
        }

        // Access right: absent → null (reported by the writer), otherwise open / restricted.
        $ar = $in['ACCESS_RIGHT'] ?? null;
        $accessRight = ($ar === null || trim((string) $ar) === '')
            ? null
            : (preg_match('/open/i', (string) $ar) ? 'open' : 'restricted');

        // Every other INTRO key is kept in the dataset properties
        $handled = ['TITLE', 'DATA_DESCRIPTION', 'KEYWORDS', 'KEYWORD', 'FILE_CREATOR', 'SUPPLEMENTARY_FIELDS',
                    'MEASUREMENT', 'SAMPLING_DATE', 'SAMPLING_POINT', 'LANGUAGE', 'ACCESS_RIGHT', 'LICENSE', 'DATA'];
        foreach ($in as $k => $raw) {
            if (\in_array($k, $handled, true) || $raw === null || $raw === '' || $raw === []) {
                continue;
            }
            $key = $this->key((string) $k);
            if (!isset($props[$key])) {
                $props[$key] = $raw; // upload_date, creation_date, metadata_date, institution, scientific_field, methodology…
            } else {
                // Avoid silently discarding a source value when a normalized key already exists.
                $this->mergePreservedProperties($props, [$key => $raw]);
            }
        }

        $this->preserveSourceData($doc, $props);

        return [
            'title' => (string) ($in['TITLE'] ?? ''),
            'description' => $in['DATA_DESCRIPTION'] ?? null,
            'keywords' => $keywords,
            'authors' => $authors,
            'language' => $language,
            'license' => $in['LICENSE'] ?? null,
            'accessRight' => $accessRight,
            'temporalStart' => $date,
            'properties' => $props,
            '_structured' => $structuredProps,
            'points' => $points,
            '_unmapped' => $unmapped,

        ];

    }

    private function preservePointHoleKeys(Dataset $d, array $payload): void
    {
        $entities = array_values($d->getPoints()->toArray());
        $i = 0;
        foreach ($payload['points'] as $pt) {
            $keys = array_intersect_key(
                (array) ($pt['properties'] ?? []),
                array_flip(['hole_id', 'hole_latitude', 'hole_longitude', 'hole_elevation'])
            );
            $entity = $entities[$i++] ?? null;
            if ($entity && $keys) {
                $entity->setProperties(array_merge($entity->getProperties(), $keys));
            }
        }
    }

    /** Adds every DATA.* and root-level key of a source document to $props under its own key. */
    private function preserveSourceData(array $doc, array &$props, bool $withFiles = false): void
    {
        $data = \is_array($doc['DATA'] ?? null) ? $doc['DATA'] : [];
        foreach ($data as $k => $v) {
            if ($v === null || $v === '' || $v === [] || (!$withFiles && (string) $k === 'FILES')) {
                continue;
            }
            $this->mergePreservedProperties($props, [$this->key((string) $k) => $v]);
        }
        foreach ($doc as $k => $v) {
            if (\in_array($k, ['_id', '_source', 'INTRO', 'DATA'], true) || $v === null || $v === '' || $v === []) {
                continue;
            }
            $this->mergePreservedProperties($props, [$this->key((string) $k) => $v]);
        }
    }


    /** Merge JSONB metadata without overwriting differing values from grouped source documents. */
    private function mergePreservedProperties(array &$target, array $incoming): void
    {
        foreach ($incoming as $key => $value) {
            if (!array_key_exists($key, $target)) {
                $target[$key] = $value;
                continue;
            }
            if ($target[$key] === $value) {
                continue;
            }
            $left = is_array($target[$key]) && array_is_list($target[$key]) ? $target[$key] : [$target[$key]];
            $right = is_array($value) && array_is_list($value) ? $value : [$value];
            foreach ($right as $candidate) {
                if (!in_array($candidate, $left, true)) {
                    $left[] = $candidate;
                }
            }
            $target[$key] = $left;
        }
    }

    /** Re-apply JSONB properties after DatasetWriter so unknown keys are not schema-filtered. */
    private function preservePayloadProperties(Dataset $dataset, array $payload): void
    {
        $datasetProperties = $dataset->getProperties();
        $this->mergePreservedProperties($datasetProperties, (array) ($payload['properties'] ?? []));
        foreach ((array) ($payload['_structured'] ?? []) as $key => $value) {
            $datasetProperties[$key] = $value;   // real array/object, not a JSON string
        }
        $dataset->setProperties($datasetProperties);
    }

    /**
     * Create missing fields. A field whose value varies within a group (or is not set
     * for every document) is created at point level; fields from a previous import are
     * promoted to point level when needed.
     */
    private function createFields(Site $site, array $groups, SymfonyStyle $io, bool $dry): void
    {
        $existing = $site->getFieldMap();
        $seen = [];
        $varies = [];
        foreach ($groups as $members) {
            $values = [];
            $count = [];
            foreach ($members as $doc) {
                foreach ((array) ($doc['INTRO']['SUPPLEMENTARY_FIELDS'] ?? []) as $k => $v) {
                    $v = $this->scalarize($v);
                    if ($v === null || $v === '') {
                        continue;
                    }
                    $key = $this->key((string) $k);
                    $seen[$key] ??= (string) $k;
                    $values[$key][(string) $v] = true;
                    $count[$key] = ($count[$key] ?? 0) + 1;
                }
            }
            if (\count($members) > 1) {
                foreach ($values as $key => $set) {
                    if (\count($set) > 1 || $count[$key] < \count($members)) {
                        $varies[$key] = true;
                    }
                }
            }
        }

        $new = [];
        foreach ($seen as $key => $orig) {
            if (isset($existing[$key]) || \in_array($key, FieldSchema::RESERVED, true)) {
                continue;
            }
            $new[$key] = [
                'key' => $key,
                'label' => ucfirst(mb_strtolower(str_replace('_', ' ', $orig))),
                'type' => 'text',
                'group' => self::IMPORT_GROUP,
                'scope' => isset($varies[$key]) ? 'point' : 'dataset',
            ];
        }

        $defs = $site->getFields();
        $promoted = [];
        $relabelled = false;
        foreach ($defs as &$def) {
            if (\in_array($def['group'] ?? null, [self::IMPORT_GROUP, self::PREVIOUS_IMPORT_GROUP], true)) {
                $relabelled = $relabelled || $def['group'] !== self::IMPORT_GROUP;
                $def['group'] = self::IMPORT_GROUP;
                if (($def['scope'] ?? 'dataset') === 'dataset' && isset($varies[$def['key']])) {
                    $def['scope'] = 'point';
                    $promoted[] = $def['key'];
                }
            }
        }
        unset($def);

        if (!$new && !$promoted && !$relabelled) {
            return;
        }
        if ($new) {
            $io->writeln('Fields created: ' . implode(', ', array_keys($new)));
        }
        if ($promoted) {
            $io->writeln('Fields promoted to point level: ' . implode(', ', $promoted));
        }
        $point = array_keys(array_filter($new, static fn ($f) => $f['scope'] === 'point'));
        if ($point) {
            $io->writeln('Including point fields (values differ by sample): ' . implode(', ', $point));
        }
        if ($dry) {
            // Dry run: treat these fields as existing to avoid false out-of-schema errors.
            $this->virtualFields = $new;
            foreach ($defs as $def) {
                if (\in_array($def['key'] ?? null, $promoted, true)) {
                    $this->virtualFields[$def['key']] = $def;
                }
            }
        } else {
            $site->setFields($this->schema->normalizeDefinitions(array_merge($defs, array_values($new))));
            $this->em()->flush();
        }
    }

    private function indexFiles(string $dir): array
    {
        $idx = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $idx[$f->getFilename()] ??= $f->getPathname();
            $idx[mb_strtolower($f->getFilename())] ??= $f->getPathname();
        }
        return $idx;
    }

    /** Build a field key from its source name. Prefix reserved keys with "src_" rather than dropping them. */
    private function key(string $k): string
    {
        $k = preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $k) ?: $k));
        $k = trim((string) $k, '_');
        $k = preg_match('/^[a-z]/', $k) ? substr($k, 0, 63) : 'f_' . substr($k, 0, 60);
        return \in_array($k, FieldSchema::RESERVED, true) ? 'src_' . $k : $k;
    }

    private function num(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        $v = str_replace([',', ' '], ['.', ''], (string) $v);
        return is_numeric($v) ? (float) $v : null;
    }

    /** Lambert-93 (RGF93, GRS80) → WGS84; RGF93/WGS84 difference is negligible at this scale. */
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