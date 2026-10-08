# Authentification par la fédération RENATER

L'application ne parle jamais directement aux fournisseurs d'identité des établissements. Deux
architectures sont prises en charge, choisies par `FEDERATION_MODE`.

## Mode `oidc` — Keycloak raccordé à RENATER (recommandé)

C'est l'architecture de Projet_IGSN : un Keycloak est le seul fournisseur de service (SP SAML)
déclaré dans la fédération ; il présente le WAYF/discovery et transmet l'identité à l'application en
OpenID Connect. Un seul SP RENATER sert ainsi tous les sites, et le même Keycloak peut aussi servir
ORDaR et l'application IGSN.

1. **Keycloak** : dans le royaume (ex. `otelo`), ajouter un fournisseur d'identité SAML alimenté par
   les métadonnées des IdP de la fédération RENATER (directement ou via un proxy SATOSA, comme pour
   Projet_IGSN) et déclarer Keycloak comme SP auprès de RENATER ; mapper `mail`, `givenName`, `sn`,
   `eduPersonPrincipalName` vers les attributs utilisateur, et exposer `eduPersonPrincipalName` dans
   la réponse userinfo (mapper « User Attribute » sur le client).
2. **Client** `geosamples` : confidentiel, flux standard (code), PKCE S256 ; URI de redirection :
   `https://<chaque nom d'hôte>/api/auth/federation/callback` (un par site, plus le portail).
3. **Application** (`.env`) :
   ```
   FEDERATION_MODE=oidc
   OIDC_ISSUER=https://sso.otelo.univ-lorraine.fr/realms/otelo
   OIDC_CLIENT_ID=geosamples
   OIDC_CLIENT_SECRET=…
   ```

Déroulé : le bouton « Choisir mon établissement » mène à `/api/auth/federation/login?return=…` →
Keycloak → établissement → `/api/auth/federation/callback`. Le backend échange le code (avec le
vérificateur PKCE et un `state` signé lié à un cookie), lit les attributs sur `userinfo`, crée ou
retrouve le compte (par identifiant fédéré, sinon par e-mail), pose le cookie de session et revient à
la page d'origine.

## Mode `shibboleth` — en-têtes Apache mod_shib (ou CAS)

Comme `/api/loginCAS` d'ORDaR v2 : Apache (SP Shibboleth) protège une seule URL et transmet les
attributs en en-têtes. Chaque nom d'hôte servi doit alors être déclaré auprès de RENATER, ou un
nom d'hôte unique de connexion doit être utilisé.

```apache
<Location /api/auth/shibboleth>
    AuthType shibboleth
    ShibRequestSetting requireSession 1
    ShibUseHeaders On
    Require valid-user
    # Secret partagé : le backend refuse les en-têtes qui ne viennent pas d'ici
    RequestHeader set X-Shib-Secret "même-valeur-que-SHIB_SHARED_SECRET"
</Location>
```

```
FEDERATION_MODE=shibboleth
SHIB_HEADERS='{"id":"eppn","email":"mail","firstname":"givenname","lastname":"sn","affiliation":"o"}'
SHIB_SHARED_SECRET=…
```

## Comptes locaux

Toujours disponibles (formulaire « Avec un compte local ») pour amorcer l'instance et pour les
partenaires hors fédération. Ils sont créés par un administrateur (`app:user:create` ou
« Administration générale → Comptes »). Les tentatives de connexion sont limitées par IP et par compte.

## Pré-autorisation

Un responsable peut ajouter une personne à son site par son adresse e-mail avant sa première
connexion : le compte fédéré est rattaché automatiquement lorsque l'établissement transmet la même
adresse.
