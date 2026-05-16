<?php

namespace App\Controller;

use App\Service\LiteratureService;
use App\Service\SuggestionService;
use App\Service\Project\ProjectManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/literature')]
#[IsGranted('ROLE_USER')]
class LiteratureController extends AbstractController
{
    public function __construct(
        private LiteratureService $literatureService,
        private SuggestionService $suggestionService,
        private ProjectManager $projectManager,
    ) {
    }


    /**
     * POST /api/literature/review
     * Body JSON: { "query": "string", "project_id": int }
     */
    #[Route('/review', name: 'api_literature_review', methods: ['POST'])]
    public function review(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (empty($data['query']) || empty($data['project_id'])) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 400, 'message' => 'Les champs "query" et "project_id" sont requis.']
            ], Response::HTTP_BAD_REQUEST);
        }

        $project = $this->projectManager->getProject((int) $data['project_id']);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 404, 'message' => 'Projet non trouvé.']
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            $result = $this->literatureService->review($data['query'], $project);

            return $this->json([
                'success' => true,
                'data'    => [
                    'response'       => $result['response'],
                    'interaction_id' => $result['interaction']->getId(),
                    'response_time_ms' => $result['interaction']->getResponseTimeMs(),
                ],
            ]);
        } catch (\RuntimeException $e) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 503, 'message' => 'Service IA temporairement indisponible. Veuillez réessayer.']
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    /**
     * POST /api/literature/suggestions
     * Body JSON: { "query": "string", "limit": 5 (optional) }
     */
    #[Route('/suggestions', name: 'api_literature_suggestions', methods: ['POST'])]
    public function suggestions(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (empty($data['query'])) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 400, 'message' => 'Le champ "query" est requis.']
            ], Response::HTTP_BAD_REQUEST);
        }

        $limit = isset($data['limit']) ? (int) $data['limit'] : 5;
        $limit = max(1, min($limit, 10)); // Borne entre 1 et 10

        try {
            $articles = $this->suggestionService->suggest($data['query'], $limit);

            return $this->json([
                'success' => true,
                'data'    => $articles,
            ]);
        } catch (\RuntimeException $e) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 503, 'message' => 'Service IA temporairement indisponible. Veuillez réessayer.']
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (\UnexpectedValueException $e) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 502, 'message' => 'La réponse de l\'IA est invalide. Veuillez réessayer.']
            ], Response::HTTP_BAD_GATEWAY);
        }
    }
}
