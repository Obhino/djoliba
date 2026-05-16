<?php

namespace App\Controller;

use App\Service\LiteratureService;
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
}
