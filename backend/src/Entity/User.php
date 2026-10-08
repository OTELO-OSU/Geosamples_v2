<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Compte utilisateur.
 *
 * - Les comptes fédérés (RENATER via OIDC ou Shibboleth) n'ont pas de mot de passe :
 *   ils sont identifiés par federationId (eduPersonPrincipalName ou "sub" OIDC).
 * - Les comptes locaux (mot de passe) servent à l'amorçage et aux personnes hors fédération.
 * - ROLE_ADMIN est global ; les rôles "responsable" / "utilisateur" sont portés
 *   par SiteMembership, site par site.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column(length: 120)]
    private string $firstname = '';

    #[ORM\Column(length: 120)]
    private string $lastname = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $affiliation = null;

    #[ORM\Column(length: 19, nullable: true)]
    private ?string $orcid = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    #[ORM\Column(length: 255, unique: true, nullable: true)]
    private ?string $federationId = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $identityProvider = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    /** @var Collection<int, SiteMembership> */
    #[ORM\OneToMany(targetEntity: SiteMembership::class, mappedBy: 'user', orphanRemoval: true)]
    private Collection $memberships;

    public function __construct(string $email)
    {
        $this->email = mb_strtolower(trim($email));
        $this->createdAt = new \DateTimeImmutable();
        $this->memberships = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): static { $this->email = mb_strtolower(trim($email)); return $this; }
    public function getUserIdentifier(): string { return $this->email; }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_values(array_unique($roles));
    }
    public function setRoles(array $roles): static { $this->roles = array_values(array_unique($roles)); return $this; }
    public function isAdmin(): bool { return \in_array('ROLE_ADMIN', $this->roles, true); }
    public function setAdmin(bool $admin): static
    {
        $roles = array_diff($this->roles, ['ROLE_ADMIN']);
        if ($admin) { $roles[] = 'ROLE_ADMIN'; }
        $this->roles = array_values($roles);
        return $this;
    }

    public function getPassword(): ?string { return $this->password; }
    public function setPassword(?string $password): static { $this->password = $password; return $this; }
    public function eraseCredentials(): void {}

    public function getFirstname(): string { return $this->firstname; }
    public function setFirstname(string $v): static { $this->firstname = trim($v); return $this; }
    public function getLastname(): string { return $this->lastname; }
    public function setLastname(string $v): static { $this->lastname = trim($v); return $this; }
    public function getDisplayName(): string
    {
        $n = trim($this->firstname . ' ' . $this->lastname);
        return $n !== '' ? $n : $this->email;
    }
    public function getAffiliation(): ?string { return $this->affiliation; }
    public function setAffiliation(?string $v): static { $this->affiliation = $v ?: null; return $this; }
    public function getOrcid(): ?string { return $this->orcid; }
    public function setOrcid(?string $v): static { $this->orcid = $v ?: null; return $this; }
    public function getFederationId(): ?string { return $this->federationId; }
    public function setFederationId(?string $v): static { $this->federationId = $v; return $this; }
    public function getIdentityProvider(): ?string { return $this->identityProvider; }
    public function setIdentityProvider(?string $v): static { $this->identityProvider = $v; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $v): static { $this->active = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getLastLoginAt(): ?\DateTimeImmutable { return $this->lastLoginAt; }
    public function touchLogin(): static { $this->lastLoginAt = new \DateTimeImmutable(); return $this; }

    /** @return Collection<int, SiteMembership> */
    public function getMemberships(): Collection { return $this->memberships; }

    public function getMembership(Site $site): ?SiteMembership
    {
        foreach ($this->memberships as $m) {
            if ($m->getSite()->getId() === $site->getId()) {
                return $m;
            }
        }
        return null;
    }
}
