<?php

namespace App\Entity;

use App\Repository\SubProjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: SubProjectRepository::class)]
#[ORM\Index(columns: ['research_project_id'])]
#[ORM\Index(columns: ['user_id'])]
#[ORM\Index(columns: ['type'])]
#[ORM\Index(columns: ['status'])]
class SubProject
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['project:read', 'research_project:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ResearchProject::class, inversedBy: 'subProjects')]
    #[ORM\JoinColumn(name: 'research_project_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['project:read'])]
    private ?ResearchProject $researchProject = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'subProjects')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    /**
     * Valeurs autorisées : reading, literature, writing, thesis
     */
    #[ORM\Column(length: 20)]
    #[Groups(['project:read', 'research_project:read'])]
    private ?string $type = null;

    #[ORM\Column(length: 255)]
    #[Groups(['project:read', 'research_project:read'])]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['project:read', 'research_project:read'])]
    private ?string $content = null;

    /**
     * Valeurs autorisées : active, archived, deleted
     */
    #[ORM\Column(length: 50, options: ['default' => 'active'])]
    #[Groups(['project:read', 'research_project:read'])]
    private ?string $status = 'active';

    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['project:read', 'research_project:read'])]
    private ?array $metadata = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['project:read', 'research_project:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['project:read', 'research_project:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    /**
     * @var Collection<int, Project>
     */
    #[ORM\OneToMany(targetEntity: Project::class, mappedBy: 'subProject', cascade: ['persist', 'remove'])]
    private Collection $projects;

    /**
     * @var Collection<int, Interaction>
     */
    #[ORM\OneToMany(targetEntity: Interaction::class, mappedBy: 'subProject', cascade: ['persist', 'remove'])]
    private Collection $interactions;

    /**
     * @var Collection<int, Document>
     */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'subProject', cascade: ['persist', 'remove'])]
    private Collection $documents;

    /**
     * @var Collection<int, Chapter>
     */
    #[ORM\OneToMany(targetEntity: Chapter::class, mappedBy: 'subProject', cascade: ['persist', 'remove'])]
    private Collection $chapters;

    /**
     * @var Collection<int, BibliographyEntry>
     */
    #[ORM\OneToMany(targetEntity: BibliographyEntry::class, mappedBy: 'subProject', cascade: ['persist', 'remove'])]
    private Collection $bibliographyEntries;

    private const VALID_TYPES = ['reading', 'literature', 'writing', 'thesis'];
    private const VALID_STATUSES = ['active', 'archived', 'deleted'];

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->projects = new ArrayCollection();
        $this->interactions = new ArrayCollection();
        $this->documents = new ArrayCollection();
        $this->chapters = new ArrayCollection();
        $this->bibliographyEntries = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getResearchProject(): ?ResearchProject
    {
        return $this->researchProject;
    }

    public function setResearchProject(?ResearchProject $researchProject): static
    {
        $this->researchProject = $researchProject;
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

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        if (!in_array($type, self::VALID_TYPES, true)) {
            throw new \InvalidArgumentException(
                sprintf('Type de sous-projet invalide "%s". Valeurs autorisées : %s.', $type, implode(', ', self::VALID_TYPES))
            );
        }
        $this->type = $type;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): static
    {
        $this->content = $content;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException(
                sprintf('Statut de sous-projet invalide "%s". Valeurs autorisées : %s.', $status, implode(', ', self::VALID_STATUSES))
            );
        }
        $this->status = $status;
        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;
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

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * @return Collection<int, Project>
     */
    public function getProjects(): Collection
    {
        return $this->projects;
    }

    public function addProject(Project $project): static
    {
        if (!$this->projects->contains($project)) {
            $this->projects->add($project);
            $project->setSubProject($this);
        }
        return $this;
    }

    public function removeProject(Project $project): static
    {
        if ($this->projects->removeElement($project)) {
            if ($project->getSubProject() === $this) {
                $project->setSubProject(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, Interaction>
     */
    public function getInteractions(): Collection
    {
        return $this->interactions;
    }

    public function addInteraction(Interaction $interaction): static
    {
        if (!$this->interactions->contains($interaction)) {
            $this->interactions->add($interaction);
            $interaction->setSubProject($this);
        }
        return $this;
    }

    public function removeInteraction(Interaction $interaction): static
    {
        if ($this->interactions->removeElement($interaction)) {
            if ($interaction->getSubProject() === $this) {
                $interaction->setSubProject(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, Document>
     */
    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    public function addDocument(Document $document): static
    {
        if (!$this->documents->contains($document)) {
            $this->documents->add($document);
            $document->setSubProject($this);
        }
        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document)) {
            if ($document->getSubProject() === $this) {
                $document->setSubProject(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, Chapter>
     */
    public function getChapters(): Collection
    {
        return $this->chapters;
    }

    public function addChapter(Chapter $chapter): static
    {
        if (!$this->chapters->contains($chapter)) {
            $this->chapters->add($chapter);
            $chapter->setSubProject($this);
        }
        return $this;
    }

    public function removeChapter(Chapter $chapter): static
    {
        if ($this->chapters->removeElement($chapter)) {
            if ($chapter->getSubProject() === $this) {
                $chapter->setSubProject(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, BibliographyEntry>
     */
    public function getBibliographyEntries(): Collection
    {
        return $this->bibliographyEntries;
    }

    public function addBibliographyEntry(BibliographyEntry $entry): static
    {
        if (!$this->bibliographyEntries->contains($entry)) {
            $this->bibliographyEntries->add($entry);
            $entry->setSubProject($this);
        }
        return $this;
    }

    public function removeBibliographyEntry(BibliographyEntry $entry): static
    {
        if ($this->bibliographyEntries->removeElement($entry)) {
            if ($entry->getSubProject() === $this) {
                $entry->setSubProject(null);
            }
        }
        return $this;
    }
}
