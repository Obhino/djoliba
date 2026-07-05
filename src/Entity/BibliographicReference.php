<?php

namespace App\Entity;

use App\Repository\BibliographicReferenceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BibliographicReferenceRepository::class)]
#[ORM\Table(name: 'bibliographic_reference')]
#[ORM\Index(columns: ['user_id'])]
#[ORM\Index(columns: ['cite_key'])]
class BibliographicReference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'bibliographicReferences')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 100)]
    private ?string $citeKey = null;

    #[ORM\Column(length: 30, options: ['default' => 'misc'])]
    private ?string $entryType = 'misc';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $authors = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $year = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $journal = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $doi = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $rawData = null;

    #[ORM\Column(length: 20, options: ['default' => 'bib_file'])]
    private ?string $source = 'bib_file';

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $zoteroKey = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getCiteKey(): ?string
    {
        return $this->citeKey;
    }

    public function setCiteKey(string $citeKey): static
    {
        $this->citeKey = $citeKey;
        return $this;
    }

    public function getEntryType(): ?string
    {
        return $this->entryType;
    }

    public function setEntryType(string $entryType): static
    {
        $this->entryType = $entryType;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getAuthors(): ?string
    {
        return $this->authors;
    }

    public function setAuthors(?string $authors): static
    {
        $this->authors = $authors;
        return $this;
    }

    public function getYear(): ?string
    {
        return $this->year;
    }

    public function setYear(?string $year): static
    {
        $this->year = $year;
        return $this;
    }

    public function getJournal(): ?string
    {
        return $this->journal;
    }

    public function setJournal(?string $journal): static
    {
        $this->journal = $journal;
        return $this;
    }

    public function getDoi(): ?string
    {
        return $this->doi;
    }

    public function setDoi(?string $doi): static
    {
        $this->doi = $doi;
        return $this;
    }

    public function getRawData(): ?array
    {
        return $this->rawData;
    }

    public function setRawData(?array $rawData): static
    {
        $this->rawData = $rawData;
        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;
        return $this;
    }

    public function getZoteroKey(): ?string
    {
        return $this->zoteroKey;
    }

    public function setZoteroKey(?string $zoteroKey): static
    {
        $this->zoteroKey = $zoteroKey;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }
}
