<?php

namespace App\Controller;

use App\Exception\ApiException;
use App\Repository\UserRepository;
use App\Service\AuthService;
use App\Service\FederationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /api/auth/login                 connexion par compte local {email, password}
 * POST /api/auth/refresh               nouveau JWT depuis le cookie de session (rotation)
 * POST /api/auth/logout                fin de session
 * GET  /api/auth/federation/login      redirection vers le fournisseur OIDC (RENATER via Keycloak)
 * GET  /api/auth/federation/callback   retour OIDC
 * GET  /api/auth/shibboleth            connexion par en-têtes mod_shib / CAS
 * GET  /api/me  · PATCH /api/me        profil
 */
final class AuthController extends ApiController
{
    public function __construct(
        private AuthService $auth,
        private FederationService $federation,
        private UserRepository $users,
        private UserPasswordHasherInterface $hasher,
        private EntityManagerInterface $em,
        private RateLimiterFactory $loginIpLimiter,
        private RateLimiterFactory $loginAccountLimiter,
        private LoggerInterface $logger,
    ) {}

    #[Route('/api/auth/login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $data = $this->body($request);
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $password = (string) ($data['password'] ?? '');

        foreach ([$this->loginIpLimiter->create($request->getClientIp() ?? 'unknown'), $this->loginAccountLimiter->create($email)] as $limiter) {
            if (!$limiter->consume()->isAccepted()) {
                throw new ApiException('Trop de tentatives, réessayez plus tard', 429);
            }
        }
        $user = $email !== '' ? $this->users->findOneByEmail($email) : null;
        if (!$user || !$user->getPassword() || !$this->hasher->isPasswordValid($user, $password)) {
            $this->logger->notice('Failed login', ['email' => $email, 'ip' => $request->getClientIp()]);
            throw new ApiException('Identifiants incorrects', 401);
        }
        if (!$user->isActive()) {
            throw ApiException::forbidden('Compte désactivé');
        }
        return $this->auth->sessionResponse($user, $this->auth->issueRefreshCookie($user));
    }

    #[Route('/api/auth/refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        [$user, $cookie] = $this->auth->rotate($request->cookies->get(AuthService::COOKIE));
        return $this->auth->sessionResponse($user, $cookie);
    }

    #[Route('/api/auth/logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $resp = new JsonResponse(['ok' => true]);
        $this->auth->revoke($request->cookies->get(AuthService::COOKIE), $resp);
        return $resp;
    }

    #[Route('/api/auth/federation/login', methods: ['GET'])]
    public function federationLogin(Request $request): Response
    {
        return $this->federation->oidcRedirect($request, (string) $request->query->get('return', '/'));
    }

    #[Route('/api/auth/federation/callback', methods: ['GET'])]
    public function federationCallback(Request $request): Response
    {
        try {
            [$user, $return] = $this->federation->oidcCallback($request);
            $resp = new RedirectResponse($this->withQuery($return, ['auth' => 'ok']));
            $resp->headers->setCookie($this->auth->issueRefreshCookie($user));
        } catch (ApiException $e) {
            $resp = new RedirectResponse('/?' . http_build_query(['auth' => 'error', 'message' => $e->getMessage()]));
        } catch (\Throwable $e) {
            $this->logger->error('OIDC callback error: ' . $e->getMessage());
            $resp = new RedirectResponse('/?' . http_build_query(['auth' => 'error', 'message' => 'Échec de la connexion fédérée']));
        }
        $this->federation->clearStateCookie($resp);
        return $resp;
    }

    #[Route('/api/auth/shibboleth', methods: ['GET'])]
    public function shibboleth(Request $request): Response
    {
        $return = FederationService::safeReturn($request->query->get('return'));
        try {
            $user = $this->federation->shibbolethUser($request);
            $resp = new RedirectResponse($this->withQuery($return, ['auth' => 'ok']));
            $resp->headers->setCookie($this->auth->issueRefreshCookie($user));
            return $resp;
        } catch (ApiException $e) {
            return new RedirectResponse('/?' . http_build_query(['auth' => 'error', 'message' => $e->getMessage()]));
        }
    }

    #[Route('/api/me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        return $this->json($this->presenter->user($this->perm->requireUser(), true));
    }

    #[Route('/api/me', methods: ['PATCH'])]
    public function updateMe(Request $request): JsonResponse
    {
        $user = $this->perm->requireUser();
        $d = $this->body($request);
        if (\array_key_exists('firstname', $d)) { $user->setFirstname(mb_substr((string) $d['firstname'], 0, 120)); }
        if (\array_key_exists('lastname', $d)) { $user->setLastname(mb_substr((string) $d['lastname'], 0, 120)); }
        if (\array_key_exists('affiliation', $d)) { $user->setAffiliation(mb_substr((string) $d['affiliation'], 0, 255)); }
        if (\array_key_exists('orcid', $d)) {
            $o = preg_replace('#^https?://orcid\.org/#', '', trim((string) $d['orcid']));
            if ($o !== '' && !preg_match('/^\d{4}-\d{4}-\d{4}-\d{3}[\dX]$/', $o)) {
                throw ApiException::validation(['orcid' => 'ORCID invalide']);
            }
            $user->setOrcid($o);
        }
        if (!empty($d['newPassword'])) {
            if (!$user->getPassword()) {
                throw new ApiException('Les comptes fédérés n\'ont pas de mot de passe local');
            }
            if (!$this->hasher->isPasswordValid($user, (string) ($d['currentPassword'] ?? ''))) {
                throw ApiException::validation(['currentPassword' => 'Mot de passe actuel incorrect']);
            }
            if (mb_strlen((string) $d['newPassword']) < 12) {
                throw ApiException::validation(['newPassword' => '12 caractères minimum']);
            }
            $user->setPassword($this->hasher->hashPassword($user, (string) $d['newPassword']));
        }
        $this->em->flush();
        return $this->json($this->presenter->user($user, true));
    }

    private function withQuery(string $path, array $q): string
    {
        return $path . (str_contains($path, '?') ? '&' : '?') . http_build_query($q);
    }
}
