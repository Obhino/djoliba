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
    public function list(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $limit = $request->query->get('limit');
        
        if ($user) {
            $projects = $this->projectManager->getUserProjects($user);
        } else {
            // Mode Test : Récupération depuis la session
            $projects = $request->getSession()->get('test_projects', []);
        }
        
        if ($limit) {
            $projects = array_slice($projects, 0, (int)$limit);
        }

        return $this->json([
            'success' => true,
            'data' => $projects
        ], Response::HTTP_OK, [], ['groups' => 'project:read']);
    }

    #[Route('', name: 'api_projects_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        // Support pour FormData (utilisé lors de l'upload de fichiers)
        if (null === $data || !isset($data['name'])) {
            $data = [
                'name' => $request->request->get('name'),
                'type' => $request->request->get('type'),
            ];
        }

        if (!$data['name'] || !$data['type']) {
            return $this->json([
                'success' => false,
                'error' => ['code' => 400, 'message' => 'Nom et type sont requis']
            ], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->getUser();
        if ($user) {
            $project = $this->projectManager->createProject($user, $data['type'], $data['name']);
        } else {
            // Mode Test : Sauvegarde en session
            $session = $request->getSession();
            $projects = $session->get('test_projects', []);
            
            $project = [
                'id' => count($projects) + 1,
                'name' => $data['name'],
                'type' => $data['type'],
                'createdAt' => (new \DateTime())->format('c'),
                'status' => 'active'
            ];
            
            array_unshift($projects, $project);
            $session->set('test_projects', $projects);
        }

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

    #[Route('/{id}/articles', name: 'api_projects_add_article', methods: ['POST'])]
    public function addArticle(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        $isTestMode = $request->getSession()->get('is_test_mode');

        if (!$user && !$isTestMode) {
            return $this->json(['success' => false, 'error' => ['message' => 'Non autorisé']], 401);
        }

        $articleData = json_decode($request->getContent(), true);
        if (!$articleData) {
            return $this->json(['success' => false, 'error' => ['message' => 'Données invalides']], 400);
        }

        if ($user) {
            $project = $this->projectManager->getProject($id);
            if (!$project || $project->getUser() !== $user) {
                return $this->json(['success' => false, 'error' => ['message' => 'Projet non trouvé']], 404);
            }
            
            $currentMetadata = $project->getMetadata() ?? [];
            if (!isset($currentMetadata['articles'])) $currentMetadata['articles'] = [];
            $currentMetadata['articles'][] = $articleData;
            $this->projectManager->updateProject($project, ['metadata' => $currentMetadata]);
        } else {
            $session = $request->getSession();
            $projects = $session->get('test_projects', []);
            $found = false;
            foreach ($projects as &$p) {
                if ($p['id'] == $id) {
                    if (!isset($p['metadata'])) $p['metadata'] = [];
                    if (!isset($p['metadata']['articles'])) $p['metadata']['articles'] = [];
                    $p['metadata']['articles'][] = $articleData;
                    $found = true;
                    break;
                }
            }
            if (!$found) return $this->json(['success' => false, 'error' => ['message' => 'Projet non trouvé']], 404);
            $session->set('test_projects', $projects);
        }

        return $this->json(['success' => true]);
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
