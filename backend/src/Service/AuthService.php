<?php

namespace App\Service;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Exception\ApiException;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Jetons de session, même schéma qu'ORDaR v2 :
 *  - access token JWT court (15 min), conservé en mémoire par le front
 *  - refresh token opaque, rotatif, en cookie HttpOnly limité à /api/auth
 */
final class AuthService
{
    public const COOKIE = 'gs_refresh';

    public function __construct(
        private EntityManagerInterface $em,
        private JWTTokenManagerInterface $jwt,
        private Presenter $presenter,
        private int $refreshTtl,
        private bool $cookieSecure,
    ) {}

    /** Crée un refresh token et renvoie le cookie correspondant. */
    public function issueRefreshCookie(User $user): Cookie
    {
        $raw = bin2hex(random_bytes(32));
        $expires = new \DateTimeImmutable("+{$this->refreshTtl} seconds");
        $this->em->persist(new RefreshToken($user, hash('sha256', $raw), $expires));
        $user->touchLogin();
        $this->em->flush();
        return Cookie::create(self::COOKIE, $raw, $expires, '/api/auth', null, $this->cookieSecure, true, false, Cookie::SAMESITE_LAX);
    }

    public function sessionResponse(User $user, ?Cookie $cookie = null): JsonResponse
    {
        $resp = new JsonResponse([
            'token' => $this->jwt->create($user),
            'user' => $this->presenter->user($user, true),
        ]);
        if ($cookie) {
            $resp->headers->setCookie($cookie);
        }
        return $resp;
    }

    /** Consomme un refresh token (rotation) et renvoie l'utilisateur + nouveau cookie. */
    public function rotate(?string $raw): array
    {
        if (!$raw) {
            throw ApiException::unauthorized('Session absente');
        }
        $repo = $this->em->getRepository(RefreshToken::class);
        $token = $repo->findOneBy(['tokenHash' => hash('sha256', $raw)]);
        if (!$token || $token->isExpired()) {
            if ($token) {
                $this->em->remove($token);
                $this->em->flush();
            }
            throw ApiException::unauthorized('Session expirée');
        }
        $user = $token->getUser();
        $this->em->remove($token);
        if (!$user->isActive()) {
            $this->em->flush();
            throw ApiException::forbidden('Compte désactivé');
        }
        return [$user, $this->issueRefreshCookie($user)];
    }

    public function revoke(?string $raw, Response $resp): void
    {
        if ($raw) {
            $token = $this->em->getRepository(RefreshToken::class)->findOneBy(['tokenHash' => hash('sha256', $raw)]);
            if ($token) {
                $this->em->remove($token);
                $this->em->flush();
            }
        }
        $resp->headers->clearCookie(self::COOKIE, '/api/auth', null, $this->cookieSecure, true, Cookie::SAMESITE_LAX);
    }

    public function purgeExpired(): int
    {
        return $this->em->createQuery('DELETE FROM App\Entity\RefreshToken t WHERE t.expiresAt < :now')
            ->setParameter('now', new \DateTimeImmutable())->execute();
    }
}
