<?php

namespace App\Entity;

use App\Repository\SiteMembershipRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Rôle d'un utilisateur sur un site.
 *  - responsable : configure le site, gère les membres, valide/modifie tous les jeux du site
 *  - utilisateur : dépose, importe et modifie ses propres jeux de données
 * Une demande d'adhésion est une adhésion au statut "pending".
 */
#[ORM\Entity(repositoryClass: SiteMembershipRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_site_user', columns: ['site_id', 'user_id'])]
class SiteMembership
{
    public const ROLE_MANAGER = 'responsable';
    public const ROLE_CONTRIBUTOR = 'utilisateur';
    public const ROLES = [self::ROLE_MANAGER, self::ROLE_CONTRIBUTOR];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PENDING = 'pending';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Site $site;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'memberships')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20)]
    private string $role;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $message = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Site $site, User $user, string $role, string $status = self::STATUS_ACTIVE)
    {
        $this->site = $site;
        $this->user = $user;
        $this->role = $role;
        $this->status = $status;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSite(): Site { return $this->site; }
    public function getUser(): User { return $this->user; }
    public function getRole(): string { return $this->role; }
    public function setRole(string $r): static { $this->role = $r; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): static { $this->status = $s; return $this; }
    public function isActive(): bool { return $this->status === self::STATUS_ACTIVE; }
    public function isManager(): bool { return $this->isActive() && $this->role === self::ROLE_MANAGER; }
    public function getMessage(): ?string { return $this->message; }
    public function setMessage(?string $m): static { $this->message = $m; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
