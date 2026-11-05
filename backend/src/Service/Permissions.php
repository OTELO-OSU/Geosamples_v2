<?php

namespace App\Service;

use App\Entity\Dataset;
use App\Entity\Site;
use App\Entity\SiteMembership;
use App\Entity\User;
use App\Exception\ApiException;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Authorization rules (centralized here to keep them readable and testable).
 *
 *  admin        : tout, sur tous les sites
 *  manager      : site configuration, members, review, and editing all site datasets
 *  contributor  : submitting / importing / editing their own datasets
 *  public       : reading published datasets; open-access dataset files
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
            throw ApiException::forbidden('Account disabled');
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
        return $this->isAdmin() || (bool) $this->membership($site)?->isActive();
    }

    public function canCreateDataset(Site $site): bool
    {
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

    public function denyUnless(bool $cond, string $msg = 'Access denied'): void
    {
        if (!$cond) {
            throw $this->user() ? ApiException::forbidden($msg) : ApiException::unauthorized();
        }
    }
}
