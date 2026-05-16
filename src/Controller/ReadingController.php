<?php

namespace App\Controller;

use App\Repository\DocumentRepository;
use App\Service\Project\ProjectManager;
use App\Service\ReadingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/reading')]
#[IsGranted('ROLE_USER')]
class ReadingController extends AbstractController
{
    public function __construct(
        private ReadingService     $readingService,
        private ProjectManager     $projectManager,
        private DocumentRepository $documentRepository,
    ) {
    }

    /**
     * POST /api/reading/synthesize
     * Body JSON: { "document_id": int, "project_id": int }
     */
    #[Route('/synthesize', name: 'api_reading_synthesize', methods: ['POST'])]
    public function synthesize(Request $request): JsonResponse
    {
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
                'data'    => [
                    'points'         => $result['points'],
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
     * POST /api/reading/chat
     * Body JSON: { "project_id": int, "question": "string" }
     */
    #[Route('/chat', name: 'api_reading_chat', methods: ['POST'])]
    public function chat(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (empty($data['project_id']) || empty($data['question'])) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 400, 'message' => 'Les champs "project_id" et "question" sont requis.']
            ], Response::HTTP_BAD_REQUEST);
        }

        $project = $this->projectManager->getProject((int) $data['project_id']);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return $this->json(['success' => false, 'error' => ['code' => 404, 'message' => 'Projet non trouvé.']], Response::HTTP_NOT_FOUND);
        }

        try {
            $result = $this->readingService->chat($project, $data['question']);

            return $this->json([
                'success' => true,
                'data'    => [
                    'response'         => $result['response'],
                    'interaction_id'   => $result['interaction']->getId(),
                    'response_time_ms' => $result['interaction']->getResponseTimeMs(),
                ],
            ]);
        } catch (\RuntimeException $e) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 503, 'message' => 'Service IA temporairement indisponible.']
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }
}
