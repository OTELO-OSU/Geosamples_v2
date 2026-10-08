<?php

namespace App\Controller;

use App\Entity\SiteMembership;
use App\Entity\User;
use App\Exception\ApiException;
use App\Repository\SiteMembershipRepository;
use App\Repository\UserRepository;
use App\Service\Notifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET    /api/sites/{slug}/members          membres et demandes (responsable)
 * POST   /api/sites/{slug}/members          ajout par e-mail {email, role, firstname?, lastname?} (responsable)
 * PATCH  /api/sites/{slug}/members/{id}     {role?, status?} (responsable)
 * DELETE /api/sites/{slug}/members/{id}     (responsable, ou soi-même pour quitter)
 * POST   /api/sites/{slug}/join             demande de participation {message} (connecté)
 */
final class MemberController extends ApiController
{
    public function __construct(
        private SiteMembershipRepository $memberships,
        private UserRepository $users,
        private EntityManagerInterface $em,
        private Notifier $notifier,
        private RateLimiterFactory $joinRequestLimiter,
    ) {}

    #[Route('/api/sites/{slug}/members', methods: ['GET'])]
    public function list(string $slug): JsonResponse
    {
        $site = $this->site($slug);
        $this->perm->denyUnless($this->perm->canManageSite($site));
        return $this->json(array_map(fn ($m) => $this->presenter->membership($m), $this->memberships->findForSite($site)));
    }

    #[Route('/api/sites/{slug}/members', methods: ['POST'])]
    public function add(string $slug, Request $request): JsonResponse
    {
        $site = $this->site($slug);
        $this->perm->denyUnless($this->perm->canManageSite($site));
        $d = $this->body($request);
        $email = mb_strtolower(trim((string) ($d['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ApiException::validation(['email' => 'E-mail invalide']);
        }
        $role = $this->role($d['role'] ?? SiteMembership::ROLE_CONTRIBUTOR);
        // Un compte pré-créé sera rattaché à l'identité fédérée lors de la 1re connexion (même e-mail).
        $user = $this->users->findOneByEmail($email);
        if (!$user) {
            $user = (new User($email))->setFirstname((string) ($d['firstname'] ?? ''))->setLastname((string) ($d['lastname'] ?? ''));
            $this->em->persist($user);
        }
        $m = $user->getId() ? $this->memberships->findOneBy(['site' => $site, 'user' => $user]) : null;
        if ($m) {
            $m->setRole($role)->setStatus(SiteMembership::STATUS_ACTIVE);
        } else {
            $m = new SiteMembership($site, $user, $role);
            $this->em->persist($m);
        }
        $this->em->flush();
        $this->notifier->membershipGranted($m);
        return $this->json($this->presenter->membership($m), 201);
    }

    #[Route('/api/sites/{slug}/members/{id}', methods: ['PATCH'])]
    public function update(string $slug, int $id, Request $request): JsonResponse
    {
        $site = $this->site($slug);
        $this->perm->denyUnless($this->perm->canManageSite($site));
        $m = $this->memberships->find($id);
        if (!$m || $m->getSite()->getId() !== $site->getId()) {
            throw ApiException::notFound();
        }
        $d = $this->body($request);
        $wasPending = !$m->isActive();
        if (isset($d['role'])) {
            $m->setRole($this->role($d['role']));
        }
        if (isset($d['status'])) {
            if (!\in_array($d['status'], [SiteMembership::STATUS_ACTIVE, SiteMembership::STATUS_PENDING], true)) {
                throw new ApiException('Statut inconnu');
            }
            $m->setStatus($d['status']);
        }
        $this->guardLastManager($m);
        $this->em->flush();
        if ($wasPending && $m->isActive()) {
            $this->notifier->membershipGranted($m);
        }
        return $this->json($this->presenter->membership($m));
    }

    #[Route('/api/sites/{slug}/members/{id}', methods: ['DELETE'])]
    public function remove(string $slug, int $id): JsonResponse
    {
        $site = $this->site($slug);
        $m = $this->memberships->find($id);
        if (!$m || $m->getSite()->getId() !== $site->getId()) {
            throw ApiException::notFound();
        }
        $self = $this->perm->user()?->getId() === $m->getUser()->getId();
        $this->perm->denyUnless($self || $this->perm->canManageSite($site));
        if ($m->isManager() && \count($this->memberships->findManagers($site)) <= 1 && !$this->perm->isAdmin()) {
            throw new ApiException('Le site doit conserver au moins un responsable', 409);
        }
        $this->em->remove($m);
        $this->em->flush();
        return $this->json(['ok' => true]);
    }

    #[Route('/api/sites/{slug}/join', methods: ['POST'])]
    public function join(string $slug, Request $request): JsonResponse
    {
        $site = $this->site($slug);
        $user = $this->perm->requireUser();
        if (!$site->setting('allowJoinRequests')) {
            throw ApiException::forbidden('Ce site n\'accepte pas de demandes de participation');
        }
        if (!$this->joinRequestLimiter->create('u' . $user->getId())->consume()->isAccepted()) {
            throw new ApiException('Trop de demandes, réessayez plus tard', 429);
        }
        $m = $user->getMembership($site);
        if ($m) {
            return $this->json($this->presenter->membership($m));
        }
        $m = new SiteMembership($site, $user, SiteMembership::ROLE_CONTRIBUTOR, SiteMembership::STATUS_PENDING);
        $m->setMessage(mb_substr(trim((string) ($this->body($request)['message'] ?? '')), 0, 2000) ?: null);
        $this->em->persist($m);
        $this->em->flush();
        $this->notifier->joinRequested($site, $m);
        return $this->json($this->presenter->membership($m), 201);
    }

    private function role(mixed $r): string
    {
        if (!\in_array($r, SiteMembership::ROLES, true)) {
            throw ApiException::validation(['role' => 'Rôle attendu : responsable ou utilisateur']);
        }
        return $r;
    }

    private function guardLastManager(SiteMembership $changed): void
    {
        if ($this->perm->isAdmin()) {
            return;
        }
        $managers = array_filter($this->memberships->findManagers($changed->getSite()), static fn ($m) => $m->isManager());
        if (!$managers) {
            throw new ApiException('Le site doit conserver au moins un responsable', 409);
        }
    }
}
