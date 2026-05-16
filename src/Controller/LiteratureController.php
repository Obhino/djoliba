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
class LiteratureController extends AbstractController
{
    public function __construct(
        private LiteratureService $literatureService,
        private SuggestionService $suggestionService,
        private ProjectManager $projectManager,
        private \App\Service\IA\DeepSeekService $deepSeekService,
        private \Doctrine\ORM\EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/review', name: 'api_literature_review', methods: ['POST'])]
    public function review(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);

        if (empty($data['query']) || empty($data['project_id'])) {
            return $this->json(['error' => 'Champs requis manquants'], 400);
        }

        $user = $this->getUser();
        $isTestMode = $request->getSession()->get('is_test_mode');

        if (!$user && !$isTestMode) {
            return $this->json(['error' => 'Non autorisé'], 401);
        }

        // Récupération du projet
        $project = null;
        if ($user) {
            $project = $this->projectManager->getProject((int) $data['project_id']);
        }

        $deepSeekService = $this->deepSeekService;
        $entityManager = $this->entityManager;

        return new \Symfony\Component\HttpFoundation\StreamedResponse(function () use ($data, $project, $deepSeekService, $entityManager) {
            if (ob_get_level() > 0) ob_end_clean();

            try {
                $prompt = sprintf(
                    "Effectue une revue de littérature scientifique extrêmement détaillée et structurée en Markdown sur le sujet suivant: \"%s\".
                    Inclus des fondements théoriques, les tendances récentes de la recherche, les lacunes identifiées dans la littérature actuelle, et des pistes de recherche futures.
                    Sois précis, académique et utilise un ton rigoureux.",
                    $data['query']
                );

                $fullResponse = '';
                $deepSeekService->stream($prompt, function ($chunk) use (&$fullResponse) {
                    $fullResponse .= $chunk;
                    echo "data: " . json_encode(['chunk' => $chunk]) . "\n\n";
                    flush();
                });

                // Persistance de l'interaction de revue de littérature en base de données
                if ($project) {
                    $interaction = new \App\Entity\Interaction();
                    $interaction->setProject($project);
                    $interaction->setType('literature_review');
                    $interaction->setUserPrompt($data['query']);
                    $interaction->setAiResponse($fullResponse);
                    $interaction->setResponseTimeMs(0);
                    
                    $entityManager->persist($interaction);
                    $entityManager->flush();
                }

            } catch (\Exception $e) {
                echo "data: " . json_encode(['error' => $e->getMessage()]) . "\n\n";
            }

            echo "data: [DONE]\n\n";
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
        ]);
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

        if (!$this->getUser() && !$request->getSession()->get('is_test_mode')) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 401, 'message' => 'Authentification requise.']
            ], Response::HTTP_UNAUTHORIZED);
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
