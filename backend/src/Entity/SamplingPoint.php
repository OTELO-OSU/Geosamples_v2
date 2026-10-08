<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Point de prélèvement / station, géolocalisé en 3 dimensions.
 *  - longitude / latitude : WGS84 (EPSG:4326), degrés décimaux
 *  - elevation            : altitude en mètres (négative = profondeur), dans verticalDatum
 *  - observedAt / observedEnd : date ou période propre au point (sinon celle du jeu)
 * Les coordonnées saisies dans un autre système (Lambert 93, UTM…) sont conservées
 * dans srcCrs / srcX / srcY pour traçabilité.
 */
#[ORM\Entity]
#[ORM\Index(name: 'idx_point_lat_lon', columns: ['latitude', 'longitude'])]
#[ORM\Index(name: 'idx_point_observed', columns: ['observed_at'])]
class SamplingPoint
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Dataset::class, inversedBy: 'points')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Dataset $dataset = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(type: Types::FLOAT)]
    private float $longitude;

    #[ORM\Column(type: Types::FLOAT)]
    private float $latitude;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $elevation = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $verticalDatum = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $observedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $observedEnd = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $srcCrs = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $srcX = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $srcY = null;

    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $properties = [];

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    public function __construct(float $longitude, float $latitude)
    {
        $this->longitude = $longitude;
        $this->latitude = $latitude;
    }

    public function getId(): ?int { return $this->id; }
    public function getDataset(): ?Dataset { return $this->dataset; }
    public function setDataset(?Dataset $d): static { $this->dataset = $d; return $this; }
    public function getName(): ?string { return $this->name; }
    public function setName(?string $n): static { $this->name = $n ?: null; return $this; }
    public function getLongitude(): float { return $this->longitude; }
    public function setLongitude(float $v): static { $this->longitude = $v; return $this; }
    public function getLatitude(): float { return $this->latitude; }
    public function setLatitude(float $v): static { $this->latitude = $v; return $this; }
    public function getElevation(): ?float { return $this->elevation; }
    public function setElevation(?float $v): static { $this->elevation = $v; return $this; }
    public function getVerticalDatum(): ?string { return $this->verticalDatum; }
    public function setVerticalDatum(?string $v): static { $this->verticalDatum = $v ?: null; return $this; }
    public function getObservedAt(): ?\DateTimeImmutable { return $this->observedAt; }
    public function setObservedAt(?\DateTimeImmutable $d): static { $this->observedAt = $d; return $this; }
    public function getObservedEnd(): ?\DateTimeImmutable { return $this->observedEnd; }
    public function setObservedEnd(?\DateTimeImmutable $d): static { $this->observedEnd = $d; return $this; }
    public function getSrcCrs(): ?string { return $this->srcCrs; }
    public function setSrcCrs(?string $v): static { $this->srcCrs = $v ?: null; return $this; }
    public function getSrcX(): ?float { return $this->srcX; }
    public function setSrcX(?float $v): static { $this->srcX = $v; return $this; }
    public function getSrcY(): ?float { return $this->srcY; }
    public function setSrcY(?float $v): static { $this->srcY = $v; return $this; }
    public function getProperties(): array { return $this->properties; }
    public function setProperties(array $p): static { $this->properties = $p; return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $p): static { $this->position = $p; return $this; }
}
