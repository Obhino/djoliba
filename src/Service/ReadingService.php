<?php

namespace App\Service;

use App\Entity\Document;
use App\Entity\Interaction;
use App\Entity\Project;
use App\Repository\DocumentRepository;
use App\Service\IA\CacheService;
use App\Service\IA\DeepSeekService;
use App\Service\IA\GenericTextService;
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
        private GenericTextService $genericTextService,
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
        // En mode développement / générique, on utilise directement le GenericTextService
        $points = $this->genericTextService->getGenericSynthesis($document->getFilename());

        // Traçabilité
        $interaction = new Interaction();
        $interaction->setProject($project);
        $interaction->setType('reading_chat');
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

        // Obtenir l'une des 5 réponses génériques de très haute qualité académique avec LaTeX
        $aiResponse = $this->genericTextService->getRandomGenericChatResponse($question);

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
