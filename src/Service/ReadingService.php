<?php

namespace App\Service;

use App\Entity\Document;
use App\Entity\Interaction;
use App\Entity\Project;
use App\Repository\DocumentRepository;
use App\Service\IA\CacheService;
use App\Service\IA\DeepSeekService;
use Doctrine\ORM\EntityManagerInterface;

class ReadingService
{
    // TTL selon PROJECT_CONTEXT.md section 6 CacheService
    private const CACHE_TTL_SYNTHESIS = 3600;   // 1 heure pour les synthèses
    private const SYNTHESIZE_POINTS   = 5;       // Nombre de points clés par défaut

    public function __construct(
        private DeepSeekService    $deepSeekService,
        private CacheService       $cacheService,
        private DocumentRepository $documentRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Génère une synthèse structurée en points clés d'un document.
     * Résultat mis en cache 1h par document (identique pour tous les projets).
     *
     * @return array{points: array<int, array{point: string, explication: string}>, interaction: Interaction}
     */
    public function synthesize(Document $document, Project $project): array
    {
        $documentId = $document->getId();
        $cacheKey = 'synthesis_doc_' . $documentId;

        // On utilise le cache 1h pour éviter de refaire la synthèse du même document
        $points = $this->cacheService->remember(
            $cacheKey,
            function () use ($document) {
                // 1. Extraire le contenu texte
                $textContext = $this->extractTextContent($document);

                // 2. Préparer le prompt pour DeepSeek
                // Limiter la taille du texte à ~8000 caractères pour ne pas exploser le token limit
                $textContext = mb_substr($textContext, 0, 8000);

                $prompt = sprintf(
                    "Tu es un assistant de recherche académique. Synthétise ce document en 5 points clés majeurs. " .
                    "Retourne UNIQUEMENT un tableau JSON valide (pas de markdown, pas de texte autour) " .
                    "avec exactement ce format : [{\"point\": \"Titre du point\", \"explication\": \"Détail...\"}].\n\nTexte du document :\n%s",
                    $textContext
                );

                // 3. Appel à l'API via DeepSeekService
                $response = $this->deepSeekService->call($prompt, [
                    'temperature' => 0.3, // Température basse pour garantir le JSON
                ]);

                // 4. Parser la réponse
                return $this->parsePoints($response);
            },
            self::CACHE_TTL_SYNTHESIS
        );

        // Traçabilité
        $interaction = new Interaction();
        $interaction->setProject($project);
        $interaction->setType('reading_synthesis');
        $interaction->setUserPrompt('[synthesize] ' . $document->getFilename());
        $interaction->setAiResponse(json_encode($points, JSON_UNESCAPED_UNICODE));
        $this->entityManager->persist($interaction);
        $this->entityManager->flush();

        return [
            'points'      => $points,
            'interaction' => $interaction,
        ];
    }

    /**
     * Répond à une question en utilisant les documents du projet comme contexte.
     * Agrège le contenu des documents du projet (jusqu'à 12000 caractères par document).
     *
     * @return array{response: string, interaction: Interaction}
     */
    public function chat(Project $project, string $question, ?Document $singleDocument = null): array
    {
        $startTime = microtime(true);

        // Contexte documentaire
        $documents = $singleDocument ? [$singleDocument] : $this->documentRepository->findBy(['project' => $project]);
        $contextTexts = [];

        foreach ($documents as $doc) {
            try {
                $text = $this->extractTextContent($doc);
                $contextTexts[] = "--- Document: " . $doc->getFilename() . " ---\n" . mb_substr($text, 0, 12000);
            } catch (\RuntimeException $e) {
                // Ignorer les fichiers illisibles
                continue;
            }
        }

        $contextString = implode("\n\n", $contextTexts);

        // Récupérer l'historique des interactions précédentes de type reading_chat pour ce projet
        $previousInteractions = $this->entityManager->getRepository(Interaction::class)->findBy(
            ['project' => $project, 'type' => 'reading_chat'],
            ['createdAt' => 'ASC']
        );

        $messages = [];

        // 1. Définir le prompt système de base
        $messages[] = [
            'role' => 'system',
            'content' => "Tu es un assistant IA spécialisé dans l'analyse de documents. Réponds de manière structurée et précise en te basant sur le contexte fourni. Si la réponse ne s'y trouve pas, signale-le clairement."
        ];

        // 2. Injecter le document au tout début de la conversation (pour optimiser le cache contextuel de l'API DeepSeek)
        $documentContext = sprintf(
            "Voici le contexte documentaire pour notre discussion :\n\n%s",
            $contextString ?: "Aucun document lisible fourni."
        );
        $messages[] = [
            'role' => 'user',
            'content' => $documentContext
        ];
        $messages[] = [
            'role' => 'assistant',
            'content' => "Entendu. J'ai bien pris connaissance du document. Je suis prêt à répondre à vos questions en me basant exclusivement sur son contenu."
        ];

        // 3. Reconstituer l'historique de la conversation
        $chatTurns = [];
        foreach ($previousInteractions as $interaction) {
            $promptText = $interaction->getUserPrompt();
            
            // On ignore la synthèse automatique initiale
            if (str_starts_with($promptText, '[synthesize]')) {
                continue;
            }

            $chatTurns[] = [
                'role' => 'user',
                'content' => $promptText
            ];

            if ($interaction->getAiResponse()) {
                $chatTurns[] = [
                    'role' => 'assistant',
                    'content' => $interaction->getAiResponse()
                ];
            }
        }

        // Limiter l'historique aux 10 derniers messages (environ 5 échanges complets)
        // pour optimiser la taille du contexte et le coût de l'API
        $maxHistoryMessages = 10;
        if (count($chatTurns) > $maxHistoryMessages) {
            $chatTurns = array_slice($chatTurns, -$maxHistoryMessages);
        }

        // Assurer l'alternance stricte user/assistant : le premier message inséré
        // après la validation de l'assistant d'accueil doit obligatoirement être de rôle 'user'
        if (!empty($chatTurns) && $chatTurns[0]['role'] === 'assistant') {
            array_shift($chatTurns);
        }

        // Fusionner l'en-tête (System + PDF Context) avec l'historique glissant
        $messages = array_merge($messages, $chatTurns);

        // 4. Ajouter la question actuelle de l'utilisateur
        $messages[] = [
            'role' => 'user',
            'content' => $question
        ];

        // 5. Appel de l'API DeepSeek avec historique (bénéficie de cache contextuel API + cache local)
        $aiResponse = $this->deepSeekService->chatWithHistory($messages, [
            'temperature' => 0.3,
        ]);

        $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

        // Traçabilité
        $interaction = new Interaction();
        $interaction->setProject($project);
        $interaction->setType('reading_chat');
        $interaction->setUserPrompt($question);
        $interaction->setAiResponse($aiResponse);
        $interaction->setResponseTimeMs($responseTimeMs);
        $this->entityManager->persist($interaction);
        $this->entityManager->flush();

        return [
            'response'    => $aiResponse,
            'interaction' => $interaction,
        ];
    }

    /**
     * Extrait le contenu textuel brut d'un document PDF/DOCX/LaTeX.
     * Pour l'instant : lecture directe du fichier texte (.tex) ou retourne le chemin pour les binaires.
     *
     * @throws \RuntimeException Si le fichier physique est introuvable.
     * @todo Intégrer pdftotext (poppler) ou phpoffice/phpword pour PDF/DOCX
     */
    private function extractTextContent(Document $document): string
    {
        $path = $document->getStoredPath();

        if (!file_exists($path)) {
            throw new \RuntimeException(sprintf(
                'Fichier physique introuvable pour le document #%d : %s',
                $document->getId(),
                $path
            ));
        }

        $mimeType = $document->getMimeType();

        // Fichiers texte (LaTeX, .tex) : lecture directe
        if (in_array($mimeType, ['application/x-tex', 'text/x-tex'], true)) {
            return file_get_contents($path);
        }

        // PDF : extraction via smalot/pdfparser
        if ($mimeType === 'application/pdf') {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($path);
                return $pdf->getText();
            } catch (\Exception $e) {
                throw new \RuntimeException(sprintf('Erreur lors de la lecture du PDF : %s', $e->getMessage()), 0, $e);
            }
        }

        // DOCX : retourner une instruction pour DeepSeek
        // TODO: Implémenter une extraction réelle via phpoffice/phpword
        // Pour l'instant, on indique le nom du fichier pour que l'IA sache de quoi il s'agit
        return sprintf(
            "[Contenu du fichier %s — extraction binaire non encore implémentée. Fichier de type : %s]",
            $document->getFilename(),
            $mimeType
        );
    }

    /**
     * Parse le JSON [{point, explication}] retourné par DeepSeek.
     * Nettoie les balises markdown éventuelles.
     */
    private function parsePoints(string $raw): array
    {
        $cleaned = trim($raw);
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
        $cleaned = preg_replace('/\s*```$/', '', $cleaned);

        $data = json_decode(trim($cleaned), true);

        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            // Fallback : retourner la réponse brute dans un point unique
            return [['point' => 'Synthèse', 'explication' => $raw]];
        }

        return array_map(fn($item) => [
            'point'       => (string) ($item['point']       ?? ''),
            'explication' => (string) ($item['explication'] ?? ''),
        ], $data);
    }
}
