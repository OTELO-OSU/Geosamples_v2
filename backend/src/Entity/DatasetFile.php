<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class DatasetFile
{
    public const KIND_DATA = 'data';
    public const KIND_PICTURE = 'picture';
    public const KIND_DOCUMENT = 'document';
    public const KINDS = [self::KIND_DATA, self::KIND_PICTURE, self::KIND_DOCUMENT];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Dataset::class, inversedBy: 'files')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Dataset $dataset = null;

    #[ORM\Column(length: 255)]
    private string $originalName;

    #[ORM\Column(length: 255)]
    private string $storedName;

    #[ORM\Column(length: 120)]
    private string $mimeType;

    #[ORM\Column(type: 'bigint')]
    private string $size;

    #[ORM\Column(length: 20)]
    private string $kind = self::KIND_DATA;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $originalName, string $storedName, string $mimeType, int $size, string $kind)
    {
        $this->originalName = $originalName;
        $this->storedName = $storedName;
        $this->mimeType = $mimeType;
        $this->size = (string) $size;
        $this->kind = $kind;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getDataset(): ?Dataset { return $this->dataset; }
    public function setDataset(?Dataset $d): static { $this->dataset = $d; return $this; }
    public function getOriginalName(): string { return $this->originalName; }
    public function getStoredName(): string { return $this->storedName; }
    public function getMimeType(): string { return $this->mimeType; }
    public function getSize(): int { return (int) $this->size; }
    public function getKind(): string { return $this->kind; }
    public function setKind(string $k): static { $this->kind = $k; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
