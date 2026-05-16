<?php

namespace App\Controller;

use App\Service\Project\ProjectManager;
use App\Service\WritingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/writing')]
#[IsGranted('ROLE_USER')]
class WritingController extends AbstractController
{
    public function __construct(
        private WritingService $writingService,
        private ProjectManager $projectManager,
    ) {
    }

    /**
     * POST /api/writing/originality
     * Body JSON: { "text": "string", "project_id": int }
     *
     * Analyse l'originalité d'un texte.
     * Réponse: { originality_score, level, similar_passages[], recommendations[] }
     */
    #[Route('/originality', name: 'api_writing_originality', methods: ['POST'])]
    public function checkOriginality(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (empty($data['text']) || empty($data['project_id'])) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 400, 'message' => 'Les champs "text" et "project_id" sont requis.']
            ], Response::HTTP_BAD_REQUEST);
        }

        if (mb_strlen($data['text']) < 100) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 422, 'message' => 'Le texte doit contenir au moins 100 caractères.']
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $project = $this->projectManager->getProject((int) $data['project_id']);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 404, 'message' => 'Projet non trouvé.']
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            $result = $this->writingService->checkOriginality($data['text'], $project);

            return $this->json([
                'success' => true,
                'data'    => [
                    'originality_score' => $result['originality_score'],
                    'level'             => $result['level'],
                    'similar_passages'  => $result['similar_passages'],
                    'recommendations'   => $result['recommendations'],
                    'interaction_id'    => $result['interaction']->getId(),
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
     * POST /api/writing/journals
     * Body JSON: { "text": "string", "project_id": int, "limit": 5 (optional) }
     *
     * Suggère des revues scientifiques adaptées au texte.
     * Réponse: { journals[]: { name, publisher, impact_factor, scope, url, match_reason } }
     */
    #[Route('/journals', name: 'api_writing_journals', methods: ['POST'])]
    public function suggestJournal(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (empty($data['text']) || empty($data['project_id'])) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 400, 'message' => 'Les champs "text" et "project_id" sont requis.']
            ], Response::HTTP_BAD_REQUEST);
        }

        $project = $this->projectManager->getProject((int) $data['project_id']);

        if (!$project || $project->getUser() !== $this->getUser()) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 404, 'message' => 'Projet non trouvé.']
            ], Response::HTTP_NOT_FOUND);
        }

        $limit = isset($data['limit']) ? max(1, min((int) $data['limit'], 10)) : 5;

        try {
            $result = $this->writingService->suggestJournal($data['text'], $project, $limit);

            return $this->json([
                'success' => true,
                'data'    => [
                    'journals'       => $result['journals'],
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
}
