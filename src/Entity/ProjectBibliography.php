<?php

namespace App\Entity;

use App\Repository\ProjectBibliographyRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProjectBibliographyRepository::class)]
#[ORM\Table(name: 'project_bibliography')]
#[ORM\Index(columns: ['research_project_id'])]
class ProjectBibliography
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: ResearchProject::class, inversedBy: 'projectBibliography')]
    #[ORM\JoinColumn(name: 'research_project_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?ResearchProject $researchProject = null;

    /**
     * @var Collection<int, BibliographicReference>
     */
    #[ORM\ManyToMany(targetEntity: BibliographicReference::class)]
    #[ORM\JoinTable(name: 'project_bibliography_bibliographic_reference')]
    #[ORM\JoinColumn(name: 'project_bibliography_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'bibliographic_reference_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $references;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->references = new ArrayCollection();
        $this->createdAt = new \DateTime();
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

    /**
     * @return Collection<int, BibliographicReference>
     */
    public function getReferences(): Collection
    {
        return $this->references;
    }

    public function addReference(BibliographicReference $reference): static
    {
        if (!$this->references->contains($reference)) {
            $this->references->add($reference);
        }
        return $this;
    }

    public function removeReference(BibliographicReference $reference): static
    {
        $this->references->removeElement($reference);
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
