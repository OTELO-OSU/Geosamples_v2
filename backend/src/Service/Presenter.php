<?php

namespace App\Service;

use App\Entity\Dataset;
use App\Entity\DatasetFile;
use App\Entity\SamplingPoint;
use App\Entity\Site;
use App\Entity\SiteMembership;
use App\Entity\User;

/** Sérialisation JSON des entités (vues publique / gestion). */
final class Presenter
{
    public function __construct(private Permissions $perm) {}

    public function siteSummary(Site $s): array
    {
        return [
            'slug' => $s->getSlug(),
            'title' => $s->getTitle(),
            'subtitle' => $s->getSubtitle(),
            'hostnames' => $s->getHostnames(),
            'logoUrl' => $s->getLogoPath() ? '/api/sites/' . $s->getSlug() . '/logo?v=' . $s->getUpdatedAt()->getTimestamp() : null,
            'theme' => $s->getTheme(),
            'published' => $s->isPublished(),
        ];
    }

    public function site(Site $s): array
    {
        $settings = $s->getSettings();
        $public = [
            'moderation' => $settings['moderation'],
            'defaultAccessRight' => $settings['defaultAccessRight'],
            'defaultLicense' => $settings['defaultLicense'],
            'verticalDatum' => $settings['verticalDatum'],
            'contactEmail' => $settings['contactEmail'],
            'ordarUrl' => $settings['ordarUrl'],
            'requireKeywords' => $settings['requireKeywords'],
            'allowJoinRequests' => $settings['allowJoinRequests'],
        ];
        $out = $this->siteSummary($s) + [
            'description' => $s->getDescription(),
            'about' => $s->getAbout(),
            'map' => $s->getMap(),
            'fields' => $s->getFields(),
            'importTemplate' => $s->getImportTemplate(),
            'settings' => $public,
        ];
        $m = $this->perm->membership($s);
        $out['me'] = [
            'role' => $this->perm->isAdmin() ? 'admin' : ($m?->isActive() ? $m->getRole() : null),
            'pending' => $m !== null && !$m->isActive(),
            'canManage' => $this->perm->canManageSite($s),
            'canContribute' => $this->perm->canContribute($s),
        ];
        return $out;
    }

    public function user(User $u, bool $withMemberships = false): array
    {
        $out = [
            'id' => $u->getId(),
            'email' => $u->getEmail(),
            'firstname' => $u->getFirstname(),
            'lastname' => $u->getLastname(),
            'displayName' => $u->getDisplayName(),
            'affiliation' => $u->getAffiliation(),
            'orcid' => $u->getOrcid(),
            'admin' => $u->isAdmin(),
            'active' => $u->isActive(),
            'federated' => $u->getFederationId() !== null,
            'local' => $u->getPassword() !== null,
            'identityProvider' => $u->getIdentityProvider(),
            'createdAt' => DateParser::format($u->getCreatedAt()),
            'lastLoginAt' => DateParser::format($u->getLastLoginAt()),
        ];
        if ($withMemberships) {
            $out['memberships'] = array_values(array_map(static fn (SiteMembership $m) => [
                'site' => $m->getSite()->getSlug(),
                'siteTitle' => $m->getSite()->getTitle(),
                'role' => $m->getRole(),
                'status' => $m->getStatus(),
            ], $u->getMemberships()->toArray()));
        }
        return $out;
    }

    public function membership(SiteMembership $m): array
    {
        return [
            'id' => $m->getId(),
            'role' => $m->getRole(),
            'status' => $m->getStatus(),
            'message' => $m->getMessage(),
            'createdAt' => DateParser::format($m->getCreatedAt()),
            'user' => $this->user($m->getUser()),
        ];
    }

    public function point(SamplingPoint $p): array
    {
        return [
            'id' => $p->getId(),
            'name' => $p->getName(),
            'longitude' => $p->getLongitude(),
            'latitude' => $p->getLatitude(),
            'elevation' => $p->getElevation(),
            'verticalDatum' => $p->getVerticalDatum(),
            'observedAt' => DateParser::format($p->getObservedAt()),
            'observedEnd' => DateParser::format($p->getObservedEnd()),
            'srcCrs' => $p->getSrcCrs(),
            'srcX' => $p->getSrcX(),
            'srcY' => $p->getSrcY(),
            'properties' => (object) $p->getProperties(),
        ];
    }

    public function file(DatasetFile $f): array
    {
        return [
            'id' => $f->getId(),
            'name' => $f->getOriginalName(),
            'mimeType' => $f->getMimeType(),
            'size' => $f->getSize(),
            'kind' => $f->getKind(),
            'url' => '/api/files/' . $f->getId(),
            'createdAt' => DateParser::format($f->getCreatedAt()),
        ];
    }

    public function dataset(Dataset $d): array
    {
        $canDownload = $this->perm->canDownload($d);
        return [
            'uuid' => (string) $d->getUuid(),
            'site' => $d->getSite()->getSlug(),
            'title' => $d->getTitle(),
            'description' => $d->getDescription(),
            'keywords' => $d->getKeywords(),
            'authors' => $d->getAuthors(),
            'license' => $d->getLicense(),
            'accessRight' => $d->getAccessRight(),
            'language' => $d->getLanguage(),
            'status' => $d->getStatus(),
            'reviewComment' => ($this->perm->canEdit($d)) ? $d->getReviewComment() : null,
            'temporalStart' => DateParser::format($d->getTemporalStart()),
            'temporalEnd' => DateParser::format($d->getTemporalEnd()),
            'properties' => (object) $d->getProperties(),
            'doi' => $d->getDoi(),
            'ordarUrl' => $d->getOrdarUrl(),
            'owner' => $d->getOwner() ? ['id' => $d->getOwner()->getId(), 'displayName' => $d->getOwner()->getDisplayName()] : null,
            'createdAt' => DateParser::format($d->getCreatedAt()),
            'updatedAt' => DateParser::format($d->getUpdatedAt()),
            'publishedAt' => DateParser::format($d->getPublishedAt()),
            'points' => array_map(fn ($p) => $this->point($p), $d->getPoints()->toArray()),
            'files' => array_map(fn ($f) => $this->file($f), $d->getFiles()->toArray()),
            'can' => [
                'edit' => $this->perm->canEdit($d),
                'delete' => $this->perm->canDelete($d),
                'download' => $canDownload,
                'review' => $this->perm->canManageSite($d->getSite()),
            ],
        ];
    }
}
