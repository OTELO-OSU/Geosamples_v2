<?php

namespace App\Service;

use App\Entity\User;
use App\Exception\ApiException;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Authentification via la fédération d'identité RENATER.
 *
 * Deux modes (FEDERATION_MODE) :
 *
 *  oidc       — l'application est cliente OIDC d'un Keycloak (ou SATOSA) lui-même
 *               fournisseur de service SAML de la fédération RENATER (WAYF/discovery
 *               côté Keycloak). Flux "authorization code" + PKCE, les attributs sont
 *               lus sur l'endpoint userinfo. C'est l'architecture retenue pour Projet_IGSN.
 *
 *  shibboleth — un Apache mod_shib (SP RENATER) ou un CAS protège /api/auth/shibboleth
 *               et transmet les attributs en en-têtes (comme /api/loginCAS d'ORDaR v2).
 *               SHIB_SHARED_SECRET permet d'exiger un en-tête X-Shib-Secret posé par
 *               le reverse proxy, pour refuser des en-têtes forgés.
 */
final class FederationService
{
    private const STATE_COOKIE = 'gs_oidc';

    public function __construct(
        private string $federationMode,
        private string $oidcIssuer,
        private string $oidcClientId,
        private string $oidcClientSecret,
        private string $oidcScopes,
        private array $shibHeaders,
        private string $shibSharedSecret,
        private string $appSecret,
        private bool $cookieSecure,
        private HttpClientInterface $http,
        private CacheInterface $cache,
        private UserRepository $users,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {}

    public function mode(): string
    {
        return \in_array($this->federationMode, ['oidc', 'shibboleth'], true) ? $this->federationMode : 'none';
    }

    public function loginUrl(): ?string
    {
        return match ($this->mode()) {
            'oidc' => '/api/auth/federation/login',
            'shibboleth' => '/api/auth/shibboleth',
            default => null,
        };
    }

    public static function safeReturn(?string $return): string
    {
        $return = (string) $return;
        return (str_starts_with($return, '/') && !str_starts_with($return, '//') && !str_contains($return, '\\')) ? $return : '/';
    }

    // --------------------------------------------------------------------- OIDC
    public function oidcRedirect(Request $request, string $return): RedirectResponse
    {
        if ($this->mode() !== 'oidc') {
            throw ApiException::notFound();
        }
        $cfg = $this->discovery();
        $nonce = bin2hex(random_bytes(16));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $state = $this->sign(['n' => $nonce, 'r' => self::safeReturn($return), 'e' => time() + 600]);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $url = $cfg['authorization_endpoint'] . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->oidcClientId,
            'redirect_uri' => $this->callbackUrl($request),
            'scope' => $this->oidcScopes,
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
        $resp = new RedirectResponse($url);
        $resp->headers->setCookie(Cookie::create(self::STATE_COOKIE, $nonce . '.' . $verifier, time() + 600, '/api/auth/federation', null, $this->cookieSecure, true, false, Cookie::SAMESITE_LAX));
        return $resp;
    }

    /** @return array{0: User, 1: string} [utilisateur, chemin de retour] */
    public function oidcCallback(Request $request): array
    {
        if ($this->mode() !== 'oidc') {
            throw ApiException::notFound();
        }
        if ($err = $request->query->get('error')) {
            throw new ApiException('Connexion refusée par le fournisseur d\'identité : ' . $err, 401);
        }
        $state = $this->verify((string) $request->query->get('state'));
        [$nonce, $verifier] = array_pad(explode('.', (string) $request->cookies->get(self::STATE_COOKIE), 2), 2, '');
        if (!$state || !hash_equals((string) $state['n'], $nonce) || $state['e'] < time()) {
            throw new ApiException('Session de connexion invalide ou expirée, veuillez réessayer', 401);
        }
        $cfg = $this->discovery();
        $tokenResp = $this->http->request('POST', $cfg['token_endpoint'], [
            'body' => [
                'grant_type' => 'authorization_code',
                'code' => (string) $request->query->get('code'),
                'redirect_uri' => $this->callbackUrl($request),
                'client_id' => $this->oidcClientId,
                'client_secret' => $this->oidcClientSecret,
                'code_verifier' => $verifier,
            ],
        ]);
        if ($tokenResp->getStatusCode() !== 200) {
            $this->logger->warning('OIDC token exchange failed', ['body' => $tokenResp->getContent(false)]);
            throw new ApiException('Échec de l\'échange du code d\'autorisation', 401);
        }
        $tokens = $tokenResp->toArray();
        // L'access token est obtenu directement du token endpoint (TLS, client authentifié) :
        // les attributs sont lus sur userinfo plutôt que dans l'id_token.
        $claims = $this->http->request('GET', $cfg['userinfo_endpoint'], [
            'headers' => ['Authorization' => 'Bearer ' . $tokens['access_token']],
        ])->toArray();

        $user = $this->upsert([
            'id' => (string) ($claims['eduPersonPrincipalName'] ?? $claims['eppn'] ?? $claims['sub'] ?? ''),
            'email' => (string) ($claims['email'] ?? ''),
            'firstname' => (string) ($claims['given_name'] ?? ''),
            'lastname' => (string) ($claims['family_name'] ?? ''),
            'affiliation' => (string) ($claims['organization'] ?? $claims['o'] ?? ''),
            'idp' => (string) ($claims['identity_provider'] ?? $claims['idp'] ?? 'oidc'),
        ]);
        return [$user, $state['r']];
    }

    public function clearStateCookie(\Symfony\Component\HttpFoundation\Response $resp): void
    {
        $resp->headers->clearCookie(self::STATE_COOKIE, '/api/auth/federation', null, $this->cookieSecure, true, Cookie::SAMESITE_LAX);
    }

    // --------------------------------------------------------------- Shibboleth
    public function shibbolethUser(Request $request): User
    {
        if ($this->mode() !== 'shibboleth') {
            throw ApiException::notFound();
        }
        if ($this->shibSharedSecret !== '' && !hash_equals($this->shibSharedSecret, (string) $request->headers->get('X-Shib-Secret'))) {
            throw ApiException::forbidden('En-têtes de fédération non approuvés');
        }
        $h = fn (string $k) => trim(explode(';', (string) $request->headers->get($this->shibHeaders[$k] ?? $k, ''))[0]);
        return $this->upsert([
            'id' => $h('id'),
            'email' => $h('email'),
            'firstname' => $h('firstname'),
            'lastname' => $h('lastname'),
            'affiliation' => $h('affiliation'),
            'idp' => (string) $request->headers->get('Shib-Identity-Provider', 'shibboleth'),
        ]);
    }

    // ------------------------------------------------------------------- commun
    /**
     * Rattache l'identité fédérée à un compte : par identifiant fédéré, sinon par
     * e-mail (compte pré-créé par un responsable), sinon création.
     */
    public function upsert(array $id): User
    {
        $email = mb_strtolower(trim($id['email']));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException('Votre établissement n\'a pas transmis d\'adresse e-mail : connexion impossible', 401);
        }
        $fid = $id['id'] !== '' ? $id['id'] : $email;
        $user = $this->users->findOneBy(['federationId' => $fid]) ?? $this->users->findOneByEmail($email);
        if (!$user) {
            $user = new User($email);
            $this->em->persist($user);
        }
        if (!$user->isActive()) {
            throw ApiException::forbidden('Compte désactivé');
        }
        $user->setFederationId($fid)->setIdentityProvider(mb_substr($id['idp'], 0, 120) ?: null);
        if ($id['firstname'] !== '') { $user->setFirstname($id['firstname']); }
        if ($id['lastname'] !== '') { $user->setLastname($id['lastname']); }
        if ($id['affiliation'] !== '' && !$user->getAffiliation()) { $user->setAffiliation($id['affiliation']); }
        if ($user->getEmail() !== $email && !$this->users->findOneByEmail($email)) {
            $user->setEmail($email);
        }
        $this->em->flush();
        return $user;
    }

    private function discovery(): array
    {
        if ($this->oidcIssuer === '') {
            throw new \RuntimeException('OIDC_ISSUER non configuré');
        }
        return $this->cache->get('oidc_discovery_' . md5($this->oidcIssuer), function (ItemInterface $item) {
            $item->expiresAfter(3600);
            return $this->http->request('GET', rtrim($this->oidcIssuer, '/') . '/.well-known/openid-configuration')->toArray();
        });
    }

    private function callbackUrl(Request $request): string
    {
        return $request->getSchemeAndHttpHost() . '/api/auth/federation/callback';
    }

    private function sign(array $payload): string
    {
        $b = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        return $b . '.' . hash_hmac('sha256', $b, $this->appSecret);
    }

    private function verify(string $state): ?array
    {
        [$b, $sig] = array_pad(explode('.', $state, 2), 2, '');
        if ($b === '' || !hash_equals(hash_hmac('sha256', $b, $this->appSecret), $sig)) {
            return null;
        }
        return json_decode(base64_decode(strtr($b, '-_', '+/')), true) ?: null;
    }
}
