<?php

namespace App\Entity;

use App\Repository\InteractionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InteractionRepository::class)]
#[ORM\Index(columns: ['project_id'])]
class Interaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Project $project = null;

    /**
     * Valeurs autorisées : literature_review, reading_chat, writing_check,
     * writing_suggest_journal, thesis_assist
     */
    #[ORM\Column(type: 'string', length: 30)]
    private ?string $type = null;

    private const VALID_TYPES = [
        'literature_review',
        'reading_chat',
        'writing_check',
        'writing_suggest_journal',
        'thesis_assist',
        'reading_synthesis',
    ];

    #[ORM\Column(type: Types::TEXT)]
    private ?string $userPrompt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $aiResponse = null;

    #[ORM\Column(nullable: true)]
    private ?int $responseTimeMs = null;

    #[ORM\Column(nullable: true)]
    private ?int $tokensUsed = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $costCfa = null;

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

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        if (!in_array($type, self::VALID_TYPES, true)) {
            throw new \InvalidArgumentException(
                sprintf('Type invalide "%s". Valeurs acceptées : %s.', $type, implode(', ', self::VALID_TYPES))
            );
        }
        $this->type = $type;

        return $this;
    }

    public function getUserPrompt(): ?string
    {
        return $this->userPrompt;
    }

    public function setUserPrompt(string $userPrompt): static
    {
        $this->userPrompt = $userPrompt;

        return $this;
    }

    public function getAiResponse(): ?string
    {
        return $this->aiResponse;
    }

    public function setAiResponse(?string $aiResponse): static
    {
        $this->aiResponse = $aiResponse;

        return $this;
    }

    public function getResponseTimeMs(): ?int
    {
        return $this->responseTimeMs;
    }

    public function setResponseTimeMs(?int $responseTimeMs): static
    {
        $this->responseTimeMs = $responseTimeMs;

        return $this;
    }

    public function getTokensUsed(): ?int
    {
        return $this->tokensUsed;
    }

    public function setTokensUsed(?int $tokensUsed): static
    {
        $this->tokensUsed = $tokensUsed;

        return $this;
    }

    public function getCostCfa(): ?string
    {
        return $this->costCfa;
    }

    public function setCostCfa(?string $costCfa): static
    {
        $this->costCfa = $costCfa;

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
