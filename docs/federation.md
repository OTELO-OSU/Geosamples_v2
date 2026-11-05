# Authentication via the RENATER federation

The application never communicates directly with institutional identity providers. Two
architectures are supported, selected by `FEDERATION_MODE`.

## `oidc` mode — Keycloak connected to RENATER (recommended)

This is the architecture used by Projet_IGSN: a Keycloak is the only service provider (SAML SP)
registered with the federation; it presents the WAYF/discovery page and passes the user's identity
to the application using OpenID Connect. A single RENATER SP thus serves all sites, and the same
Keycloak can also serve ORDaR and the IGSN application.

1. **Keycloak**: in the realm (e.g. `otelo`), add a SAML identity provider populated with
   RENATER federation IdP metadata (directly or through a SATOSA proxy, as with Projet_IGSN) and
   register Keycloak as an SP with RENATER; map `mail`, `givenName`, `sn`, and
   `eduPersonPrincipalName` to user attributes, and expose `eduPersonPrincipalName` in the userinfo
   response (“User Attribute” mapper on the client).
2. **Client** `geosamples`: confidential, standard (code) flow, PKCE S256; redirect URI:
   `https://<each-hostname>/api/auth/federation/callback` (one per site, plus the portal).
3. **Application** (`.env`):
   ```
   FEDERATION_MODE=oidc
   OIDC_ISSUER=https://sso.otelo.univ-lorraine.fr/realms/otelo
   OIDC_CLIENT_ID=geosamples
   OIDC_CLIENT_SECRET=…
   ```

Flow: the “Choose my institution” button opens `/api/auth/federation/login?return=…` → Keycloak →
institution → `/api/auth/federation/callback`. The backend exchanges the code (using the PKCE
verifier and a signed `state` bound to a cookie), reads attributes from `userinfo`, creates or finds
the account (by federated identifier, or by email), sets the session cookie, and returns to the
original page.

## `shibboleth` mode — Apache mod_shib headers (or CAS)

As with ORDaR v2's `/api/loginCAS`: Apache (Shibboleth SP) protects a single URL and passes
attributes in headers. Each hostname served must then be registered with RENATER, or a single
login hostname must be used.

```apache
<Location /api/auth/shibboleth>
    AuthType shibboleth
    ShibRequestSetting requireSession 1
    ShibUseHeaders On
    Require valid-user
    # Shared secret: the backend rejects headers that do not originate here
    RequestHeader set X-Shib-Secret "même-valeur-que-SHIB_SHARED_SECRET"
</Location>
```

```
FEDERATION_MODE=shibboleth
SHIB_HEADERS='{"id":"eppn","email":"mail","firstname":"givenname","lastname":"sn","affiliation":"o"}'
SHIB_SHARED_SECRET=…
```

## ORCID sign-in

ORCID is available in addition to the federation mode selected above. Register the redirect URI
in the ORCID client and set the same value in `ORCID_CLIENT_REDIRECT_URI` (it must match exactly).
Two forms work: `https://<hostname>/orcid` (a frontend route that relays to the backend) or
`https://<hostname>/api/auth/orcid/callback`; when empty, the latter is used.

```
ORCID_DOMAIN=orcid.org
ORCID_CLIENT_ID=…
ORCID_CLIENT_SECRET=…
ORCID_CLIENT_REDIRECT_URI=https://<hostname>/orcid
```

For the ORCID test environment, use `sandbox.orcid.org` as `ORCID_DOMAIN`.
The ORCID button is displayed only when both credentials are configured. The first sign-in
automatically creates an account, as with OIDC; the application requires a valid email address and
uses the address returned by ORCID first, then the public address on the ORCID profile. If no
address is available, make an address public in ORCID and try again. The ORCID identifier is saved
in the account profile.

## Local accounts

Always available (the “With a local account” form) for initial setup and for partners outside the
federation. An administrator creates them (`app:user:create` or “Global administration → Accounts”).
Sign-in attempts are rate-limited per IP and per account.

## Pre-authorization

A manager can add someone to their site using their email address before their first sign-in: the
federated account is linked automatically when the institution provides the same address.
