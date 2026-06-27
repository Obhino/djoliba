<?php

namespace App\EventSubscriber;

use App\Entity\AdminLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Event\AfterEntityDeletedEvent;
use EasyCorp\Bundle\EasyAdminBundle\Event\AfterEntityPersistedEvent;
use EasyCorp\Bundle\EasyAdminBundle\Event\AfterEntityUpdatedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class AdminActivitySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AfterEntityPersistedEvent::class => 'onPersist',
            AfterEntityUpdatedEvent::class => 'onUpdate',
            AfterEntityDeletedEvent::class => 'onDelete',
        ];
    }

    private function logAction(string $action, object $entity): void
    {
        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();

        if (!$user instanceof User) {
            return;
        }

        $log = new AdminLog();
        $log->setAdmin($user);
        $log->setAction($action);
        
        $className = (new \ReflectionClass($entity))->getShortName();
        $entityId = method_exists($entity, 'getId') ? $entity->getId() : 'unknown';
        $log->setTarget(sprintf('%s #%s', $className, $entityId));
        
        $request = $this->requestStack->getCurrentRequest();
        if ($request) {
            $log->setIpAddress($request->getClientIp());
        }

        // We use a separate persist/flush to ensure logs are written
        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }

    public function onPersist(AfterEntityPersistedEvent $event): void
    {
        $this->logAction('CREATE', $event->getEntityInstance());
    }

    public function onUpdate(AfterEntityUpdatedEvent $event): void
    {
        $this->logAction('UPDATE', $event->getEntityInstance());
    }

    public function onDelete(AfterEntityDeletedEvent $event): void
    {
        $this->logAction('DELETE', $event->getEntityInstance());
    }
}
