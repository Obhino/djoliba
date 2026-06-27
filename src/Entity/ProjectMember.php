<?php

namespace App\Entity;

use App\Repository\ProjectMemberRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: ProjectMemberRepository::class)]
#[ORM\Index(columns: ['research_project_id'])]
#[ORM\Index(columns: ['user_id'])]
class ProjectMember
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['project:read', 'research_project:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ResearchProject $researchProject = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    /**
     * Valeurs autorisées : owner, editor, viewer
     */
    #[ORM\Column(length: 20)]
    #[Groups(['project:read', 'research_project:read'])]
    private ?string $role = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['project:read', 'research_project:read'])]
    private ?\DateTimeInterface $invitedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['project:read', 'research_project:read'])]
    private ?\DateTimeInterface $joinedAt = null;

    /**
     * Valeurs autorisées : pending, active, declined
     */
    #[ORM\Column(length: 20, options: ['default' => 'pending'])]
    #[Groups(['project:read', 'research_project:read'])]
    private ?string $status = 'pending';

    private const VALID_ROLES = ['owner', 'editor', 'viewer'];
    private const VALID_STATUSES = ['pending', 'active', 'declined'];

    public function __construct()
    {
        $this->invitedAt = new \DateTime();
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

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        if (!in_array($role, self::VALID_ROLES, true)) {
            throw new \InvalidArgumentException(
                sprintf('Rôle invalide "%s". Valeurs autorisées : %s.', $role, implode(', ', self::VALID_ROLES))
            );
        }
        $this->role = $role;
        return $this;
    }

    public function getInvitedAt(): ?\DateTimeInterface
    {
        return $this->invitedAt;
    }

    public function setInvitedAt(\DateTimeInterface $invitedAt): static
    {
        $this->invitedAt = $invitedAt;
        return $this;
    }

    public function getJoinedAt(): ?\DateTimeInterface
    {
        return $this->joinedAt;
    }

    public function setJoinedAt(?\DateTimeInterface $joinedAt): static
    {
        $this->joinedAt = $joinedAt;
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
                sprintf('Statut d\'invitation invalide "%s". Valeurs autorisées : %s.', $status, implode(', ', self::VALID_STATUSES))
            );
        }
        $this->status = $status;
        return $this;
    }
}
