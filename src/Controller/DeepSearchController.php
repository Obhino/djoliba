<?php

namespace App\Controller;

use App\Service\Project\ProjectManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class DeepSearchController extends AbstractController
{
    public function __construct(
        private ProjectManager $projectManager
    ) {}

    #[IsGranted('ROLE_USER')]
    #[Route('/project/{id}/deep-search', name: 'app_project_deep_search')]
    public function deepSearch(int $id, Request $request): Response
    {
        $project = $this->projectManager->getProject($id);

        if (!$project || $project->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException('Projet non trouvé.');
        }

        $query = $request->query->get('query', $project->getName());

        return $this->render('project/deep_search.html.twig', [
            'project' => $project,
            'initial_query' => $query,
        ]);
    }
}
