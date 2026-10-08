<?php

namespace App\Controller;

use App\Entity\Site;
use App\Exception\ApiException;
use App\Service\Permissions;
use App\Service\Presenter;
use App\Service\SiteResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Service\Attribute\Required;

/** Socle commun des contrôleurs JSON. */
abstract class ApiController extends AbstractController
{
    protected Permissions $perm;
    protected Presenter $presenter;
    protected SiteResolver $sites;

    #[Required]
    public function setBase(Permissions $perm, Presenter $presenter, SiteResolver $sites): void
    {
        $this->perm = $perm;
        $this->presenter = $presenter;
        $this->sites = $sites;
    }

    protected function body(Request $request): array
    {
        if ($request->getContent() === '') {
            return [];
        }
        try {
            $data = json_decode($request->getContent(), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiException('Corps JSON invalide');
        }
        if (!\is_array($data)) {
            throw new ApiException('Objet JSON attendu');
        }
        return $data;
    }

    /** Site visible par l'utilisateur courant (les sites non publiés sont réservés aux membres). */
    protected function site(string $slug): Site
    {
        $site = $this->sites->bySlug($slug);
        if (!$this->perm->canViewSite($site)) {
            throw ApiException::notFound('Site introuvable');
        }
        return $site;
    }
}
