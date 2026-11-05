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
 * Authentication via the RENATER identity federation.
 *
 * Two modes (FEDERATION_MODE):
 *
 *  oidc       — the application is an OIDC client of Keycloak (or SATOSA), which is
 *               itself a SAML service provider for the RENATER federation (WAYF/discovery
 *               through Keycloak). "Authorization code" + PKCE flow; attributes are
 *               read from the userinfo endpoint. This is the architecture used for Projet_IGSN.
 *
 *  shibboleth — Apache mod_shib (RENATER SP) or CAS protects /api/auth/shibboleth
 *               and passes attributes in headers (as with ORDaR v2's /api/loginCAS).
 *               SHIB_SHARED_SECRET can require an X-Shib-Secret header set by the
 *               reverse proxy to reject forged headers.
 */
final class FederationService
{
    private const STATE_COOKIE = 'gs_oidc';
    private const ORCID_STATE_COOKIE = 'gs_orcid';

    public function __construct(
        private string $federationMode,
        private string $oidcIssuer,
        private string $oidcClientId,
        private string $oidcClientSecret,
        private string $oidcScopes,
        private string $orcidDomain,
        private string $orcidRedirectUri,
        private string $orcidClientId,
        private string $orcidClientSecret,
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

    public function orcidLoginUrl(): ?string
    {
        return $this->orcidClientId !== '' && $this->orcidClientSecret !== ''
            ? '/api/auth/orcid/login'
            : null;
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

    /** @return array{0: User, 1: string} [user, return path] */
    public function oidcCallback(Request $request): array
    {
        if ($this->mode() !== 'oidc') {
            throw ApiException::notFound();
        }
        if ($err = $request->query->get('error')) {
            throw new ApiException('Sign-in rejected by identity provider: ' . $err, 401);
        }
        $state = $this->verify((string) $request->query->get('state'));
        [$nonce, $verifier] = array_pad(explode('.', (string) $request->cookies->get(self::STATE_COOKIE), 2), 2, '');
        if (!$state || !hash_equals((string) $state['n'], $nonce) || $state['e'] < time()) {
            throw new ApiException('Sign-in session is invalid or expired; please try again', 401);
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
            throw new ApiException('Failed to exchange authorization code', 401);
        }
        $tokens = $tokenResp->toArray();
        // The access token comes directly from the token endpoint (TLS, authenticated client):
        // attributes are read from userinfo rather than the id_token.
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

    // -------------------------------------------------------------------- ORCID
    public function orcidRedirect(Request $request, string $return): RedirectResponse
    {
        if ($this->orcidLoginUrl() === null) {
            throw ApiException::notFound();
        }
        $nonce = bin2hex(random_bytes(16));
        $state = $this->sign(['n' => $nonce, 'r' => self::safeReturn($return), 'e' => time() + 600]);
        // Same format as ORDaR v2: unencoded redirect_uri, openid scope.
        $url = $this->orcidBase() . '/oauth/authorize?client_id=' . rawurlencode($this->orcidClientId)
            . '&response_type=code&scope=openid&redirect_uri=' . $this->orcidCallbackUrl($request)
            . '&state=' . rawurlencode($state);
        $resp = new RedirectResponse($url);
        $resp->headers->setCookie(Cookie::create(self::ORCID_STATE_COOKIE, $nonce, time() + 600, '/api/auth/orcid', null, $this->cookieSecure, true, false, Cookie::SAMESITE_LAX));
        return $resp;
    }

    /** @return array{0: User, 1: string} [user, return path] */
    public function orcidCallback(Request $request): array
    {
        if ($this->orcidLoginUrl() === null) {
            throw ApiException::notFound();
        }
        if ($err = $request->query->get('error')) {
            throw new ApiException('Sign-in rejected by ORCID: ' . $err, 401);
        }
        $state = $this->verify((string) $request->query->get('state'));
        $nonce = (string) $request->cookies->get(self::ORCID_STATE_COOKIE);
        if (!$state || !hash_equals((string) $state['n'], $nonce) || $state['e'] < time()) {
            throw new ApiException('Sign-in session is invalid or expired; please try again', 401);
        }

        $tokenResponse = $this->http->request('POST', $this->orcidBase() . '/oauth/token', [
            'headers' => ['Accept' => 'application/json'],
            'body' => [
                'client_id' => $this->orcidClientId,
                'client_secret' => $this->orcidClientSecret,
                'grant_type' => 'authorization_code',
                'code' => (string) $request->query->get('code'),
            ],
        ]);
        if ($tokenResponse->getStatusCode() !== 200) {
            $this->logger->warning('ORCID token exchange failed', ['body' => $tokenResponse->getContent(false)]);
            throw new ApiException('Failed to exchange ORCID code', 401);
        }
        $tokens = $tokenResponse->toArray();
        $accessToken = (string) ($tokens['access_token'] ?? '');
        if ($accessToken === '') {
            throw new ApiException('Invalid ORCID response', 401);
        }

        $userinfoResponse = $this->http->request('GET', $this->orcidBase() . '/oauth/userinfo', [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $accessToken,
            ],
        ]);
        if ($userinfoResponse->getStatusCode() !== 200) {
            $this->logger->warning('ORCID userinfo request failed', ['status' => $userinfoResponse->getStatusCode()]);
            throw new ApiException('Unable to retrieve your ORCID profile', 401);
        }
        $claims = $userinfoResponse->toArray();
        $orcid = self::canonicalOrcid((string) ($claims['sub'] ?? $tokens['orcid'] ?? ''));
        if ($orcid === '') {
            throw new ApiException('ORCID did not provide a valid identifier', 401);
        }

        $email = trim((string) ($claims['email'] ?? ''));
        if ($email === '') {
            $email = $this->publicOrcidEmail($orcid);
        }
        $user = $this->upsert([
            'id' => 'orcid:' . $orcid,
            'orcid' => $orcid,
            'email' => $email,
            'firstname' => (string) ($claims['given_name'] ?? ''),
            'lastname' => (string) ($claims['family_name'] ?? ''),
            'affiliation' => '',
            'idp' => 'orcid',
        ]);
        return [$user, $state['r']];
    }

    public function clearOrcidStateCookie(\Symfony\Component\HttpFoundation\Response $resp): void
    {
        $resp->headers->clearCookie(self::ORCID_STATE_COOKIE, '/api/auth/orcid', null, $this->cookieSecure, true, Cookie::SAMESITE_LAX);
    }

    // --------------------------------------------------------------- Shibboleth
    public function shibbolethUser(Request $request): User
    {
        if ($this->mode() !== 'shibboleth') {
            throw ApiException::notFound();
        }
        if ($this->shibSharedSecret !== '' && !hash_equals($this->shibSharedSecret, (string) $request->headers->get('X-Shib-Secret'))) {
            throw ApiException::forbidden('Untrusted federation headers');
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
     * Attach the federated identity to an account: by federated ID, then by
     * email (an account pre-created by a manager), or create a new account.
     */
    public function upsert(array $id): User
    {
        $email = mb_strtolower(trim((string) ($id['email'] ?? '')));
        $orcid = $id['orcid'] ?? null;
        $fid = ($id['id'] ?? '') !== '' ? $id['id'] : $email;
        $user = ($fid !== '' ? $this->users->findOneBy(['federationId' => $fid]) : null)
            ?? ($orcid ? $this->users->findOneBy(['orcid' => $orcid]) : null)
            ?? ($email !== '' ? $this->users->findOneByEmail($email) : null);

        if ($email === '' && $user) {
            $email = $user->getEmail();
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = ($id['idp'] ?? '') === 'orcid'
                ? 'No valid email address was provided. Make your email address public in ORCID and try again.'
                : 'Your institution did not provide an email address; sign-in is not possible.';
            throw new ApiException($message, 401);
        }
        if ($user && !$user->isActive()) {
            throw ApiException::forbidden('Account disabled');
        }
        if ($orcid && $user?->getOrcid() !== null && $user->getOrcid() !== $orcid) {
            throw ApiException::forbidden('Another ORCID iD is already linked to this account');
        }
        if (!$user) {
            $user = new User($email);
            $this->em->persist($user);
        }
        $user->setFederationId($fid)->setIdentityProvider(mb_substr($id['idp'], 0, 120) ?: null);
        if ($orcid) {
            $user->setOrcid($orcid);
        }
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
            throw new \RuntimeException('OIDC_ISSUER is not configured');
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

    private function orcidCallbackUrl(Request $request): string
    {
        // This URI must match the one registered with ORCID (ORCID_CLIENT_REDIRECT_URI).
        return $this->orcidRedirectUri !== ''
            ? $this->orcidRedirectUri
            : $request->getSchemeAndHttpHost() . '/api/auth/orcid/callback';
    }

    private function orcidDomain(): string
    {
        return trim(preg_replace('#^https?://#', '', $this->orcidDomain) ?? '', '/');
    }

    private function orcidBase(): string
    {
        return 'https://' . $this->orcidDomain();
    }

    private function publicOrcidEmail(string $orcid): string
    {
        $url = 'https://pub.' . $this->orcidDomain() . '/v3.0/' . rawurlencode($orcid) . '/email';
        $response = $this->http->request('GET', $url, [
            'headers' => ['Accept' => 'application/vnd.orcid+json'],
        ]);
        if ($response->getStatusCode() !== 200) {
            return '';
        }
        $emails = $response->toArray(false)['email'] ?? [];
        return trim((string) ($emails[0]['email'] ?? ''));
    }

    private static function canonicalOrcid(string $value): string
    {
        $value = preg_replace('#^https?://orcid\.org/#i', '', trim($value)) ?? '';
        return preg_match('/^\d{4}-\d{4}-\d{4}-\d{3}[\dX]$/i', $value) ? strtoupper($value) : '';
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
