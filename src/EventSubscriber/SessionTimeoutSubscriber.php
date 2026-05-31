<?php

namespace App\EventSubscriber;

use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class SessionTimeoutSubscriber implements EventSubscriberInterface
{
    private const TIMEOUT_SECONDS = 3600; // 1 heure d'inactivité

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private ProjectRepository $projectRepository,
        private EntityManagerInterface $entityManager,
        private RouterInterface $router
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['onKernelRequest', 10]],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        $route = $request->attributes->get('_route');

        // 1. Enregistrement de l'identifiant du projet actif si on est sur une route projet
        if ($route && str_starts_with($route, 'app_project_')) {
            $projectId = $request->attributes->get('id');
            if ($projectId) {
                $session->set('active_project_id', (int) $projectId);
            }
        }

        // 2. Vérification de l'utilisateur connecté
        $token = $this->tokenStorage->getToken();
        $user = $token ? $token->getUser() : null;

        if (!$user) {
            return;
        }

        // Si l'utilisateur est déjà sur la page de connexion ou se déconnecte, on ignore
        if (in_array($route, ['app_login', 'app_logout'], true)) {
            return;
        }

        // 3. Gestion de l'inactivité
        $now = time();
        $lastActivity = $session->get('last_activity');

        if ($lastActivity !== null) {
            $inactiveTime = $now - $lastActivity;

            if ($inactiveTime > self::TIMEOUT_SECONDS) {
                // Inactivité trop longue : Sauvegarde du projet actif
                $activeProjectId = $session->get('active_project_id');
                if ($activeProjectId) {
                    $project = $this->projectRepository->find($activeProjectId);
                    if ($project && $project->getUser() === $user) {
                        $project->setUpdatedAt(new \DateTime());
                        $project->setLastAccessedAt(new \DateTime());
                        $this->entityManager->flush();
                    }
                }

                // Déconnexion & Invalidation de la session
                $session->invalidate();
                $this->tokenStorage->setToken(null);

                // Ajout d'un message flash d'avertissement
                $session->getFlashBag()->add('error', 'Votre session a été interrompue pour cause d\'inactivité. Votre projet en cours a été sauvegardé en toute sécurité.');

                // Redirection vers la page de connexion
                $response = new RedirectResponse($this->router->generate('app_login'));
                $event->setResponse($response);
                return;
            }
        }

        // Mise à jour du timestamp de dernière activité
        $session->set('last_activity', $now);
    }
}
