<?php

namespace App\Controller;

use App\Repository\DocumentRepository;
use App\Repository\SubProjectRepository;
use App\Service\File\FileStorageService;
use App\Service\Project\ProjectManager;
use App\Service\Project\SubProjectManager;
use App\Service\ReadingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Attribute\RateLimiter;

#[Route('/api/reading')]
#[IsGranted('ROLE_USER')]
class ReadingController extends AbstractController
{
    public function __construct(
        private ReadingService     $readingService,
        private FileStorageService $fileStorageService,
        private ProjectManager     $projectManager,
        private DocumentRepository $documentRepository,
        private SubProjectManager  $subProjectManager,
        private SubProjectRepository $subProjectRepository,
    ) {
    }

    /**
     * POST /api/reading/upload
     * multipart/form-data: { file: UploadedFile, project_id: int }
     *
     * Upload, valide, scan et déclenche le traitement asynchrone (ProcessDocumentMessage).
     */
    #[Route('/upload', name: 'api_reading_upload', methods: ['POST'])]
    #[RateLimiter('api_default')]
    public function upload(Request $request): JsonResponse
    {
        $file      = $request->files->get('file');
        $projectId = $request->request->get('project_id');

        if (!$file || !$projectId) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 400, 'message' => 'Les champs "file" et "project_id" sont requis.']
            ], Response::HTTP_BAD_REQUEST);
        }

        $project = $this->projectManager->getProject((int) $projectId);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 404, 'message' => 'Projet non trouvé.']
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $researchProject = $project->getResearchProject();

            // Vérifier s'il existe déjà un sous-projet du même nom, de type "reading" et actif
            $existingSubProject = $this->subProjectRepository->findOneBy([
                'user'            => $this->getUser(),
                'researchProject' => $researchProject,
                'type'            => 'reading',
                'name'            => $originalFilename,
                'status'          => 'active',
            ]);

            if ($existingSubProject) {
                $existingProject = $existingSubProject->getProjects()->first();
                $redirectUrl = $existingProject
                    ? $this->generateUrl('app_project_reading', ['id' => $existingProject->getId()])
                    : null;

                return $this->json([
                    'success' => false,
                    'error'   => [
                        'code'         => 409,
                        'message'      => 'Un document du même nom existe déjà.',
                        'redirect_url' => $redirectUrl,
                    ],
                ], Response::HTTP_CONFLICT);
            }

            $subProject = $this->subProjectManager->createForUser(
                $this->getUser(),
                'reading',
                $originalFilename,
                $researchProject
            );

            // Récupérer le projet compagnon pour y lier le document
            $newProject = $subProject->getProjects()->first();
            if (!$newProject) {
                throw new \RuntimeException("Le projet compagnon n'a pas pu être créé.");
            }

            $document = $this->fileStorageService->upload($file, $newProject, $this->getUser());

            $redirectUrl = $this->generateUrl('app_project_reading', ['id' => $newProject->getId()]);

            return $this->json([
                'success' => true,
                'data'    => [
                    'document_id'  => $document->getId(),
                    'filename'     => $document->getFilename(),
                    'mime_type'    => $document->getMimeType(),
                    'size_bytes'   => $document->getSizeBytes(),
                    'redirect_url' => $redirectUrl,
                    'message'      => 'Nouveau sous-projet créé avec succès. Redirection...',
                ],
            ], Response::HTTP_CREATED);

        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 422, 'message' => $e->getMessage()]
            ], Response::HTTP_UNPROCESSABLE_ENTITY);

        } catch (\RuntimeException $e) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 500, 'message' => 'Erreur lors de l\'enregistrement du fichier.']
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * POST /api/reading/{id}/chat
     * Body JSON: { "question": "string" }
     *
     * Chat avec un document spécifique par son ID.
     * Contexte limité à CE document (vs /chat qui agrège tout le projet).
     */
    #[Route('/{id}/chat', name: 'api_reading_document_chat', methods: ['POST'])]
    #[RateLimiter('api_ia')]
    public function documentChat(int $id, Request $request): JsonResponse
    {
        set_time_limit(240);
        $data = json_decode($request->getContent(), true);

        if (empty($data['question'])) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 400, 'message' => 'Le champ "question" est requis.']
            ], Response::HTTP_BAD_REQUEST);
        }

        // IDOR protection : le document appartient bien à l'utilisateur connecté
        $document = $this->documentRepository->findOneBy([
            'id'   => $id,
            'user' => $this->getUser(),
        ]);

        if (!$document) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 404, 'message' => 'Document non trouvé.']
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            $result = $this->readingService->chat($document->getProject(), $data['question'], $document);

            return $this->json([
                'success' => true,
                'data'    => [
                    'response'         => $result['response'],
                    'interaction_id'   => $result['interaction']->getId(),
                    'response_time_ms' => $result['interaction']->getResponseTimeMs(),
                    'document_id'      => $document->getId(),
                ],
            ]);
        } catch (\RuntimeException $e) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 503, 'message' => 'Service IA temporairement indisponible.']
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    /**
     * GET /api/reading/{id}/synthesize
     * Génère et retourne la synthèse en points clés du document.
     */
    #[Route('/{id}/synthesize', name: 'api_reading_document_synthesize', methods: ['GET'])]
    public function documentSynthesize(int $id): JsonResponse
    {
        set_time_limit(240);
        $document = $this->documentRepository->findOneBy([
            'id'   => $id,
            'user' => $this->getUser(),
        ]);

        if (!$document) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 404, 'message' => 'Document non trouvé.']
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            $result = $this->readingService->synthesize($document, $document->getProject());

            return $this->json([
                'success' => true,
                'data'    => [
                    'points' => $result['points'],
                    'interaction_id' => $result['interaction']->getId(),
                ],
            ]);
        } catch (\RuntimeException $e) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 503, 'message' => 'Service IA temporairement indisponible.']
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    /**
     * POST /api/reading/synthesize
     * Body JSON: { "document_id": int, "project_id": int }
     */
    #[Route('/synthesize', name: 'api_reading_synthesize', methods: ['POST'])]
    public function synthesize(Request $request): JsonResponse
    {
        set_time_limit(240);
        $data = json_decode($request->getContent(), true);

        if (empty($data['document_id']) || empty($data['project_id'])) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 400, 'message' => 'Les champs "document_id" et "project_id" sont requis.']
            ], Response::HTTP_BAD_REQUEST);
        }

        $project  = $this->projectManager->getProject((int) $data['project_id']);
        $document = $this->documentRepository->findOneBy([
            'id'   => (int) $data['document_id'],
            'user' => $this->getUser(),
        ]);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return $this->json(['success' => false, 'error' => ['code' => 404, 'message' => 'Projet non trouvé.']], Response::HTTP_NOT_FOUND);
        }

        if (!$document) {
            return $this->json(['success' => false, 'error' => ['code' => 404, 'message' => 'Document non trouvé.']], Response::HTTP_NOT_FOUND);
        }

        try {
            $result = $this->readingService->synthesize($document, $project);
            return $this->json([
                'success' => true,
                'data'    => ['points' => $result['points'], 'interaction_id' => $result['interaction']->getId()],
            ]);
        } catch (\RuntimeException $e) {
            return $this->json(['success' => false, 'error' => ['code' => 503, 'message' => 'Service IA temporairement indisponible.']], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    /**
     * POST /api/reading/chat
     * Body JSON: { "project_id": int, "question": "string" }
     * Chat sur tous les documents du projet (contexte agrégé).
     */
    #[Route('/chat', name: 'api_reading_chat', methods: ['POST'])]
    public function chat(Request $request): JsonResponse
    {
        set_time_limit(240);
        $data = json_decode($request->getContent(), true);

        if (empty($data['project_id']) || empty($data['question'])) {
            return $this->json(['success' => false, 'error' => ['code' => 400, 'message' => 'Les champs "project_id" et "question" sont requis.']], Response::HTTP_BAD_REQUEST);
        }

        $project = $this->projectManager->getProject((int) $data['project_id']);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return $this->json(['success' => false, 'error' => ['code' => 404, 'message' => 'Projet non trouvé.']], Response::HTTP_NOT_FOUND);
        }

        try {
            $result = $this->readingService->chat($project, $data['question']);
            return $this->json([
                'success' => true,
                'data'    => ['response' => $result['response'], 'interaction_id' => $result['interaction']->getId(), 'response_time_ms' => $result['interaction']->getResponseTimeMs()],
            ]);
        } catch (\RuntimeException $e) {
            return $this->json(['success' => false, 'error' => ['code' => 503, 'message' => 'Service IA temporairement indisponible.']], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    /**
     * GET /api/reading/project/{id}/history
     * Récupère l'historique des interactions de chat (reading_chat) pour un projet.
     */
    #[Route('/project/{id}/history', name: 'api_reading_project_history', methods: ['GET'])]
    public function projectHistory(int $id, \App\Repository\InteractionRepository $interactionRepo): JsonResponse
    {
        $project = $this->projectManager->getProject($id);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return $this->json(['success' => false, 'error' => ['code' => 404, 'message' => 'Projet non trouvé.']], Response::HTTP_NOT_FOUND);
        }

        $interactions = $interactionRepo->findBy(
            ['project' => $project, 'type' => 'reading_chat'],
            ['createdAt' => 'ASC']
        );

        $history = [];
        foreach ($interactions as $interaction) {
            $history[] = [
                'role' => 'user',
                'content' => $interaction->getUserPrompt(),
                'time' => $interaction->getCreatedAt()->format('H:i')
            ];
            if ($interaction->getAiResponse()) {
                $history[] = [
                    'role' => 'ai',
                    'content' => $interaction->getAiResponse(),
                    'time' => $interaction->getCreatedAt()->format('H:i')
                ];
            }
        }

        return $this->json([
            'success' => true,
            'data' => ['history' => $history]
        ]);
    }
}
