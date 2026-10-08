<?php

namespace App\Controller;

use App\Entity\Site;
use App\Entity\SiteMembership;
use App\Exception\ApiException;
use App\Repository\SiteRepository;
use App\Repository\UserRepository;
use App\Service\FederationService;
use App\Service\FieldSchema;
use App\Service\FileStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET    /api/context                 site résolu (hôte / X-Site), mode de fédération, utilisateur
 * GET    /api/sites                   portail : sites publiés (+ sites dont on est membre)
 * GET    /api/sites/{slug}            configuration publique d'un site
 * PUT    /api/sites/{slug}            mise à jour (responsable ; hôtes, slug et publication : admin)
 * POST   /api/sites/{slug}/logo       logo (responsable)
 * GET    /api/sites/{slug}/logo
 * POST   /api/admin/sites             création (admin)
 * DELETE /api/admin/sites/{slug}      suppression (admin)
 */
final class SiteController extends ApiController
{
    public function __construct(
        private SiteRepository $siteRepo,
        private UserRepository $users,
        private EntityManagerInterface $em,
        private FieldSchema $schema,
        private FederationService $federation,
        private FileStorage $storage,
    ) {}

    #[Route('/api/context', methods: ['GET'])]
    public function context(Request $request): JsonResponse
    {
        $site = $this->sites->fromRequest($request);
        if ($site && !$this->perm->canViewSite($site)) {
            $site = null;
        }
        $user = $this->perm->user();
        return $this->json([
            'site' => $site ? $this->presenter->site($site) : null,
            'boundByHost' => $site !== null && \in_array(mb_strtolower($request->getHost()), $site->getHostnames(), true),
            'federation' => ['mode' => $this->federation->mode(), 'loginUrl' => $this->federation->loginUrl()],
            'user' => $user ? $this->presenter->user($user, true) : null,
        ]);
    }

