<?php

namespace App\MessageHandler;

use App\Message\ExportProjectMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ExportProjectMessageHandler
{
    public function __invoke(ExportProjectMessage $message)
    {
        // TODO: Implémenter la logique d'export du projet (ZIP)
        $projectId = $message->getProjectId();
    }
}
