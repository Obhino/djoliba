<?php

namespace App\Twig;

use App\Entity\ResearchProject;
use App\Service\Project\ResearchProjectManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    public function __construct(
        private Security $security,
        private ResearchProjectManager $rpManager,
        private RequestStack $requestStack
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('get_user_research_projects', [$this, 'getUserResearchProjects']),
            new TwigFunction('get_active_research_project', [$this, 'getActiveResearchProject']),
        ];
    }

    /**
     * @return ResearchProject[]
     */
    public function getUserResearchProjects(): array
    {
        $user = $this->security->getUser();
        if (!$user) {
            return [];
        }
        return $this->rpManager->getUserResearchProjects($user);
    }

    public function getActiveResearchProject(): ?ResearchProject
    {
        $user = $this->security->getUser();
        if (!$user) {
            return null;
        }

        $request = $this->requestStack->getCurrentRequest();
        if (!$request || !$request->hasSession()) {
            return null;
        }

        $session = $request->getSession();
        $activeId = $session->get('active_research_project_id');
        if (!$activeId) {
            return null;
        }

        $rp = $this->rpManager->getResearchProject((int) $activeId);
        if ($rp && $rp->getUser() === $user && $rp->getStatus() !== 'deleted') {
            return $rp;
        }

        return null;
    }
}