    #[Route('/api/sites', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $all = $this->siteRepo->findBy([], ['title' => 'ASC']);
        $visible = array_filter($all, fn (Site $s) => $this->perm->canViewSite($s));
        $db = $this->em->getConnection();
        $counts = [];
        foreach ($db->fetchAllAssociative("SELECT site_id, count(*) AS n FROM dataset WHERE status = 'published' GROUP BY site_id") as $r) {
            $counts[(int) $r['site_id']] = (int) $r['n'];
        }
        return $this->json(array_values(array_map(fn (Site $s) => $this->presenter->siteSummary($s) + [
            'description' => $s->getDescription(),
            'datasets' => $counts[$s->getId()] ?? 0,
            'canManage' => $this->perm->canManageSite($s),
        ], $visible)));
    }

    #[Route('/api/sites/{slug}', methods: ['GET'])]
    public function show(string $slug): JsonResponse
    {
        return $this->json($this->presenter->site($this->site($slug)));
    }

    #[Route('/api/sites/{slug}', methods: ['PUT', 'PATCH'])]
    public function update(string $slug, Request $request): JsonResponse
    {
        $site = $this->site($slug);
        $this->perm->denyUnless($this->perm->canManageSite($site));
        $this->applyConfig($site, $this->body($request), $this->perm->isAdmin());
        $this->em->flush();
        return $this->json($this->presenter->site($site));
    }

    #[Route('/api/admin/sites', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $d = $this->body($request);
        $slug = mb_strtolower(trim((string) ($d['slug'] ?? '')));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $slug)) {
            throw ApiException::validation(['slug' => 'Identifiant invalide (minuscules, chiffres, tirets)']);
        }
        if ($this->siteRepo->findOneBy(['slug' => $slug])) {
            throw ApiException::validation(['slug' => 'Identifiant déjà utilisé']);
        }
        $site = new Site($slug, trim((string) ($d['title'] ?? $slug)) ?: $slug);
        $this->applyConfig($site, $d, true);
        $this->em->persist($site);

        // Responsable initial facultatif
        if (!empty($d['managerEmail'])) {
            $email = mb_strtolower(trim((string) $d['managerEmail']));
            $u = $this->users->findOneByEmail($email) ?? new \App\Entity\User($email);
            $this->em->persist($u);
            $this->em->persist(new SiteMembership($site, $u, SiteMembership::ROLE_MANAGER));
        }
        $this->em->flush();
        return $this->json($this->presenter->site($site), 201);
    }

    #[Route('/api/admin/sites/{slug}', methods: ['DELETE'])]
    public function delete(string $slug): JsonResponse
    {
        $site = $this->sites->bySlug($slug);
        $n = (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM dataset WHERE site_id = ?', [$site->getId()]);
        if ($n > 0) {
            throw new ApiException("Le site contient $n jeu(x) de données : supprimez-les ou dépubliez le site", 409);
        }
        $this->em->remove($site);
        $this->em->flush();
        return $this->json(['ok' => true]);
    }

    #[Route('/api/sites/{slug}/logo', methods: ['POST'])]
    public function uploadLogo(string $slug, Request $request): JsonResponse
    {
        $site = $this->site($slug);
        $this->perm->denyUnless($this->perm->canManageSite($site));
        $file = $request->files->get('file');
        if (!$file || !\in_array($file->getMimeType(), ['image/png', 'image/jpeg', 'image/webp'], true) || $file->getSize() > 2_000_000) {
            throw new ApiException('Logo PNG, JPEG ou WebP de 2 Mo maximum attendu');
        }
        $dir = $this->storage->logoDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $name = $site->getSlug() . '-' . bin2hex(random_bytes(4)) . '.' . $file->guessExtension();
        if ($site->getLogoPath()) {
            @unlink($dir . '/' . $site->getLogoPath());
        }
        $file->move($dir, $name);
        $site->setLogoPath($name);
        $site->touch();
        $this->em->flush();
        return $this->json($this->presenter->site($site));
    }

    #[Route('/api/sites/{slug}/logo', methods: ['GET'])]
    public function logo(string $slug): Response
    {
        $site = $this->site($slug);
        $path = $site->getLogoPath() ? $this->storage->logoDir() . '/' . $site->getLogoPath() : null;
        if (!$path || !is_file($path)) {
            throw ApiException::notFound();
        }
        $resp = new BinaryFileResponse($path);
        $resp->setPublic();
        $resp->setMaxAge(86400);
        return $resp;
    }

    private function applyConfig(Site $site, array $d, bool $admin): void
    {
        $errors = [];
        if (isset($d['title'])) {
            $t = trim((string) $d['title']);
            $t === '' ? $errors['title'] = 'Titre obligatoire' : $site->setTitle(mb_substr($t, 0, 255));
        }
        foreach (['subtitle', 'description', 'about'] as $k) {
            if (\array_key_exists($k, $d)) {
                $v = trim((string) $d[$k]) ?: null;
                $site->{'set' . ucfirst($k)}($k === 'subtitle' && $v ? mb_substr($v, 0, 255) : $v);
            }
        }
        if (isset($d['theme']) && \is_array($d['theme'])) {
            $theme = [];
            foreach (['primary', 'secondary', 'accent'] as $k) {
                $c = $d['theme'][$k] ?? null;
                if ($c !== null && !preg_match('/^#[0-9a-fA-F]{6}$/', (string) $c)) {
                    $errors["theme.$k"] = 'Couleur #RRGGBB attendue';
                } elseif ($c) {
                    $theme[$k] = $c;
                }
            }
            $site->setTheme($theme);
        }
        if (isset($d['map']) && \is_array($d['map'])) {
            $m = $d['map'];
            $center = $m['center'] ?? null;
            if (!\is_array($center) || \count($center) !== 2 || !is_numeric($center[0]) || !is_numeric($center[1])) {
                $errors['map.center'] = 'Centre [lat, lon] attendu';
            } else {
                $site->setMap([
                    'center' => [(float) $center[0], (float) $center[1]],
                    'zoom' => max(1, min(18, (int) ($m['zoom'] ?? 6))),
                    'basemaps' => array_values(array_filter((array) ($m['basemaps'] ?? []), 'is_string')) ?: Site::DEFAULT_MAP['basemaps'],
                    'defaultBasemap' => (string) ($m['defaultBasemap'] ?? 'osm'),
                    'clusterMaxZoom' => max(5, min(20, (int) ($m['clusterMaxZoom'] ?? 14))),
                    'colorBy' => (string) ($m['colorBy'] ?? 'none'),
                ]);
            }
        }
        if (isset($d['fields']) && \is_array($d['fields'])) {
            try {
                $site->setFields($this->schema->normalizeDefinitions($d['fields']));
            } catch (ApiException $e) {
                $errors += $e->getDetails();
            }
        }
        if (isset($d['importTemplate']) && \is_array($d['importTemplate'])) {
            $cols = [];
            foreach ((array) ($d['importTemplate']['columns'] ?? []) as $k => $c) {
                if (\is_array($c) && preg_match('/^[a-z][a-z0-9_]*$/', (string) $k)) {
                    $cols[$k] = [
                        'include' => (bool) ($c['include'] ?? true),
                        'label' => mb_substr(trim((string) ($c['label'] ?? '')), 0, 120) ?: null,
                        'example' => mb_substr(trim((string) ($c['example'] ?? '')), 0, 255) ?: null,
                    ];
                }
            }
            $site->setImportTemplate([
                'columns' => $cols,
                'instructions' => trim((string) ($d['importTemplate']['instructions'] ?? '')) ?: null,
            ]);
        }
        if (isset($d['settings']) && \is_array($d['settings'])) {
            $s = $site->getSettings();
            $in = $d['settings'];
            foreach (['moderation', 'requireKeywords', 'allowJoinRequests'] as $k) {
                if (\array_key_exists($k, $in)) { $s[$k] = (bool) $in[$k]; }
            }
            if (isset($in['defaultAccessRight'])) {
                $s['defaultAccessRight'] = $in['defaultAccessRight'] === 'restricted' ? 'restricted' : 'open';
            }
            foreach (['defaultLicense', 'verticalDatum', 'contactEmail', 'ordarUrl'] as $k) {
                if (\array_key_exists($k, $in)) { $s[$k] = trim((string) $in[$k]) ?: null; }
            }
            if ($s['contactEmail'] && !filter_var($s['contactEmail'], FILTER_VALIDATE_EMAIL)) {
                $errors['settings.contactEmail'] = 'E-mail invalide';
            }
            $site->setSettings($s);
        }
        if ($admin) {
            if (isset($d['hostnames'])) {
                $hosts = \is_array($d['hostnames']) ? $d['hostnames'] : preg_split('/[\s,]+/', (string) $d['hostnames']);
                foreach ($hosts as $h) {
                    $h = mb_strtolower(trim((string) $h));
                    if ($h === '') { continue; }
                    if (!preg_match('/^[a-z0-9.-]+(:\d+)?$/', $h)) {
                        $errors['hostnames'] = "Nom d'hôte invalide : $h";
                    }
                    $other = $this->siteRepo->findOneByHost($h);
                    if ($other && $other->getId() !== $site->getId()) {
                        $errors['hostnames'] = "$h est déjà utilisé par le site {$other->getSlug()}";
                    }
                }
                $site->setHostnames($hosts);
            }
            if (\array_key_exists('published', $d)) {
                $site->setPublished((bool) $d['published']);
            }
        }
        if ($errors) {
            throw ApiException::validation($errors);
        }
    }
}
