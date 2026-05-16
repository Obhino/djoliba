<?php

namespace App\Message;

/**
 * Message déclenché automatiquement après chaque persistance d'un Document (via DocumentPostPersistSubscriber).
 * Transporte l'ID du document à traiter ainsi que le projet associé.
 *
 * Ce message est routé vers le transport "async" (Doctrine) dans messenger.yaml.
 */
final class ProcessDocumentMessage
{
    public function __construct(
        private readonly int $documentId,
        private readonly int $projectId,
    ) {
    }

    public function getDocumentId(): int
    {
        return $this->documentId;
    }

    public function getProjectId(): int
    {
        return $this->projectId;
    }
}
