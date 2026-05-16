<?php

namespace App\MessageHandler;

use App\Message\ExportProjectMessage;
use App\Service\Project\ProjectExporterService;
use App\Service\Project\ProjectManager;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ExportProjectMessageHandler
{
    public function __construct(
        private ProjectManager $projectManager,
        private ProjectExporterService $exporterService
    ) {
    }

    public function __invoke(ExportProjectMessage $message)
    {
        $project = $this->projectManager->getProject($message->getProjectId());
        
        if (!$project) {
            return;
        }

        // En mode asynchrone, on génère le ZIP et on pourrait envoyer un email ou une notif
        // Pour l'instant, on lance simplement l'exportation
        $this->exporterService->exportToZip($project);
    }
}
