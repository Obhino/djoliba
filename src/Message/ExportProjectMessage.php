<?php

namespace App\Message;

class ExportProjectMessage
{
    public function __construct(
        private int $projectId,
        private string $format = 'zip',
        private string $jobId = ''
    ) {
    }

    public function getProjectId(): int
    {
        return $this->projectId;
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function getJobId(): string
    {
        return $this->jobId;
    }
}
