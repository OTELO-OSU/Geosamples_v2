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
 * Reprise des données de l'ancienne application geo-samples (MongoDB / Elasticsearch).
 *
 * Entrée : export JSON des documents (mongoexport --jsonArray, ou NDJSON, ou réponse
 * _search Elasticsearch). Structure attendue (cf. geo-samples/Docs/Organisation.md) :
 *   INTRO.TITLE, INTRO.DATA_DESCRIPTION, INTRO.KEYWORDS, INTRO.FILE_CREATOR[], INTRO.LANGUAGE,
 *   INTRO.LICENSE, INTRO.ACCESS_RIGHT, INTRO.SAMPLING_DATE, INTRO.SAMPLING_POINT[],
 *   INTRO.SUPPLEMENTARY_FIELDS{}, INTRO.MEASUREMENT[], DATA.FILES[]
 *
 * Regroupement : dans l'ancienne application, chaque échantillon était un document. Par
 * défaut, les documents de même titre, même description et mêmes auteurs sont regroupés en
 * UN jeu de données (un point par document). Les champs supplémentaires dont la valeur varie
 * à l'intérieur d'un groupe deviennent des champs « point ». --no-group désactive.
 *
 * Aucun document n'est rejeté et AUCUN jeu n'est mis en brouillon : tous les jeux reçoivent le
 * statut de --status. Une valeur absente reste vide (rien n'est deviné) ; une valeur invalide est
 * conservée telle quelle sauf si le stockage l'interdit. Chaque champ absent / invalide / écarté
 * est listé, jeu par jeu, dans le rapport CSV (--report) et résumé dans reviewComment.
 *
 *   php bin/console app:legacy:import nometal export.json --dry-run
 *   php bin/console app:legacy:import nometal export.json --create-fields --files-dir=/srv/geosamples/files --report=/tmp/nometal.csv
 */
#[AsCommand(name: 'app:legacy:import', description: "Importe un export JSON de l'ancienne application geo-samples")]
final class LegacyImportCommand extends Command
{
    private const IMPORT_GROUP = 'Reprise geo-samples';

    private const REPORT_HEADER = ['#', 'id_jeu', 'ids_origine', 'titre', 'document_origine', 'point', 'champ', 'type', 'probleme', 'valeur_origine'];

    private const KIND_LABELS = [
        'missing' => 'manquant',   // absent dans la source, laissé vide
        'invalid' => 'invalide',   // valeur incorrecte (conservée ou vidée selon le stockage)
        'adjusted' => 'ajusté',    // valeur technique imposée par le stockage
        'dropped' => 'écarté',     // élément non repris (point sans coordonnées, champ hors schéma…)
        'failed' => 'échec',
    ];

    /** Champs ajoutés « virtuellement » en --dry-run pour ne pas signaler de faux champs hors schéma. */
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
        $this->addArgument('site', InputArgument::REQUIRED, 'Slug du site cible')
            ->addArgument('file', InputArgument::REQUIRED, 'Export JSON / NDJSON')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Contrôle sans écriture')
            ->addOption('create-fields', null, InputOption::VALUE_NONE, 'Crée en champs texte les SUPPLEMENTARY_FIELDS inconnus du site')
            ->addOption('files-dir', null, InputOption::VALUE_REQUIRED, 'Répertoire des fichiers de l\'ancienne application (recherche par nom)')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Statut de tous les jeux importés', Dataset::STATUS_PUBLISHED)
            ->addOption('report', null, InputOption::VALUE_REQUIRED, 'Écrit en CSV, jeu par jeu, les champs manquants / invalides / écartés')
            ->addOption('no-group', null, InputOption::VALUE_NONE, 'Un jeu par document (pas de regroupement par titre)')
            ->addOption('truncate', null, InputOption::VALUE_NONE, 'DESTRUCTIF : vide la table des jeux de données (TRUNCATE … RESTART IDENTITY CASCADE, tous sites) avant l\'import');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $slug = (string) $input->getArgument('site');
        $dry = (bool) $input->getOption('dry-run');
        $status = (string) $input->getOption('status');

        $site = $this->findSite($slug);
        if (!$site) {
            $io->error('Site introuvable');
            return Command::FAILURE;
        }
        $skipped = 0;
        $docs = $this->readDocs((string) $input->getArgument('file'), $skipped);
        $io->writeln(\count($docs) . ' document(s) lu(s)');
        if ($skipped) {
            $io->warning($skipped . ' ligne(s) illisible(s) (JSON invalide) ignorée(s) dans le fichier d\'entrée');
        }

        if ($input->getOption('truncate')) {
            $table = $this->em()->getClassMetadata(Dataset::class)->getTableName();
            if ($dry) {
                $io->writeln(sprintf('Simulation : la table "%s" serait vidée (TRUNCATE … RESTART IDENTITY CASCADE)', $table));
            } else {
                $this->em()->getConnection()->executeStatement(sprintf('TRUNCATE "%s" RESTART IDENTITY CASCADE', $table));
                $this->em()->clear();
                $site = $this->findSite($slug) ?? $site;
                $io->writeln(sprintf('Table "%s" vidée (et les tables qui en dépendent)', $table));
            }
        }

        $groups = $this->groupDocs($docs, !$input->getOption('no-group'));
        $io->writeln(sprintf('%d jeu(x) de données après regroupement (%d document(s))', \count($groups), \count($docs)));

        if ($input->getOption('create-fields')) {
            $this->createFields($site, $groups, $io, $dry);
            $site = $this->findSite($slug) ?? $site;
        }
        $fileIndex = $input->getOption('files-dir') ? $this->indexFiles((string) $input->getOption('files-dir')) : [];

        $seq = 0;          // numéro du jeu (repris dans le rapport)
        $ok = 0;
        $withIssues = 0;
        $errors = [];
        $rows = [];        // lignes du rapport : une ligne par (jeu, champ en problème)

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
                        $payload['title'] .= sprintf(' (partie %d/%d)', $ci + 1, \count($chunks));
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
                    $errors[] = [$seq, $title, $msg . ($fallbackId ? " → jeu minimal créé (id $fallbackId), document d'origine dans reviewComment" : '')];
                    $rows[] = [
                        $seq, $fallbackId ?? '', $idLabel, $title, $ids[0] ?? '', '', '(jeu complet)',
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
            $io->writeln(sprintf('Rapport : %d ligne(s) écrite(s) dans %s', \count($rows), $path));
        }
        if ($rows) {
            $io->warning(sprintf('%d jeu(x) avec au moins un champ manquant, invalide ou écarté (détail : --report)', $withIssues));
            // Synthèse : pour chaque champ, nombre de jeux concernés
            $perField = [];
            foreach ($rows as $r) {
                $perField[$r[6]][$r[0]] = true;
            }
            uasort($perField, static fn ($a, $b) => \count($b) <=> \count($a));
            $io->table(
                ['Champ', 'Jeux concernés'],
                array_map(static fn ($k, $v) => [$k, \count($v)], array_keys(\array_slice($perField, 0, 25, true)), \array_slice($perField, 0, 25))
            );
            $io->table(
                ['#', 'Titre', 'Point', 'Champ', 'Type', 'Problème'],
                array_map(static fn ($r) => [$r[0], mb_substr((string) $r[3], 0, 40), $r[5], $r[6], $r[7], mb_substr((string) $r[8], 0, 70)], \array_slice($rows, 0, 10))
            );
        }
        if ($errors) {
            $io->warning(\count($errors) . ' jeu(x) non importé(s) correctement (erreur inattendue)');
            $io->table(['#', 'Titre', 'Erreurs'], \array_slice($errors, 0, 50));
        }
        $io->success(sprintf(
            '%d jeu(x) %s, dont %d avec des champs manquants ou invalides',
            $ok,
            $dry ? 'importables (simulation)' : 'importés',
            $withIssues
        ));
        // Ligne lue par migrate_all.sh pour le récapitulatif (drafts conservé à 0 pour compatibilité)
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
    // Doctrine : le gestionnaire est repris à chaque fois (il est fermé après une erreur SQL)
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

    /** Rouvre un gestionnaire fermé par une erreur SQL et recharge le site. */
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
    * Dernier filet : si le mapping normal échoue, crée un jeu minimal et conserve les sections
    * récupérables dans properties, sans recopier le document V1 complet dans reviewComment.
    */

    private function persistFallback(Site $site, array $chunk, string $reason, string $status): mixed
    {
        $d = new Dataset($site, null);
        $d->setTitle((string) ($chunk[0]['INTRO']['TITLE'] ?? ''));
        $d->setStatus($status);
        $d->setReviewComment(mb_substr("Échec de la reprise automatique : $reason", 0, 20000));

        // Preserve recoverable sections, but never embed the complete V1 document in reviewComment.
        $properties = ['source_document_ids' => [], 'source_samples' => [], 'source_files' => []];
        foreach ($chunk as $sourceDoc) {
            $sourceId = (string) ($sourceDoc['_id'] ?? '');
            if ($sourceId !== '') {
                $properties['source_document_ids'][] = $sourceId;
            }
            $data = is_array($sourceDoc['DATA'] ?? null) ? $sourceDoc['DATA'] : [];
            if (array_key_exists('SAMPLES', $data)) {
                $properties['source_samples'][] = ['source_id' => $sourceId, 'samples' => $data['SAMPLES']];
            }
            if (array_key_exists('FILES', $data)) {
                $properties['source_files'][] = ['source_id' => $sourceId, 'files' => $data['FILES']];
            }
            foreach ($data as $key => $value) {
                if (!in_array($key, ['SAMPLES', 'FILES'], true)) {
                    $properties['source_data_' . $this->key((string) $key)][] = ['source_id' => $sourceId, 'value' => $value];
                }
            }
            $intro = is_array($sourceDoc['INTRO'] ?? null) ? $sourceDoc['INTRO'] : [];
            foreach ($intro as $key => $value) {
                $properties['source_intro_' . $this->key((string) $key)][] = ['source_id' => $sourceId, 'value' => $value];
            }
            foreach ($sourceDoc as $rootKey => $rootValue) {
                if (!in_array($rootKey, ['_id', '_source', 'INTRO', 'DATA'], true) && $rootValue !== null && $rootValue !== '' && $rootValue !== []) {
                    $properties['source_root_' . $this->key((string) $rootKey)][] = ['source_id' => $sourceId, 'value' => $rootValue];
                }
            }
        }
        $d->setProperties($properties);

        $em = $this->em();
        $em->persist($d);
        $em->flush();
        return $d->getId();
    }

    // --------------------------------------------------------------------------------------
    // Fichiers joints
    // --------------------------------------------------------------------------------------

    /** @return array<string, array{kind:string,message:string,original:?string}> problèmes rencontrés */
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
                    $issues["files.$name"] = ['kind' => 'missing', 'message' => "Fichier « $name » non attaché : --files-dir non fourni", 'original' => $url];
                    continue;
                }
                $path = $fileIndex[$name] ?? $fileIndex[mb_strtolower($name)] ?? null;
                if (!$path) {
                    $issues["files.$name"] = ['kind' => 'missing', 'message' => "Fichier « $name » introuvable dans --files-dir", 'original' => $url];
                    continue;
                }
                $tmp = sys_get_temp_dir() . '/' . uniqid('imp_', true) . '_' . $name;
                try {
                    if (!copy($path, $tmp)) {
                        throw new \RuntimeException('copie impossible');
                    }
                    $this->storage->store($d, new UploadedFile($tmp, $name, null, null, true));
                } catch (\Throwable $e) {
                    $issues["files.$name"] = ['kind' => 'failed', 'message' => "Fichier « $name » non attaché : " . $e->getMessage(), 'original' => $url];
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
    // Rapport
    // --------------------------------------------------------------------------------------

    /**
     * Transforme les problèmes du writer en lignes lisibles :
     * [champ, document d'origine, point, type, message, valeur d'origine]
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
                'Champ conservé dans les propriétés JSONB du jeu ; --create-fields permet aussi de le rendre configurable', $u['value'],
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
        fwrite($fp, "\xEF\xBB\xBF"); // BOM UTF-8 pour Excel
        fputcsv($fp, self::REPORT_HEADER, ';', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($fp, $row, ';', '"', '\\');
        }
        fclose($fp);
    }

    // --------------------------------------------------------------------------------------
    // Lecture / regroupement
    // --------------------------------------------------------------------------------------

    private function readDocs(string $file, int &$skipped): array
    {
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new \RuntimeException("Fichier illisible : $file");
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
                $docs = [$json]; // un seul document
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
     * Regroupe les documents de même titre + description + auteurs (ordre d'apparition conservé).
     * Un document sans titre reste seul.
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
     * Découpe un groupe en jeux de DatasetWriter::MAX_POINTS points maximum
     * (on compte les points, pas les documents : un document peut en contenir plusieurs).
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

    /** Payload d'un jeu : métadonnées du premier document, points de tous les documents du groupe. */
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
            $payload['description'] ??= $p['description'];
        }
        $payload['keywords'] = array_values(array_unique($keywords));
        $payload['authors'] = array_values($authors);
        // La période est déduite des dates des points par le writer.
        $payload['temporalStart'] = null;
        return $payload;
    }

    /** Un tableau de valeurs devient un texte ; les scalaires sont inchangés. */
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

        // Mots-clés : uniquement ceux de la source (aucun repli, rien n'est deviné).
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

        // Champs supplémentaires : niveau jeu ou niveau point selon la définition du champ.
        $props = [];
        $pointProps = [];

        // Keep identifiers and source sections that have no dedicated V2 entity/column.
        // This stores individual metadata sections, not a copy of the complete V1 document.
        $props['source_document_ids'] = $docId !== '' ? [$docId] : [];
        $data = is_array($doc['DATA'] ?? null) ? $doc['DATA'] : [];
        if (array_key_exists('SAMPLES', $data)) {
            $props['source_samples'] = [['source_id' => $docId, 'samples' => $data['SAMPLES']]];
        }
        if (array_key_exists('FILES', $data)) {
            // Keep original file descriptors/URLs even if --files-dir is not supplied.
            $props['source_files'] = [['source_id' => $docId, 'files' => $data['FILES']]];
        }
        foreach ($data as $dataKey => $dataValue) {
            if (!in_array($dataKey, ['SAMPLES', 'FILES'], true)) {
                $props['source_data_' . $this->key((string) $dataKey)] = [['source_id' => $docId, 'value' => $dataValue]];
            }
        }

        // Preserve root-level export metadata without storing the complete source document.
        foreach ($doc as $rootKey => $rootValue) {
            if (in_array($rootKey, ['_id', '_source', 'INTRO', 'DATA'], true) || $rootValue === null || $rootValue === '' || $rootValue === []) {
                continue;
            }
            $props['source_root_' . $this->key((string) $rootKey)] = $rootValue;
        }

        $sf = (array) ($in['SUPPLEMENTARY_FIELDS'] ?? []);
        if (array_key_exists('SUPPLEMENTARY_FIELDS', $in)) {
            $props['source_supplementary_fields'] = [['source_id' => $docId, 'fields' => $sf]];
        }

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

        // Per-sample values go on the point, so each ladder entry keeps its own
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
            $props['source_measurements'] = [['source_id' => $docId, 'measurements' => $measurements]];

            if (isset($fields['measurements'])) {
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
        if (array_key_exists('SAMPLING_DATE', $in)) {
            $props['source_sampling_dates'] = [['source_id' => $docId, 'value' => $in['SAMPLING_DATE']]];
        }
        if (array_key_exists('SAMPLING_POINT', $in)) {
            $props['source_sampling_points'] = [['source_id' => $docId, 'points' => $in['SAMPLING_POINT']]];
        }

        $points = [];
        foreach ((array) ($in['SAMPLING_POINT'] ?? []) as $p) {
            if (!\is_array($p)) {
                $p = ['NAME' => \is_scalar($p) ? (string) $p : null]; // sera signalé : coordonnées absentes
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
                // valeur d'origine conservée si elle n'est pas numérique (le writer la signale)
                'longitude' => $lon ?? $rawLon, 'latitude' => $lat ?? $rawLat,
                'elevation' => $this->num($rawElev) ?? $rawElev,
                'observedAt' => $date,
                'properties' => $thisPointProps,
                '_src' => $docId,
                '_crs' => $p['COORDINATE_SYSTEM'] ?? null,
            ] + $src;
        }

        // Langue : FRENCH/ENGLISH et codes ISO sont convertis ; toute autre valeur est transmise
        // telle quelle (le writer la signale avec sa valeur d'origine au lieu de la perdre).
        $langRaw = trim((string) ($in['LANGUAGE'] ?? ''));
        $language = null;
        if ($langRaw !== '') {
            $language = ['FRENCH' => 'fr', 'ENGLISH' => 'en'][mb_strtoupper($langRaw)]
                ?? (preg_match('/^[A-Za-z]{2,3}$/', $langRaw) ? mb_strtolower($langRaw) : $langRaw);
        }

        // Droit d'accès : absent → null (le writer le signale), sinon open / restricted.
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
     * Crée les champs manquants. Un champ dont la valeur varie à l'intérieur d'un groupe
     * (ou qui n'est pas renseigné pour tous ses documents) est créé au niveau du point ;
     * les champs d'une reprise précédente sont promus au niveau du point si besoin.
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
        foreach ($defs as &$def) {
            if (($def['group'] ?? null) === self::IMPORT_GROUP && ($def['scope'] ?? 'dataset') === 'dataset' && isset($varies[$def['key']])) {
                $def['scope'] = 'point';
                $promoted[] = $def['key'];
            }
        }
        unset($def);

        if (!$new && !$promoted) {
            return;
        }
        if ($new) {
            $io->writeln('Champs créés : ' . implode(', ', array_keys($new)));
        }
        if ($promoted) {
            $io->writeln('Champs passés au niveau du point : ' . implode(', ', $promoted));
        }
        $point = array_keys(array_filter($new, static fn ($f) => $f['scope'] === 'point'));
        if ($point) {
            $io->writeln('Dont champs « point » (valeurs différentes selon l\'échantillon) : ' . implode(', ', $point));
        }
        if ($dry) {
            // Simulation : on fait comme si les champs existaient pour ne pas signaler de faux « hors schéma ».
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

    /** Clé de champ à partir du nom d'origine. Une clé réservée est préfixée (« src_ ») au lieu d'être perdue. */
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