<?php

namespace App\Entity;

use App\Repository\DocumentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DocumentRepository::class)]
class Document
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Project $project = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 255)]
    private ?string $filename = null;

    #[ORM\Column(length: 512)]
    private ?string $storedPath = null;

    #[ORM\Column(length: 100)]
    private ?string $mimeType = null;

    #[ORM\Column]
    private ?int $sizeBytes = null;

    #[ORM\Column(options: ['default' => false])]
    private ?bool $isScanned = false;

    #[ORM\Column(options: ['default' => false])]
    private ?bool $virusFound = false;

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

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): static
    {
        $this->project = $project;

        return $this;
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

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): static
    {
        $this->filename = $filename;

        return $this;
    }

    public function getStoredPath(): ?string
    {
        return $this->storedPath;
    }

    public function setStoredPath(string $storedPath): static
    {
        $this->storedPath = $storedPath;

        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): static
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getSizeBytes(): ?int
    {
        return $this->sizeBytes;
    }

    public function setSizeBytes(int $sizeBytes): static
    {
        $this->sizeBytes = $sizeBytes;

        return $this;
    }

    public function setIsScanned(bool $isScanned): static
    {
        $this->isScanned = $isScanned;

        return $this;
    }

    public function isVirusFound(): ?bool
    {
        return $this->virusFound;
    }

    public function setVirusFound(bool $virusFound): static
    {
        $this->virusFound = $virusFound;

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

    public function isScanned(): ?bool
    {
        return $this->isScanned;
    }
}
