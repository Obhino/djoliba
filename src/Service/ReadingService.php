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
     * @throws \InvalidArgumentException Si le fichier physique est introuvable.
     * @throws \RuntimeException         Si l'API DeepSeek est indisponible.
     */
    public function synthesize(Document $document, Project $project): array
    {
        // Lecture du contenu texte du document
        $content = $this->extractTextContent($document);

        // Cache par document ID : la synthèse ne change pas entre les projets
        $cacheKey  = 'synthesis_doc_' . $document->getId();

        $rawResponse = $this->cacheService->remember(
            $cacheKey,
            function () use ($content) {
                return $this->deepSeekService->call(
                    sprintf(
                        "Synthétise ce document en %d points clés. Format de réponse UNIQUEMENT en JSON: [{\"point\": \"titre court\", \"explication\": \"détail en 2-3 phrases\"}].\n\nDocument:\n%s",
                        self::SYNTHESIZE_POINTS,
                        mb_substr($content, 0, 12000) // Limite de tokens (évite le dépassement)
                    ),
                    ['temperature' => 0.3] // Température basse pour une réponse structurée
                );
            },
            self::CACHE_TTL_SYNTHESIS
        );

        // Parse le JSON retourné par l'IA
        $points = $this->parsePoints($rawResponse);

        // Traçabilité
        $interaction = new Interaction();
        $interaction->setProject($project);
        $interaction->setType('reading_chat');
        $interaction->setUserPrompt('[synthesize] ' . $document->getFilename());
        $interaction->setAiResponse($rawResponse);
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
     * @throws \RuntimeException Si l'API DeepSeek est indisponible.
     */
    public function chat(Project $project, string $question, ?Document $singleDocument = null): array
    {
        // Si un document spécifique est passé, on limite le contexte à celui-ci
        $documents = $singleDocument !== null
            ? [$singleDocument]
            : $this->documentRepository->findBy(['project' => $project]);

        // Construire le contexte depuis les documents disponibles
        $contextParts = [];
        foreach ($documents as $doc) {
            try {
                $content = $this->extractTextContent($doc);
                $contextParts[] = sprintf(
                    "--- Document: %s ---\n%s",
                    $doc->getFilename(),
                    mb_substr($content, 0, 6000)
                );
            } catch (\RuntimeException) {
                // Ignorer les documents dont le fichier physique est absent
                continue;
            }
        }

        $context = empty($contextParts)
            ? "Aucun document disponible pour ce projet."
            : implode("\n\n", $contextParts);

        $prompt = sprintf(
            "Contexte documentaire du projet \"%s\":\n%s\n\n---\nQuestion: %s\n\nRéponds de façon précise et cite les passages pertinents des documents. Si la réponse n'est pas dans les documents, indique-le clairement.",
            $project->getName(),
            $context,
            $question
        );

        $startTime = microtime(true);
        $aiResponse = $this->deepSeekService->call($prompt, ['temperature' => 0.5]);
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
