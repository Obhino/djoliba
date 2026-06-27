<?php

namespace App\Controller;

use App\Entity\Project;
use App\Service\Project\ProjectManager;
use App\Service\Project\ResearchProjectManager;
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
    private ResearchProjectManager $researchProjectManager;

    public function __construct(ProjectManager $projectManager, ResearchProjectManager $researchProjectManager)
    {
        $this->projectManager = $projectManager;
        $this->researchProjectManager = $researchProjectManager;
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
            $session = $request->hasSession() ? $request->getSession() : null;
            $projects = $session ? $session->get('test_projects', []) : [];
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
            $researchProject = null;
            // 1. Check if research_project_id is passed in the payload
            $rpId = isset($data['research_project_id']) ? (int)$data['research_project_id'] : (int)$request->request->get('research_project_id');
            if ($rpId > 0) {
                $researchProject = $this->researchProjectManager->getResearchProject($rpId);
            }
            // 2. Otherwise check session active project
            if (!$researchProject && $request->hasSession()) {
                $session = $request->getSession();
                $activeId = $session->get('active_research_project_id');
                if ($activeId) {
                    $researchProject = $this->researchProjectManager->getResearchProject((int)$activeId);
                }
            }
            // 3. Ensure the project belongs to the current user
            if ($researchProject && $researchProject->getUser() !== $user) {
                $researchProject = null;
            }

            $project = $this->projectManager->createProject($user, $data['type'], $data['name'], $researchProject);
        } else {
            // Mode Test : Sauvegarde en session
            $session = $request->hasSession() ? $request->getSession() : null;
            $projects = $session ? $session->get('test_projects', []) : [];
            
            $name = $data['name'];
            if (mb_strlen($name) > 50) {
                $name = mb_substr($name, 0, 47) . '...';
            }
            
            $project = [
                'id' => count($projects) + 1,
                'name' => $name,
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
        $isTestMode = $request->hasSession() ? $request->getSession()?->get('is_test_mode') : false;

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
            $session = $request->hasSession() ? $request->getSession() : null;
            $projects = $session ? $session->get('test_projects', []) : [];
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

    #[Route('/{id}/upload-image', name: 'api_projects_upload_image', methods: ['POST'])]
    public function uploadImage(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        $isTestMode = $request->hasSession() ? $request->getSession()?->get('is_test_mode') : false;

        if (!$user && !$isTestMode) {
            return $this->json([
                'success' => false,
                'error' => ['message' => 'Non autorisé']
            ], Response::HTTP_UNAUTHORIZED);
        }

        $projectType = 'writing';
        $projectId = $id;

        if ($user) {
            $project = $this->projectManager->getProject($id);
            if (!$project || $project->getUser() !== $user) {
                return $this->json([
                    'success' => false,
                    'error' => ['message' => 'Projet non trouvé']
                ], Response::HTTP_NOT_FOUND);
            }
            $projectType = $project->getType();
        } else {
            // Mode Test : Session
            $session = $request->hasSession() ? $request->getSession() : null;
            $projects = $session ? $session->get('test_projects', []) : [];
            $foundProject = null;
            foreach ($projects as $p) {
                if ($p['id'] == $id) {
                    $foundProject = $p;
                    break;
                }
            }
            if (!$foundProject) {
                return $this->json([
                    'success' => false,
                    'error' => ['message' => 'Projet non trouvé']
                ], Response::HTTP_NOT_FOUND);
            }
            $projectType = $foundProject['type'] ?? 'writing';
        }

        /** @var \Symfony\Component\HttpFoundation\File\UploadedFile|null $file */
        $file = $request->files->get('image') ?? $request->files->get('file');

        if (!$file) {
            return $this->json([
                'success' => false,
                'error' => ['message' => 'Aucune image fournie']
            ], Response::HTTP_BAD_REQUEST);
        }

        // 1. Validation de la taille (5 Mo max pour les images)
        $maxImageSize = 5 * 1024 * 1024;
        if ($file->getSize() > $maxImageSize) {
            return $this->json([
                'success' => false,
                'error' => ['message' => 'L\'image dépasse la taille maximale autorisée de 5 Mo.']
            ], Response::HTTP_BAD_REQUEST);
        }

        // 2. Validation du type MIME
        $allowedMimeTypes = ['image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/webp', 'image/svg+xml'];
        $mimeType = $file->getMimeType();
        if (!in_array($mimeType, $allowedMimeTypes, true)) {
            return $this->json([
                'success' => false,
                'error' => ['message' => 'Format d\'image non supporté. Seuls PNG, JPG, JPEG, GIF, WebP et SVG sont acceptés.']
            ], Response::HTTP_BAD_REQUEST);
        }

        // 3. Dossier de stockage cohérent : public/uploads/projects/{project_type}/{project_id}/images/
        $projectDir = $this->getParameter('kernel.project_dir');
        $relativeUploadDir = sprintf('uploads/projects/%s/%s/images', $projectType, $projectId);
        $absoluteUploadDir = $projectDir . '/public/' . $relativeUploadDir;

        // Assurer la création récursive du dossier avec les droits appropriés
        if (!is_dir($absoluteUploadDir)) {
            mkdir($absoluteUploadDir, 0755, true);
        }

        // 4. Génération d'un nom de fichier unique et propre
        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = preg_replace('/[^a-zA-Z0-9_-]/', '', $originalFilename);
        if (empty($safeFilename)) {
            $safeFilename = 'image';
        }
        $guessedExtension = $file->guessExtension() ?? 'png';
        $newFilename = sprintf('%s-%s.%s', $safeFilename, uniqid(), $guessedExtension);

        try {
            $file->move($absoluteUploadDir, $newFilename);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => ['message' => 'Erreur lors de la sauvegarde de l\'image : ' . $e->getMessage()]
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $publicUrl = '/' . $relativeUploadDir . '/' . $newFilename;

        return $this->json([
            'success' => true,
            'url' => $publicUrl
        ], Response::HTTP_OK);
    }
}
