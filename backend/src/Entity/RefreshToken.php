<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Refresh token opaque (seul son hash SHA-256 est stocké), rotatif à chaque usage. */
#[ORM\Entity]
#[ORM\Index(name: 'idx_refresh_hash', columns: ['token_hash'])]
class RefreshToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    public function __construct(User $user, string $tokenHash, \DateTimeImmutable $expiresAt)
    {
        $this->user = $user;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
    }

    public function getUser(): User { return $this->user; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function isExpired(): bool { return $this->expiresAt < new \DateTimeImmutable(); }
}
