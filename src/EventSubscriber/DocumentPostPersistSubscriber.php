<?php

namespace App\EventSubscriber;

use App\Entity\Document;
use App\Message\ProcessDocumentMessage;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Déclenche automatiquement le traitement asynchrone d'un Document
 * dès qu'il est persisté en base de données.
 *
 * NOTE ARCHITECTURALE : Cet abonné écoute PostPersist de l'entité Document
 * (et non de Project), car ProcessDocumentMessage nécessite un documentId.
 * C'est à ce moment — après l'upload et la sauvegarde du Document — qu'on
 * connaît l'ID du fichier à traiter par l'IA (synthèse, extraction, etc.).
 *
 * Si vous souhaitez également réagir à la création d'un Project (ex: initialiser
 * une structure de chapitres vide), créez un second subscriber dédié.
 */
#[AsDoctrineListener(event: Events::postPersist)]
class DocumentPostPersistSubscriber
{
    public function __construct(private MessageBusInterface $messageBus)
    {
    }

    public function postPersist(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();

        // On s'assure que l'événement concerne bien un Document et pas une autre entité
        if (!$entity instanceof Document) {
            return;
        }

        // Dispatch du message dans la file d'attente asynchrone (doctrine://default)
        $this->messageBus->dispatch(new ProcessDocumentMessage($entity->getId()));
    }
}
