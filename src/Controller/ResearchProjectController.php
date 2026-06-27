<?php

namespace App\Controller;

use App\Entity\ResearchProject;
use App\Service\Project\ResearchProjectManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/research-projects')]
#[IsGranted('ROLE_USER')]
class ResearchProjectController extends AbstractController
{
    public function __construct(
        private ResearchProjectManager $rpManager
    ) {}

    #[Route('', name: 'api_research_projects_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->getUser();
        $projects = $this->rpManager->getUserResearchProjects($user);

        return $this->json([
            'success' => true,
            'data' => $projects
        ], Response::HTTP_OK, [], ['groups' => 'project:read']);
    }

    #[Route('', name: 'api_research_projects_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (empty($data['name'])) {
            return $this->json([
                'success' => false,
                'error' => ['code' => 400, 'message' => 'Le nom est requis.']
            ], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->getUser();
        $rp = $this->rpManager->createResearchProject($user, $data['name'], $data['description'] ?? null);

        // Si le paramètre 'select' est passé et vrai, on le définit automatiquement comme actif
        if (!empty($data['select']) && $request->hasSession()) {
            $request->getSession()->set('active_research_project_id', $rp->getId());
        }

        return $this->json([
            'success' => true,
            'data' => $rp
        ], Response::HTTP_CREATED, [], ['groups' => 'project:read']);
    }

    #[Route('/{id}', name: 'api_research_projects_show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $rp = $this->rpManager->getResearchProject($id);
        if (!$rp || $rp->getUser() !== $this->getUser() || $rp->getStatus() === 'deleted') {
            return $this->json([
                'success' => false,
                'error' => ['code' => 404, 'message' => 'Projet de recherche non trouvé.']
            ], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'success' => true,
            'data' => $rp
        ], Response::HTTP_OK, [], ['groups' => 'project:read']);
    }

    #[Route('/{id}', name: 'api_research_projects_update', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $rp = $this->rpManager->getResearchProject($id);
        if (!$rp || $rp->getUser() !== $this->getUser() || $rp->getStatus() === 'deleted') {
            return $this->json([
                'success' => false,
                'error' => ['code' => 404, 'message' => 'Projet de recherche non trouvé.']
            ], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        $updated = $this->rpManager->updateResearchProject($rp, $data);

        return $this->json([
            'success' => true,
            'data' => $updated
        ], Response::HTTP_OK, [], ['groups' => 'project:read']);
    }

    #[Route('/{id}', name: 'api_research_projects_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $rp = $this->rpManager->getResearchProject($id);
        if (!$rp || $rp->getUser() !== $this->getUser() || $rp->getStatus() === 'deleted') {
            return $this->json([
                'success' => false,
                'error' => ['code' => 404, 'message' => 'Projet de recherche non trouvé.']
            ], Response::HTTP_NOT_FOUND);
        }

        $this->rpManager->deleteResearchProject($rp);

        return $this->json([
            'success' => true,
            'data' => null
        ], Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/select', name: 'api_research_projects_select', methods: ['POST'])]
    public function select(int $id, Request $request): JsonResponse
    {
        $rp = $this->rpManager->getResearchProject($id);
        if (!$rp || $rp->getUser() !== $this->getUser() || $rp->getStatus() === 'deleted') {
            return $this->json([
                'success' => false,
                'error' => ['code' => 404, 'message' => 'Projet de recherche non trouvé.']
            ], Response::HTTP_NOT_FOUND);
        }

        if ($request->hasSession()) {
            $request->getSession()->set('active_research_project_id', $rp->getId());
        }

        return $this->json([
            'success' => true,
            'active_research_project_id' => $rp->getId()
        ]);
    }

    #[Route('/deselect-active', name: 'api_research_projects_deselect', methods: ['POST'], priority: 10)]
    public function deselect(Request $request): JsonResponse
    {
        if ($request->hasSession()) {
            $request->getSession()->remove('active_research_project_id');
        }

        return $this->json([
            'success' => true,
            'active_research_project_id' => null
        ]);
    }
}
