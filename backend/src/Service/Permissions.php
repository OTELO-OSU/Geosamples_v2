<?php

namespace App\Service;

use App\Entity\Dataset;
use App\Entity\Site;
use App\Entity\SiteMembership;
use App\Entity\User;
use App\Exception\ApiException;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Règles d'autorisation (centralisées ici pour rester lisibles et testables).
 *
 *  admin        : tout, sur tous les sites
 *  responsable  : configuration du site, membres, validation et édition de tous les jeux du site
 *  utilisateur  : dépôt / import / édition de ses propres jeux
 *  public       : lecture des jeux publiés ; fichiers des jeux en accès ouvert
 */
final class Permissions
{
    public function __construct(private Security $security) {}

    public function user(): ?User
    {
        $u = $this->security->getUser();
        return $u instanceof User ? $u : null;
    }

    public function requireUser(): User
    {
        $u = $this->user();
        if (!$u) {
            throw ApiException::unauthorized();
        }
        if (!$u->isActive()) {
            throw ApiException::forbidden('Compte désactivé');
        }
        return $u;
    }

    public function isAdmin(): bool { return (bool) $this->user()?->isAdmin(); }

    public function membership(Site $site): ?SiteMembership
    {
        return $this->user()?->getMembership($site);
    }

    public function canManageSite(Site $site): bool
    {
        return $this->isAdmin() || (bool) $this->membership($site)?->isManager();
    }

    public function canContribute(Site $site): bool
    {
        // retirer la vérification de role pour permettre aux membres non manager de créer des jeux
        return $this->isAdmin() || ( (bool) $this->membership($site)?->isActive() && (bool) $this->membership($site)?->isManager());
    }

    public function isMember(Site $site): bool { return $this->canContribute($site); }

    public function canViewSite(Site $site): bool
    {
        return $site->isPublished() || $this->canContribute($site);
    }

    public function canView(Dataset $d): bool
    {
        if ($d->isPublished() && $this->canViewSite($d->getSite())) {
            return true;
        }
        return $this->isOwner($d) || $this->canManageSite($d->getSite());
    }

    public function canEdit(Dataset $d): bool
    {
        if ($this->canManageSite($d->getSite())) {
            return true;
        }
        return $this->isOwner($d) && $this->canContribute($d->getSite());
    }

    public function canDelete(Dataset $d): bool
    {
        if ($this->canManageSite($d->getSite())) {
            return true;
        }
        return $this->isOwner($d) && !$d->isPublished();
    }

    public function canDownload(Dataset $d): bool
    {
        if (!$this->canView($d)) {
            return false;
        }
        return $d->getAccessRight() === Dataset::ACCESS_OPEN || $this->isMember($d->getSite()) || $this->isOwner($d);
    }

    public function isOwner(Dataset $d): bool
    {
        $u = $this->user();
        return $u !== null && $d->getOwner()?->getId() === $u->getId();
    }

    public function denyUnless(bool $cond, string $msg = 'Accès refusé'): void
    {
        if (!$cond) {
            throw $this->user() ? ApiException::forbidden($msg) : ApiException::unauthorized();
        }
    }
}
