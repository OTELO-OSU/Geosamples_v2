<?php

namespace App\Service;

use App\Entity\Site;
use App\Exception\ApiException;
use App\Repository\SiteRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Determine the current site:
 *  1. {slug} route parameter (explicit API /api/sites/{slug}/…)
 *  2. X-Site header (frontend served at /s/{slug}/ in development or on the portal)
 *  3. request hostname (nometal.otelo.univ-lorraine.fr → site "nometal")
 *  4. DEFAULT_SITE
 */
final class SiteResolver
{
    public function __construct(private SiteRepository $sites, private string $defaultSite) {}

    public function fromRequest(Request $request): ?Site
    {
        $header = trim((string) $request->headers->get('X-Site', ''));
        if ($header !== '') {
            return $this->sites->findOneBy(['slug' => $header]);
        }
        $site = $this->sites->findOneByHost($request->getHost());
        if ($site) {
            return $site;
        }
        return $this->defaultSite !== '' ? $this->sites->findOneBy(['slug' => $this->defaultSite]) : null;
    }

    /** Site identified by slug; unpublished sites are only visible to their members (checked by the caller). */
    public function bySlug(string $slug): Site
    {
        $site = $this->sites->findOneBy(['slug' => $slug]);
        if (!$site) {
            throw ApiException::notFound('Site not found');
        }
        return $site;
    }
}
