<?php

namespace App\Entity;

use App\Repository\EditorInteractionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: EditorInteractionRepository::class)]
#[ORM\Index(columns: ['sub_project_id'])]
#[ORM\Index(columns: ['user_id'])]
#[ORM\Index(columns: ['created_at'])]
class EditorInteraction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['editor_interaction:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SubProject::class)]
    #[ORM\JoinColumn(name: 'sub_project_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    #[Groups(['editor_interaction:read'])]
    private ?SubProject $subProject = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['editor_interaction:read'])]
    private ?User $user = null;

    #[ORM\Column(type: 'string', length: 50)]
    #[Groups(['editor_interaction:read'])]
    private ?string $action = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['editor_interaction:read'])]
    private ?string $selectedText = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['editor_interaction:read'])]
    private ?string $suggestion = null;

    #[ORM\Column(type: 'boolean', nullable: true)]
    #[Groups(['editor_interaction:read'])]
    private ?bool $accepted = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['editor_interaction:read'])]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubProject(): ?SubProject
    {
        return $this->subProject;
    }

    public function setSubProject(?SubProject $subProject): static
    {
        $this->subProject = $subProject;

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

    public function getAction(): ?string
    {
        return $this->action;
    }

    public function setAction(string $action): static
    {
        $this->action = $action;

        return $this;
    }

    public function getSelectedText(): ?string
    {
        return $this->selectedText;
    }

    public function setSelectedText(?string $selectedText): static
    {
        $this->selectedText = $selectedText;

        return $this;
    }

    public function getSuggestion(): ?string
    {
        return $this->suggestion;
    }

    public function setSuggestion(?string $suggestion): static
    {
        $this->suggestion = $suggestion;

        return $this;
    }

    public function isAccepted(): ?bool
    {
        return $this->accepted;
    }

    public function setAccepted(?bool $accepted): static
    {
        $this->accepted = $accepted;

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
