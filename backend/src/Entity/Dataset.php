<?php

namespace App\Entity;

use App\Repository\DatasetRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Jeu de données valorisé sur un site (pas encore publié sur ORDaR, ou lié à sa
 * publication ORDaR via $doi / $ordarUrl une fois publié).
 *
 * Les métadonnées communes sont des colonnes ; les champs spécifiques au site
 * sont dans $properties (JSONB), validés contre Site::$fields.
 */
#[ORM\Entity(repositoryClass: DatasetRepository::class)]
#[ORM\Index(name: 'idx_dataset_site_status', columns: ['site_id', 'status'])]
#[ORM\Index(name: 'idx_dataset_temporal', columns: ['temporal_start', 'temporal_end'])]
#[ORM\HasLifecycleCallbacks]
class Dataset
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_REJECTED = 'rejected';
    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_PUBLISHED, self::STATUS_REJECTED];

    public const ACCESS_OPEN = 'open';
    public const ACCESS_RESTRICTED = 'restricted';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Site $site;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $owner = null;

    #[ORM\Column(length: 500)]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $keywords = [];

    /** @var list<array{name:string, affiliation?:string, orcid?:string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $authors = [];

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $license = null;

    #[ORM\Column(length: 20)]
    private string $accessRight = self::ACCESS_OPEN;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $language = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reviewComment = null;

    /** Début de la période couverte (ou date unique) */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $temporalStart = null;

    /** Fin de la période couverte (null = date ponctuelle) */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $temporalEnd = null;

    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $properties = [];

    /** DOI ORDaR une fois le jeu publié dans l'entrepôt */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $doi = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $ordarUrl = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    /** @var Collection<int, SamplingPoint> */
    #[ORM\OneToMany(targetEntity: SamplingPoint::class, mappedBy: 'dataset', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $points;

    /** @var Collection<int, DatasetFile> */
    #[ORM\OneToMany(targetEntity: DatasetFile::class, mappedBy: 'dataset', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $files;

    public function __construct(Site $site, ?User $owner)
    {
        $this->uuid = Uuid::v7();
        $this->site = $site;
        $this->owner = $owner;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->points = new ArrayCollection();
        $this->files = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): Uuid { return $this->uuid; }
    public function getSite(): Site { return $this->site; }
    public function getOwner(): ?User { return $this->owner; }
    public function setOwner(?User $u): static { $this->owner = $u; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $t): static { $this->title = $t; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): static { $this->description = $d; return $this; }
    public function getKeywords(): array { return $this->keywords; }
    public function setKeywords(array $k): static { $this->keywords = array_values($k); return $this; }
    public function getAuthors(): array { return $this->authors; }
    public function setAuthors(array $a): static { $this->authors = array_values($a); return $this; }
    public function getLicense(): ?string { return $this->license; }
    public function setLicense(?string $l): static { $this->license = $l; return $this; }
    public function getAccessRight(): string { return $this->accessRight; }
    public function setAccessRight(string $a): static { $this->accessRight = $a; return $this; }
    public function getLanguage(): ?string { return $this->language; }
    public function setLanguage(?string $l): static { $this->language = $l; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): static
    {
        $this->status = $s;
        if ($s === self::STATUS_PUBLISHED && $this->publishedAt === null) {
            $this->publishedAt = new \DateTimeImmutable();
        }
        return $this;
    }
    public function isPublished(): bool { return $this->status === self::STATUS_PUBLISHED; }
    public function getReviewComment(): ?string { return $this->reviewComment; }
    public function setReviewComment(?string $c): static { $this->reviewComment = $c; return $this; }
    public function getTemporalStart(): ?\DateTimeImmutable { return $this->temporalStart; }
    public function setTemporalStart(?\DateTimeImmutable $d): static { $this->temporalStart = $d; return $this; }
    public function getTemporalEnd(): ?\DateTimeImmutable { return $this->temporalEnd; }
    public function setTemporalEnd(?\DateTimeImmutable $d): static { $this->temporalEnd = $d; return $this; }
    public function getProperties(): array { return $this->properties; }
    public function setProperties(array $p): static { $this->properties = $p; return $this; }
    public function getDoi(): ?string { return $this->doi; }
    public function setDoi(?string $d): static { $this->doi = $d ?: null; return $this; }
    public function getOrdarUrl(): ?string { return $this->ordarUrl; }
    public function setOrdarUrl(?string $u): static { $this->ordarUrl = $u ?: null; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }
    public function getUpdatedBy(): ?User { return $this->updatedBy; }
    public function setUpdatedBy(?User $u): static { $this->updatedBy = $u; return $this; }

    /** @return Collection<int, SamplingPoint> */
    public function getPoints(): Collection { return $this->points; }
    public function addPoint(SamplingPoint $p): static
    {
        if (!$this->points->contains($p)) {
            $this->points->add($p);
            $p->setDataset($this);
        }
        return $this;
    }
    public function removePoint(SamplingPoint $p): static { $this->points->removeElement($p); return $this; }

    /** @return Collection<int, DatasetFile> */
    public function getFiles(): Collection { return $this->files; }
    public function addFile(DatasetFile $f): static
    {
        if (!$this->files->contains($f)) {
            $this->files->add($f);
            $f->setDataset($this);
        }
        return $this;
    }
    public function removeFile(DatasetFile $f): static { $this->files->removeElement($f); return $this; }
}
