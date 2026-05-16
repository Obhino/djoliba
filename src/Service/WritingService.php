<?php

namespace App\Service;

use App\Entity\Interaction;
use App\Entity\Project;
use App\Service\IA\CacheService;
use App\Service\IA\DeepSeekService;
use Doctrine\ORM\EntityManagerInterface;

class WritingService
{
    private const CACHE_TTL = 3600; // 1 heure

    public function __construct(
        private DeepSeekService        $deepSeekService,
        private CacheService           $cacheService,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Analyse l'originalité d'un texte scientifique.
     * Retourne un score, les passages potentiellement similaires et des recommandations.
     *
     * Prompt inspiré de la section 6 PROJECT_CONTEXT.md : WritingService::checkOriginality
     *
     * @return array{
     *   originality_score: int,
     *   level: string,
     *   similar_passages: array<int, array{passage: string, risk: string, suggestion: string}>,
     *   recommendations: string[],
     *   interaction: Interaction
     * }
     */
    public function checkOriginality(string $text, Project $project): array
    {
        $cacheKey = 'originality_' . hash('sha256', $text);

        $rawResponse = $this->cacheService->remember(
            $cacheKey,
            function () use ($text) {
                return $this->deepSeekService->call(
                    sprintf(
                        "Analyse l'originalité de ce texte scientifique. Identifie les passages qui ressemblent à des écrits existants, les formulations trop génériques ou non-originales.

Texte à analyser:
%s

Réponds UNIQUEMENT avec ce JSON (sans markdown autour):
{
  \"originality_score\": <entier 0-100>,
  \"level\": \"<faible|moyen|élevé>\",
  \"similar_passages\": [
    {\"passage\": \"<extrait du texte>\", \"risk\": \"<description du risque>\", \"suggestion\": \"<reformulation proposée>\"}
  ],
  \"recommendations\": [\"<conseil 1>\", \"<conseil 2>\"]
}",
                        mb_substr($text, 0, 8000)
                    ),
                    ['temperature' => 0.2]
                );
            },
            self::CACHE_TTL
        );

        $parsed = $this->parseJson($rawResponse, [
            'originality_score' => 75,
            'level'             => 'moyen',
            'similar_passages'  => [],
            'recommendations'   => [],
        ]);

        // Traçabilité
        $interaction = $this->persistInteraction($project, 'writing_check', $text, $rawResponse);

        return array_merge($parsed, ['interaction' => $interaction]);
    }

    /**
     * Suggère des revues scientifiques cibles pour soumettre un article.
     * Retourne une liste de revues avec impact factor et justification.
     *
     * Prompt inspiré de la section 6 PROJECT_CONTEXT.md : WritingService::suggestJournal
     *
     * @return array{
     *   journals: array<int, array{name: string, publisher: string, impact_factor: string, scope: string, url: string, match_reason: string}>,
     *   interaction: Interaction
     * }
     */
    public function suggestJournal(string $text, Project $project, int $limit = 5): array
    {
        $cacheKey = 'journal_suggest_' . hash('sha256', $text) . '_' . $limit;

        $rawResponse = $this->cacheService->remember(
            $cacheKey,
            function () use ($text, $limit) {
                return $this->deepSeekService->call(
                    sprintf(
                        "En tant qu'expert en publication scientifique, suggère %d revues scientifiques adaptées à cet article.

Texte ou résumé de l'article:
%s

Réponds UNIQUEMENT avec ce JSON (sans markdown autour):
{
  \"journals\": [
    {
      \"name\": \"<nom de la revue>\",
      \"publisher\": \"<éditeur>\",
      \"impact_factor\": \"<IF ou N/A>\",
      \"scope\": \"<domaine couvert>\",
      \"url\": \"<url officielle ou N/A>\",
      \"match_reason\": \"<pourquoi cette revue est adaptée à cet article>\"
    }
  ]
}",
                        $limit,
                        mb_substr($text, 0, 6000)
                    ),
                    ['temperature' => 0.3]
                );
            },
            self::CACHE_TTL
        );

        $parsed = $this->parseJson($rawResponse, ['journals' => []]);

        // Normalisation des journaux
        $journals = array_map(fn(array $j) => [
            'name'         => (string) ($j['name']         ?? ''),
            'publisher'    => (string) ($j['publisher']    ?? ''),
            'impact_factor'=> (string) ($j['impact_factor']?? 'N/A'),
            'scope'        => (string) ($j['scope']        ?? ''),
            'url'          => (string) ($j['url']          ?? ''),
            'match_reason' => (string) ($j['match_reason'] ?? ''),
        ], $parsed['journals'] ?? []);

        // Traçabilité
        $interaction = $this->persistInteraction($project, 'writing_suggest_journal', $text, $rawResponse);

        return [
            'journals'    => $journals,
            'interaction' => $interaction,
        ];
    }

    /**
     * Parse le JSON retourné par DeepSeek avec nettoyage des balises markdown.
     * Retourne $fallback si le JSON est invalide.
     */
    private function parseJson(string $raw, array $fallback): array
    {
        $cleaned = trim($raw);
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
        $cleaned = preg_replace('/\s*```$/', '', $cleaned);

        $decoded = json_decode(trim($cleaned), true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return $fallback;
        }

        return $decoded;
    }

    /**
     * Crée et persiste une entité Interaction pour la traçabilité.
     */
    private function persistInteraction(
        Project $project,
        string  $type,
        string  $userPrompt,
        string  $aiResponse
    ): Interaction {
        $interaction = new Interaction();
        $interaction->setProject($project);
        $interaction->setType($type);
        $interaction->setUserPrompt(mb_substr($userPrompt, 0, 500) . (mb_strlen($userPrompt) > 500 ? '…' : ''));
        $interaction->setAiResponse($aiResponse);

        $this->entityManager->persist($interaction);
        $this->entityManager->flush();

        return $interaction;
    }
}
