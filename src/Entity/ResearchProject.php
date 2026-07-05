<?php

namespace App\Entity;

use App\Repository\ResearchProjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: ResearchProjectRepository::class)]
#[ORM\Index(columns: ['user_id'])]
class ResearchProject
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['project:read', 'research_project:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'researchProjects')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 255)]
    #[Groups(['project:read', 'research_project:read'])]
    private ?string $title = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['project:read', 'research_project:read'])]
    private bool $isTemplate = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['project:read', 'research_project:read'])]
    private ?string $description = null;

    /**
     * Valeurs autorisées : active, archived, deleted
     */
    #[ORM\Column(length: 10, options: ['default' => 'active'])]
    #[Groups(['project:read', 'research_project:read'])]
    private ?string $status = 'active';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['project:read', 'research_project:read'])]
    private ?string $synthesis = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['project:read', 'research_project:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['project:read', 'research_project:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    /**
     * @var Collection<int, Project>
     */
    #[ORM\OneToMany(targetEntity: Project::class, mappedBy: 'researchProject', cascade: ['persist'])]
    #[Groups(['research_project:read'])]
    private Collection $projects;

    /**
     * @var Collection<int, SubProject>
     */
    #[ORM\OneToMany(targetEntity: SubProject::class, mappedBy: 'researchProject', cascade: ['persist', 'remove'])]
    #[Groups(['research_project:read'])]
    private Collection $subProjects;

    #[ORM\OneToOne(mappedBy: 'researchProject', targetEntity: ProjectBibliography::class, cascade: ['persist', 'remove'])]
    private ?ProjectBibliography $projectBibliography = null;

    private const VALID_STATUSES = ['active', 'archived', 'deleted'];

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->projects = new ArrayCollection();
        $this->subProjects = new ArrayCollection();
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

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    #[Groups(['project:read', 'research_project:read'])]
    public function getName(): ?string
    {
        return $this->getTitle();
    }

    public function setName(string $name): static
    {
        return $this->setTitle($name);
    }

    public function isTemplate(): bool
    {
        return $this->isTemplate;
    }

    public function setIsTemplate(bool $isTemplate): static
    {
        $this->isTemplate = $isTemplate;
        return $this;
    }

    /**
     * @return Collection<int, SubProject>
     */
    public function getSubProjects(): Collection
    {
        return $this->subProjects;
    }

    public function addSubProject(SubProject $subProject): static
    {
        if (!$this->subProjects->contains($subProject)) {
            $this->subProjects->add($subProject);
            $subProject->setResearchProject($this);
        }
        return $this;
    }

    public function removeSubProject(SubProject $subProject): static
    {
        if ($this->subProjects->removeElement($subProject)) {
            if ($subProject->getResearchProject() === $this) {
                $subProject->setResearchProject(null);
            }
        }
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
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
                sprintf('Statut invalide "%s". Valeurs acceptées : %s.', $status, implode(', ', self::VALID_STATUSES))
            );
        }
        $this->status = $status;
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
            $project->setResearchProject($this);
        }
        return $this;
    }

    public function removeProject(Project $project): static
    {
        if ($this->projects->removeElement($project)) {
            if ($project->getResearchProject() === $this) {
                $project->setResearchProject(null);
            }
        }
        return $this;
    }

    public function getSynthesis(): ?string
    {
        return $this->synthesis;
    }

    public function setSynthesis(?string $synthesis): static
    {
        $this->synthesis = $synthesis;
        return $this;
    }

    public function getProjectBibliography(): ?ProjectBibliography
    {
        return $this->projectBibliography;
    }

    public function setProjectBibliography(?ProjectBibliography $projectBibliography): static
    {
        // set the owning side of the relation if necessary
        if ($projectBibliography !== null && $projectBibliography->getResearchProject() !== $this) {
            $projectBibliography->setResearchProject($this);
        }

        $this->projectBibliography = $projectBibliography;
        return $this;
    }
}
