<?php

namespace App\Entity;

use App\Repository\DailyMetricsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DailyMetricsRepository::class)]
class DailyMetrics
{
    #[ORM\Id]
    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $date = null;

    #[ORM\Column]
    private ?int $activeUsers = 0;

    #[ORM\Column]
    private ?int $newRegistrations = 0;

    #[ORM\Column]
    private ?int $iaRequestsTotal = 0;

    #[ORM\Column]
    private ?int $iaRequestsLiterature = 0;

    #[ORM\Column]
    private ?int $iaRequestsReading = 0;

    #[ORM\Column]
    private ?int $iaRequestsWriting = 0;

    #[ORM\Column]
    private ?int $iaRequestsThesis = 0;

    #[ORM\Column]
    private ?int $filesUploaded = 0;

    #[ORM\Column]
    private ?int $avgDeepseekResponseMs = 0;

    #[ORM\Column]
    private ?int $errorCount = 0;

    #[ORM\Column]
    private ?int $exportsCount = 0;

    public function getDate(): ?\DateTimeInterface
    {
        return $this->date;
    }

    public function setDate(\DateTimeInterface $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getActiveUsers(): ?int
    {
        return $this->activeUsers;
    }

    public function setActiveUsers(int $activeUsers): static
    {
        $this->activeUsers = $activeUsers;

        return $this;
    }

    public function getNewRegistrations(): ?int
    {
        return $this->newRegistrations;
    }

    public function setNewRegistrations(int $newRegistrations): static
    {
        $this->newRegistrations = $newRegistrations;

        return $this;
    }

    public function getIaRequestsTotal(): ?int
    {
        return $this->iaRequestsTotal;
    }

    public function setIaRequestsTotal(int $iaRequestsTotal): static
    {
        $this->iaRequestsTotal = $iaRequestsTotal;

        return $this;
    }

    public function getIaRequestsLiterature(): ?int
    {
        return $this->iaRequestsLiterature;
    }

    public function setIaRequestsLiterature(int $iaRequestsLiterature): static
    {
        $this->iaRequestsLiterature = $iaRequestsLiterature;

        return $this;
    }

    public function getIaRequestsReading(): ?int
    {
        return $this->iaRequestsReading;
    }

    public function setIaRequestsReading(int $iaRequestsReading): static
    {
        $this->iaRequestsReading = $iaRequestsReading;

        return $this;
    }

    public function getIaRequestsWriting(): ?int
    {
        return $this->iaRequestsWriting;
    }

    public function setIaRequestsWriting(int $iaRequestsWriting): static
    {
        $this->iaRequestsWriting = $iaRequestsWriting;

        return $this;
    }

    public function getIaRequestsThesis(): ?int
    {
        return $this->iaRequestsThesis;
    }

    public function setIaRequestsThesis(int $iaRequestsThesis): static
    {
        $this->iaRequestsThesis = $iaRequestsThesis;

        return $this;
    }

    public function getFilesUploaded(): ?int
    {
        return $this->filesUploaded;
    }

    public function setFilesUploaded(int $filesUploaded): static
    {
        $this->filesUploaded = $filesUploaded;

        return $this;
    }

    public function getAvgDeepseekResponseMs(): ?int
    {
        return $this->avgDeepseekResponseMs;
    }

    public function setAvgDeepseekResponseMs(int $avgDeepseekResponseMs): static
    {
        $this->avgDeepseekResponseMs = $avgDeepseekResponseMs;

        return $this;
    }

    public function getErrorCount(): ?int
    {
        return $this->errorCount;
    }

    public function setErrorCount(int $errorCount): static
    {
        $this->errorCount = $errorCount;

        return $this;
    }

    public function getExportsCount(): ?int
    {
        return $this->exportsCount;
    }

    public function setExportsCount(int $exportsCount): static
    {
        $this->exportsCount = $exportsCount;

        return $this;
    }
}
