<?php

namespace App\Entity;

use App\Repository\SiteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A data management site (e.g. nometal, orne).
 *
 * All site customization is stored in this entity: text, theme, map extent and
 * basemaps, custom form fields, filters, and import template. Creating a site
 * only requires inserting a row; no redeployment is needed.
 */
#[ORM\Entity(repositoryClass: SiteRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Site
{
    public const DEFAULT_THEME = ['primary' => '#00616F', 'secondary' => '#3E4A59', 'accent' => '#E07A1F'];
    public const DEFAULT_MAP = [
        'center' => [46.6, 2.4],
        'zoom' => 6,
        'basemaps' => ['osm', 'ign-ortho', 'ign-plan', 'opentopo', 'esri-imagery'],
        'defaultBasemap' => 'osm',
        'clusterMaxZoom' => 14,
        'colorBy' => 'none',
    ];
    public const DEFAULT_SETTINGS = [
        'moderation' => true,
        'defaultAccessRight' => 'open',
        'defaultLicense' => 'CC-BY-4.0',
        'verticalDatum' => 'NGF-IGN69',
        'contactEmail' => null,
        'ordarUrl' => 'https://ordar.otelo.univ-lorraine.fr',
        'requireKeywords' => true,
        'allowJoinRequests' => true,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $slug;

    /** @var list<string> hostnames serving this site (e.g. nometal.otelo.univ-lorraine.fr) */
    #[ORM\Column(type: Types::JSON)]
    private array $hostnames = [];

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $subtitle = null;

    /** Short description (markdown) displayed on the map. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** "About" page (markdown). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $about = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoPath = null;

    #[ORM\Column(type: Types::JSON)]
    private array $theme = self::DEFAULT_THEME;

    #[ORM\Column(type: Types::JSON)]
    private array $map = self::DEFAULT_MAP;

    /**
     * Site-specific fields. Each entry:
     * {key, label, type, required, options[], unit, help, group, filter, popup, scope}
     * type   : text|textarea|number|integer|date|select|multiselect|boolean|url
     * filter: null|"select"|"range"|"text" (shown in the filter panel)
     * scope: "dataset" (default) or "point" (value per sampling point)
     */
    #[ORM\Column(type: Types::JSON)]
    private array $fields = [];

    /**
     * Personnalisation du template d'import : {columns: {key: {include, label}}, instructions}
     */
    #[ORM\Column(type: Types::JSON)]
    private array $importTemplate = [];

    #[ORM\Column(type: Types::JSON)]
    private array $settings = self::DEFAULT_SETTINGS;

    /** Visible in the portal and publicly accessible. */
    #[ORM\Column(options: ['default' => true])]
    private bool $published = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $slug, string $title)
    {
        $this->slug = $slug;
        $this->title = $title;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $s): static { $this->slug = $s; return $this; }
    public function getHostnames(): array { return $this->hostnames; }
    public function setHostnames(array $h): static
    {
        $this->hostnames = array_values(array_unique(array_filter(array_map(
            static fn ($x) => mb_strtolower(trim((string) $x)), $h
        ))));
        return $this;
    }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $t): static { $this->title = $t; return $this; }
    public function getSubtitle(): ?string { return $this->subtitle; }
    public function setSubtitle(?string $s): static { $this->subtitle = $s; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): static { $this->description = $d; return $this; }
    public function getAbout(): ?string { return $this->about; }
    public function setAbout(?string $a): static { $this->about = $a; return $this; }
    public function getLogoPath(): ?string { return $this->logoPath; }
    public function setLogoPath(?string $p): static { $this->logoPath = $p; return $this; }
    public function getTheme(): array { return array_replace(self::DEFAULT_THEME, $this->theme); }
    public function setTheme(array $t): static { $this->theme = $t; return $this; }
    public function getMap(): array { return array_replace(self::DEFAULT_MAP, $this->map); }
    public function setMap(array $m): static { $this->map = $m; return $this; }
    public function getFields(): array { return $this->fields; }
    public function setFields(array $f): static { $this->fields = array_values($f); return $this; }
    public function getImportTemplate(): array { return $this->importTemplate; }
    public function setImportTemplate(array $t): static { $this->importTemplate = $t; return $this; }
    public function getSettings(): array { return array_replace(self::DEFAULT_SETTINGS, $this->settings); }
    public function setSettings(array $s): static { $this->settings = $s; return $this; }
    public function setting(string $key): mixed { return $this->getSettings()[$key] ?? null; }
    public function isPublished(): bool { return $this->published; }
    public function setPublished(bool $p): static { $this->published = $p; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return array<string, array> fields indexed by key */
    public function getFieldMap(): array
    {
        $out = [];
        foreach ($this->fields as $f) {
            if (isset($f['key'])) {
                $out[$f['key']] = $f;
            }
        }
        return $out;
    }
}
