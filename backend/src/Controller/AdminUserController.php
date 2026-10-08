<?php

namespace App\Controller;

use App\Entity\User;
use App\Exception\ApiException;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Administration globale des comptes (ROLE_ADMIN, cf. access_control).
 * GET   /api/admin/users?q=
 * POST  /api/admin/users          {email, firstname, lastname, password?, admin?}
 * PATCH /api/admin/users/{id}     {admin?, active?, password?}
 */
final class AdminUserController extends ApiController
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
    ) {}

    #[Route('/api/admin/users', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $list = $this->users->search($request->query->get('q'), 200, max(0, $request->query->getInt('offset')));
        return $this->json(array_map(fn ($u) => $this->presenter->user($u, true), $list));
    }

    #[Route('/api/admin/users', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $d = $this->body($request);
        $email = mb_strtolower(trim((string) ($d['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ApiException::validation(['email' => 'E-mail invalide']);
        }
        if ($this->users->findOneByEmail($email)) {
            throw ApiException::validation(['email' => 'Ce compte existe déjà']);
        }
        $u = (new User($email))->setFirstname((string) ($d['firstname'] ?? ''))->setLastname((string) ($d['lastname'] ?? ''))
            ->setAffiliation($d['affiliation'] ?? null)->setAdmin((bool) ($d['admin'] ?? false));
        $this->setPassword($u, $d['password'] ?? null);
        $this->em->persist($u);
        $this->em->flush();
        return $this->json($this->presenter->user($u, true), 201);
    }

    #[Route('/api/admin/users/{id}', methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $u = $this->users->find($id) ?? throw ApiException::notFound();
        $d = $this->body($request);
        $me = $this->perm->user();
        if (\array_key_exists('admin', $d)) {
            if ($me?->getId() === $u->getId() && !$d['admin']) {
                throw new ApiException('Vous ne pouvez pas retirer vos propres droits d\'administration');
            }
            $u->setAdmin((bool) $d['admin']);
        }
        if (\array_key_exists('active', $d)) {
            if ($me?->getId() === $u->getId() && !$d['active']) {
                throw new ApiException('Vous ne pouvez pas désactiver votre propre compte');
            }
            $u->setActive((bool) $d['active']);
        }
        foreach (['firstname', 'lastname'] as $k) {
            if (isset($d[$k])) { $u->{'set' . ucfirst($k)}((string) $d[$k]); }
        }
        if (\array_key_exists('password', $d)) {
            $this->setPassword($u, $d['password']);
        }
        $this->em->flush();
        return $this->json($this->presenter->user($u, true));
    }

    private function setPassword(User $u, mixed $pwd): void
    {
        if ($pwd === null || $pwd === '') {
            return;
        }
        if (mb_strlen((string) $pwd) < 12) {
            throw ApiException::validation(['password' => '12 caractères minimum']);
        }
        $u->setPassword($this->hasher->hashPassword($u, (string) $pwd));
    }
}
