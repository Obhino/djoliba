<?php

namespace App\Service\Project;

use App\Entity\ResearchProject;
use App\Entity\User;
use App\Repository\ResearchProjectRepository;
use Symfony\Component\HttpFoundation\RequestStack;

class ProjectSwitcher
{
    public function __construct(
        private RequestStack $requestStack,
        private ResearchProjectRepository $rpRepository
    ) {}

    /**
     * Définit le projet de recherche actif en session.
     */
    public function setActiveProject(?User $user, ?ResearchProject $project): void
    {
        try {
            $session = $this->requestStack->getSession();
            if ($project === null) {
                $session->remove('active_research_project_id');
            } else {
                $session->set('active_research_project_id', $project->getId());
            }
        } catch (\Exception $e) {
            // Ignorer silencieusement si la session n'est pas accessible (ex: CLI / tests)
        }
    }

    /**
     * Récupère le projet de recherche actif depuis la session.
     */
    public function getActiveProject(?User $user = null): ?ResearchProject
    {
        try {
            $session = $this->requestStack->getSession();
            $id = $session->get('active_research_project_id');
            if (!$id) {
                return null;
            }

            return $this->rpRepository->find($id);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Désactive le projet de recherche actif en session.
     */
    public function clearActiveProject(?User $user = null): void
    {
        try {
            $session = $this->requestStack->getSession();
            $session->remove('active_research_project_id');
        } catch (\Exception $e) {
            // Ignorer silencieusement si la session n'est pas accessible (ex: CLI / tests)
        }
    }
}
