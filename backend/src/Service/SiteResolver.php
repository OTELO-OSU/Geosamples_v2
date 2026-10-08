<?php

namespace App\Service;

use App\Entity\Site;
use App\Exception\ApiException;
use App\Repository\SiteRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Détermine le site courant :
 *  1. paramètre de route {slug} (API explicite /api/sites/{slug}/…)
 *  2. en-tête X-Site (front servi en /s/{slug}/ en développement ou sur le portail)
 *  3. nom d'hôte de la requête (nometal.otelo.univ-lorraine.fr → site "nometal")
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

    /** Site désigné par slug ; les sites non publiés ne sont visibles que de leurs membres (contrôlé par l'appelant). */
    public function bySlug(string $slug): Site
    {
        $site = $this->sites->findOneBy(['slug' => $slug]);
        if (!$site) {
            throw ApiException::notFound('Site introuvable');
        }
        return $site;
    }
}
