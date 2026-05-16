<?php

namespace App\MessageHandler;

use App\Message\ProcessDocumentMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ProcessDocumentMessageHandler
{
    public function __invoke(ProcessDocumentMessage $message)
    {
        // TODO: Implémenter la logique de traitement du document via IA
        $documentId = $message->getDocumentId();
    }
}
