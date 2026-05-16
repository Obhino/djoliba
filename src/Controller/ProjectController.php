<?php

namespace App\Controller;

use App\Entity\Project;
use App\Service\Project\ProjectManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/projects')]
#[IsGranted('ROLE_USER')]
class ProjectController extends AbstractController
{
    private ProjectManager $projectManager;

    public function __construct(ProjectManager $projectManager)
    {
        $this->projectManager = $projectManager;
    }

    #[Route('', name: 'api_projects_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->getUser();
        $projects = $this->projectManager->getUserProjects($user);

        return $this->json([
            'success' => true,
            'data' => $projects
        ], Response::HTTP_OK, [], ['groups' => 'project:read']);
    }

    #[Route('', name: 'api_projects_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['name']) || !isset($data['type'])) {
            return $this->json([
                'success' => false,
                'error' => [
                    'code' => 400,
                    'message' => 'Nom et type sont requis'
                ]
            ], Response::HTTP_BAD_REQUEST);
        }

        $project = $this->projectManager->createProject(
            $this->getUser(),
            $data['type'],
            $data['name']
        );

        return $this->json([
            'success' => true,
            'data' => $project
        ], Response::HTTP_CREATED, [], ['groups' => 'project:read']);
    }

    #[Route('/{id}', name: 'api_projects_show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $project = $this->projectManager->getProject($id);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return $this->json([
                'success' => false,
                'error' => [
                    'code' => 404,
                    'message' => 'Projet non trouvé'
                ]
            ], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'success' => true,
            'data' => $project
        ], Response::HTTP_OK, [], ['groups' => 'project:read']);
    }

    #[Route('/{id}', name: 'api_projects_update', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $project = $this->projectManager->getProject($id);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return $this->json([
                'success' => false,
                'error' => [
                    'code' => 404,
                    'message' => 'Projet non trouvé'
                ]
            ], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        $updatedProject = $this->projectManager->updateProject($project, $data);

        return $this->json([
            'success' => true,
            'data' => $updatedProject
        ], Response::HTTP_OK, [], ['groups' => 'project:read']);
    }

    #[Route('/{id}', name: 'api_projects_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $project = $this->projectManager->getProject($id);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return $this->json([
                'success' => false,
                'error' => [
                    'code' => 404,
                    'message' => 'Projet non trouvé'
                ]
            ], Response::HTTP_NOT_FOUND);
        }

        $this->projectManager->deleteProject($project);

        return $this->json([
            'success' => true,
            'data' => null
        ], Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/export', name: 'api_projects_export', methods: ['GET'])]
    public function export(int $id): JsonResponse
    {
        // Pour l'instant, on retourne un placeholder car le ProjectExporter n'est pas encore implémenté
        return $this->json([
            'success' => true,
            'data' => [
                'message' => 'Exportation en cours de préparation',
                'project_id' => $id
            ]
        ]);
    }
}
