<?php

namespace App\Controller;

use App\Service\Project\ProjectManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class HubController extends AbstractController
{
    public function __construct(
        private ProjectManager $projectManager
    ) {}

    private function checkAccess(): ?Response
    {
        if (!$this->getUser() && !$this->container->get('request_stack')->getCurrentRequest()->getSession()->get('is_test_mode')) {
            return $this->redirectToRoute('app_login');
        }
        return null;
    }

    private function getFilteredProjects(string $type): array
    {
        $user = $this->getUser();
        if ($user) {
            return $this->projectManager->getUserProjects($user, $type);
        }

        $session = $this->container->get('request_stack')->getCurrentRequest()->getSession();
        $projects = $session->get('test_projects', []);
        return array_values(array_filter($projects, fn($p) => isset($p['type']) && $p['type'] === $type));
    }

    #[Route('/literature', name: 'app_literature_search')]
    public function literature(): Response
    {
        if ($res = $this->checkAccess()) return $res;
        return $this->render('hub/literature.html.twig');
    }

    #[Route('/reading', name: 'app_reading_hub')]
    public function reading(): Response
    {
        if ($res = $this->checkAccess()) return $res;
        $projects = $this->getFilteredProjects('reading');
        return $this->render('hub/generic_hub.html.twig', [
            'title' => 'Mes projets de lecture',
            'subtitle' => 'Analysez et interagissez avec vos documents PDF.',
            'projects' => $projects,
            'type' => 'reading',
            'icon' => '<svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5s3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" /></svg>'
        ]);
    }

    #[Route('/synthesis', name: 'app_project_hub')]
    public function synthesis(): Response
    {
        if ($res = $this->checkAccess()) return $res;
        $projects = $this->getFilteredProjects('literature_review');
        return $this->render('hub/generic_hub.html.twig', [
            'title' => 'Mes projets de synthèse',
            'subtitle' => 'Croisez les informations de plusieurs sources.',
            'projects' => $projects,
            'type' => 'literature_review',
            'icon' => '<svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" /></svg>'
        ]);
    }

    #[Route('/writing', name: 'app_writing_hub')]
    public function writing(): Response
    {
        if ($res = $this->checkAccess()) return $res;
        $projects = $this->getFilteredProjects('writing');
        return $this->render('hub/generic_hub.html.twig', [
            'title' => "Mes projets d'écriture",
            'subtitle' => 'Rédigez vos articles avec l\'aide de l\'IA.',
            'projects' => $projects,
            'type' => 'writing',
            'icon' => '<svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>'
        ]);
    }

    #[Route('/thesis', name: 'app_thesis_hub')]
    public function thesis(): Response
    {
        if ($res = $this->checkAccess()) return $res;
        $projects = $this->getFilteredProjects('thesis');
        return $this->render('hub/generic_hub.html.twig', [
            'title' => 'Mes projets thèse/mémoire',
            'subtitle' => 'Gérez la structure et la rédaction de vos travaux longs.',
            'projects' => $projects,
            'type' => 'thesis',
            'icon' => '<svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l9-5-9-5-9 5 9 5z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z" /></svg>'
        ]);
    }
}
