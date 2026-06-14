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
        private \Symfony\Contracts\Cache\CacheInterface $cache,
        private \App\Service\ReferenceInterceptor $referenceInterceptor,
        private \App\Service\File\TextExtractorService $textExtractorService,
    ) {
    }

    #[Route('/review', name: 'api_literature_review', methods: ['POST'])]
    public function review(Request $request): Response
    {
        set_time_limit(240);
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

        if ($this->deepSeekService->isApiKeyPlaceholder()) {
            return $this->json([
                'success' => false,
                'error'   => ['code' => 503, 'message' => 'Service IA temporairement indisponible. Veuillez réessayer.']
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // 1. Logique Cache Local (Redis)
        $cacheKey = 'literature_review_v2_' . md5($data['query'] . '_' . ($project ? $project->getId() : '0'));
        $cacheItem = $this->cache->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            $cachedContent = $cacheItem->get();
            return new \Symfony\Component\HttpFoundation\StreamedResponse(function () use ($cachedContent) {
                if (ob_get_level() > 0) ob_end_clean();
                // Diffuser le contenu en cache enrichi directement
                echo "data: " . json_encode(['enriched' => $cachedContent]) . "\n\n";
                echo "data: [DONE]\n\n";
                flush();
            }, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
            ]);
        }

        // 2. Logique Cache Contextuel (DeepSeek Prompt Cache)
        $messages = [];
        $messages[] = [
            'role' => 'system',
            'content' => "Tu es un assistant IA spécialisé dans la recherche académique, scientifique et technique pour la plateforme Djoliba. Réponds toujours de manière structurée, précise et rigoureuse. Utilise un formatage Markdown riche, clair et très lisible. Intègre des équations mathématiques sous forme KaTeX ($$ pour hors-texte, $ pour en-ligne) si nécessaire."
        ];

        // Construction du contexte projet + documents du projet
        $projectContext = "Sujet du projet de synthèse : " . ($project ? $project->getName() : $data['query']);
        if ($project && $project->getMetadata() && isset($project->getMetadata()['description'])) {
            $projectContext .= "\nDescription : " . $project->getMetadata()['description'];
        }

        // Lecture des fichiers sources s'ils existent
        $documentTexts = [];
        if ($project) {
            $documents = $this->entityManager->getRepository(\App\Entity\Document::class)->findBy(['project' => $project]);
            foreach ($documents as $doc) {
                try {
                    $path = $doc->getStoredPath();
                    $mimeType = $doc->getMimeType();
                    $text = $this->textExtractorService->extractText($path, $mimeType, $doc->getFilename());
                    if (!empty($text)) {
                        $documentTexts[] = "--- Document: " . $doc->getFilename() . " ---\n" . mb_substr($text, 0, 10000);
                    }
                } catch (\Exception $e) {
                    // Ignorer les erreurs de fichiers
                }
            }
        }

        if (!empty($documentTexts)) {
            $projectContext .= "\n\nVoici les documents sources associés au projet :\n\n" . implode("\n\n", $documentTexts);
        }

        // Injection du contexte documentaire
        $messages[] = [
            'role' => 'user',
            'content' => $projectContext
        ];

        $messages[] = [
            'role' => 'assistant',
            'content' => "Entendu. J'ai bien pris en compte le sujet du projet de synthèse ainsi que ses documents sources. Je suis prêt à rédiger la revue de littérature scientifique détaillée et structurée."
        ];

        // Historique des interactions précédentes de type literature_review pour ce projet (max 4 tours / 8 messages)
        if ($project) {
            $previousInteractions = $this->entityManager->getRepository(\App\Entity\Interaction::class)->findBy(
                ['project' => $project, 'type' => 'literature_review'],
                ['createdAt' => 'ASC']
            );
            $chatTurns = [];
            foreach ($previousInteractions as $interaction) {
                $chatTurns[] = [
                    'role' => 'user',
                    'content' => "Effectue une revue de littérature sur : " . $interaction->getUserPrompt()
                ];
                if ($interaction->getAiResponse()) {
                    $chatTurns[] = [
                        'role' => 'assistant',
                        'content' => $interaction->getAiResponse()
                    ];
                }
            }

            $maxHistory = 8;
            if (count($chatTurns) > $maxHistory) {
                $chatTurns = array_slice($chatTurns, -$maxHistory);
            }
            if (!empty($chatTurns) && $chatTurns[0]['role'] === 'assistant') {
                array_shift($chatTurns);
            }

            $messages = array_merge($messages, $chatTurns);
        }

        // Requête principale
        $messages[] = [
            'role' => 'user',
            'content' => sprintf(
                "Effectue une revue de littérature scientifique extrêmement détaillée et structurée en Markdown sur le sujet suivant: \"%s\".
                Inclus des fondements théoriques, les tendances récentes de la recherche, les lacunes identifiées dans la littérature actuelle, et des pistes de recherche futures.
                
                CONSIGNES STRICTES SUR LES RÉFÉRENCES :
                - Ne cite AUCUN article ou auteur directement dans le corps du texte (pas de citations entre parenthèses ni de renvois en cours de texte, pour éliminer tout risque d'hallucination).
                - Termine obligatoirement la revue par une section \"### Bibliographie\" listant 3 à 5 articles réels et pertinents (format Auteur, Année, Titre, Journal) servant de références globales pour le sujet.
                
                Sois précis, académique et utilise un ton rigoureux.",
                $data['query']
            )
        ];

        $deepSeekService = $this->deepSeekService;
        $entityManager = $this->entityManager;
        $referenceInterceptor = $this->referenceInterceptor;
        $cache = $this->cache;

        return new \Symfony\Component\HttpFoundation\StreamedResponse(function () use ($data, $project, $deepSeekService, $entityManager, $messages, $referenceInterceptor, $cache, $cacheItem) {
            if (ob_get_level() > 0) ob_end_clean();

            try {
                $fullResponse = '';
                $deepSeekService->streamWithHistory($messages, function ($chunk) use (&$fullResponse) {
                    $fullResponse .= $chunk;
                    echo "data: " . json_encode(['chunk' => $chunk]) . "\n\n";
                    flush();
                });

                // 3. Logique de vérification des références (Enrichissement HTML)
                $enrichedResponse = $referenceInterceptor->formatEnrichedResponse($fullResponse);

                // Envoyer la réponse enrichie au client via SSE
                echo "data: " . json_encode(['enriched' => $enrichedResponse]) . "\n\n";
                flush();

                // Enregistrement dans le cache local (24h)
                $cacheItem->set($enrichedResponse);
                $cacheItem->expiresAfter(86400);
                $cache->save($cacheItem);

                // Persistance de l'interaction en base de données
                if ($project) {
                    $interaction = new \App\Entity\Interaction();
                    $interaction->setProject($project);
                    $interaction->setType('literature_review');
                    $interaction->setUserPrompt($data['query']);
                    $interaction->setAiResponse($enrichedResponse); // On persiste le HTML enrichi
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
