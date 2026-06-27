<?php

namespace App\Twig;

use App\Entity\ResearchProject;
use App\Service\Project\ResearchProjectManager;
use App\Service\Project\SubProjectManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    public function __construct(
        private Security $security,
        private ResearchProjectManager $rpManager,
        private SubProjectManager $spManager,
        private RequestStack $requestStack
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('get_user_research_projects', [$this, 'getUserResearchProjects']),
            new TwigFunction('get_active_research_project', [$this, 'getActiveResearchProject']),
            new TwigFunction('get_subproject_counts', [$this, 'getSubProjectCounts']),
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

    public function getSubProjectCounts(): array
    {
        $user = $this->security->getUser();
        if (!$user) {
            return ['reading' => 0, 'literature' => 0, 'writing' => 0, 'thesis' => 0];
        }

        $activeRp = $this->getActiveResearchProject();
        $counts = ['reading' => 0, 'literature' => 0, 'writing' => 0, 'thesis' => 0];

        if ($activeRp) {
            $subProjects = $this->spManager->getSubProjectsForProject($activeRp);
        } else {
            $subProjects = $this->spManager->getOrphanSubProjectsForUser($user);
        }

        foreach ($subProjects as $sp) {
            $type = $sp->getType();
            if ($type === 'literature_review') {
                $type = 'literature';
            }
            if (isset($counts[$type])) {
                $counts[$type]++;
            }
        }

        return $counts;
    }
}
