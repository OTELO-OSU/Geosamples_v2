<?php

namespace App\Command;

use App\Entity\Dataset;
use App\Entity\SamplingPoint;
use App\Entity\Site;
use App\Entity\SiteMembership;
use App\Entity\User;
use App\Repository\SiteRepository;
use App\Repository\UserRepository;
use App\Service\FieldSchema;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Load two demo sites and FICTIONAL data for trying the application:
 * "nometal" (many individual geological samples, clustered server-side) and
 * "orne" (monitoring stations with time series).
 * Fields are examples to adapt to actual project content.
 */
#[AsCommand(name: 'app:demo:load', description: 'Load demo sites and data (fictional)')]
final class LoadDemoCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private SiteRepository $sites,
        private UserRepository $users,
        private UserPasswordHasherInterface $hasher,
        private FieldSchema $schema,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('password', null, InputOption::VALUE_REQUIRED, 'Password for demo accounts', 'demo-geosamples')
            ->addOption('samples', null, InputOption::VALUE_REQUIRED, 'Number of nometal samples', '1800');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        mt_srand(42);
        $pwd = (string) $input->getOption('password');

        $admin = $this->user('admin@example.org', 'Admin', 'GeoSamples', $pwd, true);
        $respN = $this->user('manager.nometal@example.org', 'Claire', 'Martin', $pwd);
        $respO = $this->user('manager.orne@example.org', 'Hugo', 'Bernard', $pwd);
        $contrib = $this->user('contributor@example.org', 'Lea', 'Petit', $pwd);

        $nometal = $this->site('nometal', 'NOMETAL', 'Geological and geochemical samples',
            "Map of samples collected as part of the project. **Fictional demo data.**\n\nClick a point to view the dataset.",
            ['nometal.otelo.univ-lorraine.fr', 'nometal.localhost'],
            ['primary' => '#7A4E2D', 'secondary' => '#3B3A36', 'accent' => '#C9822B'],
            ['center' => [48.45, 6.4], 'zoom' => 8, 'basemaps' => ['osm', 'opentopo', 'ign-ortho', 'esri-imagery'], 'defaultBasemap' => 'opentopo', 'clusterMaxZoom' => 13, 'colorBy' => 'lithology'],
            [
                ['key' => 'sample_type', 'label' => 'Sample type', 'type' => 'select', 'options' => ['Core', 'Block', 'Pulp', 'Powder', 'Thin section'], 'required' => true, 'filter' => 'select', 'popup' => true, 'group' => 'Sample'],
                ['key' => 'lithology', 'label' => 'Lithology', 'type' => 'select', 'options' => ['Granite', 'Sandstone', 'Limestone', 'Argillite', 'Basalt', 'Schist', 'Laterite'], 'filter' => 'select', 'popup' => true, 'group' => 'Geology'],
                ['key' => 'ore_type', 'label' => 'Mineralization type', 'type' => 'multiselect', 'options' => ['Sulfides', 'Oxides', 'Silicates', 'Carbonates', 'None'], 'filter' => 'select', 'group' => 'Geology'],
                ['key' => 'alteration', 'label' => 'Degree of alteration', 'type' => 'select', 'options' => ['Fresh', 'Weak', 'Moderate', 'Strong'], 'filter' => 'select', 'group' => 'Geology'],
                ['key' => 'host_age', 'label' => 'Host rock age', 'type' => 'text', 'group' => 'Geology'],
                ['key' => 'ni_ppm', 'label' => 'Nickel', 'unit' => 'ppm', 'type' => 'number', 'filter' => 'range', 'popup' => true, 'group' => 'Mesures'],
                ['key' => 'co_ppm', 'label' => 'Cobalt', 'unit' => 'ppm', 'type' => 'number', 'filter' => 'range', 'group' => 'Mesures'],
                ['key' => 'method', 'label' => 'Analysis method', 'type' => 'select', 'options' => ['ICP-MS', 'ICP-OES', 'XRF', 'LA-ICP-MS', 'EMPA'], 'filter' => 'select', 'group' => 'Measurements'],
                ['key' => 'storage', 'label' => 'Storage location', 'type' => 'text', 'group' => 'Sample', 'help' => 'Core library, laboratory...'],
                ['key' => 'core_depth', 'label' => 'Core depth', 'unit' => 'm', 'type' => 'number', 'scope' => 'point', 'group' => 'Sample'],
            ],
            ['moderation' => true, 'verticalDatum' => 'NGF-IGN69', 'contactEmail' => 'contact-nometal@example.org']
        );
        $orne = $this->site('orne', 'ORNE', 'Water quality monitoring in the Orne basin',
            "Sampling stations and time series. **Fictional demo data.**\n\nUse the timeline to filter by period.",
            ['orne.otelo.univ-lorraine.fr', 'orne.localhost'],
            ['primary' => '#0F5E7A', 'secondary' => '#28434F', 'accent' => '#2BA39A'],
            ['center' => [49.22, 5.95], 'zoom' => 10, 'basemaps' => ['osm', 'ign-plan', 'ign-ortho', 'opentopo'], 'defaultBasemap' => 'ign-plan', 'clusterMaxZoom' => 14, 'colorBy' => 'year'],
            [
                ['key' => 'station_type', 'label' => 'Station type', 'type' => 'select', 'options' => ['River', 'Tributary', 'Piezometer', 'Outfall'], 'required' => true, 'filter' => 'select', 'popup' => true],
                ['key' => 'water_body', 'label' => 'Water body', 'type' => 'select', 'options' => ['Upper Orne', 'Lower Orne', 'Woigot', 'Conroy', 'Yron'], 'filter' => 'select', 'popup' => true],
                ['key' => 'monitoring', 'label' => 'Monitoring parameters', 'type' => 'multiselect', 'options' => ['Physicochemical', 'Metals', 'Nutrients', 'Hydrobiology', 'Sediments'], 'filter' => 'select'],
                ['key' => 'temperature', 'label' => 'Temperature', 'unit' => '°C', 'type' => 'number', 'scope' => 'point', 'filter' => 'range'],
                ['key' => 'ph', 'label' => 'pH', 'type' => 'number', 'scope' => 'point', 'filter' => 'range'],
                ['key' => 'conductivity', 'label' => 'Conductivity', 'unit' => 'µS/cm', 'type' => 'number', 'scope' => 'point', 'filter' => 'range'],
                ['key' => 'nitrates', 'label' => 'Nitrates', 'unit' => 'mg/L', 'type' => 'number', 'scope' => 'point'],
                ['key' => 'operator', 'label' => 'Operator', 'type' => 'text'],
            ],
            ['moderation' => false, 'verticalDatum' => 'NGF-IGN69', 'contactEmail' => 'contact-orne@example.org']
        );
        $this->em->flush();

        $this->member($nometal, $respN, SiteMembership::ROLE_MANAGER);
        $this->member($nometal, $contrib, SiteMembership::ROLE_CONTRIBUTOR);
        $this->member($orne, $respO, SiteMembership::ROLE_MANAGER);
        $this->member($orne, $contrib, SiteMembership::ROLE_CONTRIBUTOR);
        $this->em->flush();

        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM dataset WHERE site_id IN (?, ?)', [$nometal->getId(), $orne->getId()]);

        $n = $this->loadNometal($nometal, [$respN, $contrib], (int) $input->getOption('samples'));
        $this->em->clear();
        $o = $this->loadOrne($orne, [$respO, $contrib]);

        $io->success("Demo loaded: $n datasets on nometal, $o stations on orne.");
        $io->table(['Account', 'Role'], [
            ['admin@example.org', 'administrator'],
            ['manager.nometal@example.org', 'nometal manager'],
            ['manager.orne@example.org', 'orne manager'],
            ['contributor@example.org', 'contributor (nometal, orne)'],
        ]);
        $io->writeln("Password: $pwd");
        return Command::SUCCESS;
    }

    private function loadNometal(Site $site, array $owners, int $count): int
    {
        $lith = ['Granite', 'Sandstone', 'Limestone', 'Argillite', 'Basalt', 'Schist', 'Laterite'];
        $types = ['Core', 'Block', 'Pulp', 'Powder', 'Thin section'];
        $ores = ['Sulfides', 'Oxides', 'Silicates', 'Carbonates', 'None'];
        $alt = ['Fresh', 'Weak', 'Moderate', 'Strong'];
        $methods = ['ICP-MS', 'ICP-OES', 'XRF', 'LA-ICP-MS', 'EMPA'];
        // A few sampling areas (fictional centers in Lorraine / Vosges).
        $areas = [[48.07, 6.88, 0.12, 650], [48.30, 7.05, 0.10, 800], [48.69, 6.18, 0.15, 230], [49.10, 6.10, 0.12, 200], [48.55, 5.75, 0.2, 350], [47.95, 6.55, 0.08, 900]];
        $statuses = array_merge(array_fill(0, 18, Dataset::STATUS_PUBLISHED), [Dataset::STATUS_SUBMITTED, Dataset::STATUS_DRAFT]);
        for ($i = 1; $i <= $count; $i++) {
            $a = $areas[$i % \count($areas)];
            $lat = $a[0] + $this->gauss() * $a[2];
            $lon = $a[1] + $this->gauss() * $a[2] * 1.4;
            $l = $lith[mt_rand(0, \count($lith) - 1)];
            $type = $types[mt_rand(0, \count($types) - 1)];
            $date = (new \DateTimeImmutable('2014-03-01'))->modify('+' . mt_rand(0, 3800) . ' days');
            $d = new Dataset($site, $owners[$i % 2]);
            $d->setTitle(sprintf('Sample NM-%04d — %s (%s)', $i, mb_strtolower($l), mb_strtolower($type)))
              ->setDescription("Sample of $l collected during the " . $date->format('Y') . ' campaign. Fictional data generated for the demo.')
              ->setKeywords(array_values(array_unique([$l, $type, 'geochemistry', mt_rand(0, 1) ? 'nickel' : 'cobalt'])))
              ->setAuthors([['name' => $owners[$i % 2]->getDisplayName(), 'affiliation' => 'GeoRessources']])
              ->setLicense('CC-BY-4.0')->setLanguage('en')
              ->setAccessRight(mt_rand(0, 9) ? Dataset::ACCESS_OPEN : Dataset::ACCESS_RESTRICTED)
              ->setTemporalStart($date)
              ->setProperties(array_filter([
                  'sample_type' => $type,
                  'lithology' => $l,
                  'ore_type' => [$ores[mt_rand(0, 4)]],
                  'alteration' => $alt[mt_rand(0, 3)],
                  'ni_ppm' => round(abs($this->gauss()) * ($l === 'Laterite' ? 9000 : 400) + 5, 1),
                  'co_ppm' => round(abs($this->gauss()) * 80 + 1, 1),
                  'method' => $methods[mt_rand(0, 4)],
                  'storage' => mt_rand(0, 1) ? 'OTELo core library' : null,
              ], static fn ($v) => $v !== null))
              ->setStatus($statuses[$i % \count($statuses)]);
            $p = (new SamplingPoint(round($lon, 6), round($lat, 6)))
                ->setName(sprintf('NM-%04d', $i))
                ->setElevation(round(max(150, $a[3] + $this->gauss() * 120), 1))
                ->setVerticalDatum('NGF-IGN69')
                ->setObservedAt($date);
            if ($type === 'Core') {
                $p->setProperties(['core_depth' => round(mt_rand(10, 3000) / 10, 1)]);
            }
            $d->addPoint($p);
            $this->em->persist($d);
            if ($i % 300 === 0) {
                $this->em->flush();
                $this->em->clear();
                $site = $this->em->getReference(Site::class, $site->getId());
                $owners = array_map(fn (User $u) => $this->em->getReference(User::class, $u->getId()), $owners);
            }
        }
        $this->em->flush();
        return $count;
    }

    private function loadOrne(Site $site, array $owners): int
    {
        $site = $this->em->find(Site::class, $site->getId());
        $owners = array_map(fn (User $u) => $this->em->find(User::class, $u->getId()), $owners);
        // Approximate course of the Orne (upstream → confluence with the Moselle) — fictional.
        $river = [[49.105, 5.645, 225], [49.140, 5.720, 210], [49.170, 5.800, 200], [49.200, 5.870, 190], [49.230, 5.930, 180],
                  [49.245, 5.990, 175], [49.255, 6.050, 170], [49.265, 6.110, 165], [49.270, 6.160, 160], [49.285, 6.195, 158]];
        $bodies = ['Upper Orne', 'Upper Orne', 'Upper Orne', 'Woigot', 'Lower Orne', 'Conroy', 'Lower Orne', 'Yron', 'Lower Orne', 'Lower Orne'];
        $n = 0;
        foreach ($river as $i => [$lat, $lon, $elev]) {
            foreach (['River', $i % 3 === 0 ? 'Piezometer' : 'Tributary'] as $k => $stype) {
                $n++;
                $slat = $lat + ($k ? 0.012 : 0);
                $slon = $lon + ($k ? -0.01 : 0);
                $d = new Dataset($site, $owners[$k]);
                $d->setTitle(sprintf('Station OR-%02d — %s (%s)', $n, $bodies[$i], mb_strtolower($stype)))
                  ->setDescription('Monthly physicochemical monitoring. Fictional data generated for the demo.')
                  ->setKeywords(['water quality', 'Orne', $stype === 'Piezometer' ? 'groundwater' : 'surface water'])
                  ->setAuthors([['name' => $owners[$k]->getDisplayName(), 'affiliation' => 'LIEC']])
                  ->setLicense('CC-BY-4.0')->setLanguage('en')
                  ->setProperties(['station_type' => $stype, 'water_body' => $bodies[$i], 'monitoring' => ['Physicochemical', $k ? 'Nutrients' : 'Metals'], 'operator' => 'ORNE team'])
                  ->setStatus(Dataset::STATUS_PUBLISHED);
                $start = new \DateTimeImmutable('2019-01-15T10:00:00+00:00');
                $months = 24 + ($n % 4) * 12;
                for ($m = 0; $m < $months; $m++) {
                    $t = $start->modify("+$m months")->modify('+' . mt_rand(0, 6) . ' days');
                    $season = sin(($m % 12) / 12 * 2 * M_PI - M_PI / 2);
                    $p = (new SamplingPoint($slon, $slat))
                        ->setName(sprintf('OR-%02d', $n))
                        ->setElevation($stype === 'Piezometer' ? round($elev - 8 - mt_rand(0, 40) / 10, 1) : (float) $elev)
                        ->setVerticalDatum('NGF-IGN69')
                        ->setObservedAt($t)
                        ->setPosition($m)
                        ->setProperties([
                            'temperature' => round(11 + 7 * $season + $this->gauss(), 1),
                            'ph' => round(7.6 + $this->gauss() * 0.25, 2),
                            'conductivity' => round(620 + $i * 25 + $this->gauss() * 60),
                            'nitrates' => round(max(0.5, 14 - 5 * $season + $this->gauss() * 2), 1),
                        ]);
                    $d->addPoint($p);
                }
                $d->setTemporalStart($d->getPoints()->first()->getObservedAt())->setTemporalEnd($d->getPoints()->last()->getObservedAt());
                $this->em->persist($d);
            }
        }
        $this->em->flush();
        return $n;
    }

    private function user(string $email, string $first, string $last, string $pwd, bool $admin = false): User
    {
        $u = $this->users->findOneByEmail($email) ?? new User($email);
        $u->setFirstname($first)->setLastname($last)->setAdmin($admin)->setActive(true);
        $u->setPassword($this->hasher->hashPassword($u, $pwd));
        $this->em->persist($u);
        $this->em->flush();
        return $u;
    }

    private function site(string $slug, string $title, string $subtitle, string $desc, array $hosts, array $theme, array $map, array $fields, array $settings): Site
    {
        $s = $this->sites->findOneBy(['slug' => $slug]) ?? new Site($slug, $title);
        $s->setTitle($title)->setSubtitle($subtitle)->setDescription($desc)->setHostnames($hosts)->setTheme($theme)
          ->setMap(array_replace(Site::DEFAULT_MAP, $map))->setFields($this->schema->normalizeDefinitions($fields))
          ->setSettings(array_replace(Site::DEFAULT_SETTINGS, $settings))
          ->setAbout("## About\n\nThis data management site is a **demo** of GeoSamples v2. The datasets shown here are fictional.\n\nOnce published in the [ORDaR](https://ordar.otelo.univ-lorraine.fr) repository, datasets display their DOI.")
          ->setPublished(true);
        $this->em->persist($s);
        return $s;
    }

    private function member(Site $s, User $u, string $role): void
    {
        $m = $this->em->getRepository(SiteMembership::class)->findOneBy(['site' => $s, 'user' => $u]);
        if (!$m) {
            $this->em->persist(new SiteMembership($s, $u, $role));
        } else {
            $m->setRole($role)->setStatus(SiteMembership::STATUS_ACTIVE);
        }
    }

    private function gauss(): float
    {
        $u = max(1e-9, mt_rand() / mt_getrandmax());
        $v = mt_rand() / mt_getrandmax();
        return sqrt(-2 * log($u)) * cos(2 * M_PI * $v);
    }
}
