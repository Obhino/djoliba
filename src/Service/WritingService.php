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
     * @param string        $text
     * @param Project|null  $project
     * @return array
     */
    public function checkOriginality(string $text, ?Project $project = null): array
    {
        $cacheKey = 'originality_' . hash('sha256', $text);

        $rawResponse = $this->cacheService->remember(
            $cacheKey,
            function () use ($text) {
                return $this->deepSeekService->call(
                    sprintf(
                        "Analyse l'originalité du texte suivant. Retourne un score 0-100, les passages potentiellement non originaux, des suggestions de reformulation. Réponse JSON.\n\nTexte :\n%s",
                        mb_substr($text, 0, 8000)
                    ),
                    ['temperature' => 0.2]
                );
            },
            self::CACHE_TTL
        );

        $parsed = $this->parseJson($rawResponse, []);

        // Normalisation et fallback pour supporter tout format JSON retourné
        $normalized = [
            'originality_score' => (int) ($parsed['originality_score'] ?? $parsed['score'] ?? 75),
            'level'             => (string) ($parsed['level'] ?? $parsed['niveau'] ?? 'moyen'),
            'similar_passages'  => [],
            'recommendations'   => [],
        ];

        $passages = $parsed['similar_passages'] ?? $parsed['passages'] ?? $parsed['passages_non_originaux'] ?? [];
        if (is_array($passages)) {
            foreach ($passages as $p) {
                if (!is_array($p)) continue;
                $normalized['similar_passages'][] = [
                    'passage'    => (string) ($p['passage'] ?? $p['extrait'] ?? ''),
                    'risk'       => (string) ($p['risk'] ?? $p['risque'] ?? 'N/A'),
                    'suggestion' => (string) ($p['suggestion'] ?? $p['reformulation'] ?? ''),
                ];
            }
        }

        $recommendations = $parsed['recommendations'] ?? $parsed['conseils'] ?? [];
        if (is_array($recommendations)) {
            foreach ($recommendations as $r) {
                $normalized['recommendations'][] = (string) $r;
            }
        }

        // Traçabilité si le projet est fourni
        $interaction = null;
        if ($project !== null) {
            $interaction = $this->persistInteraction($project, 'writing_check', $text, $rawResponse);
        }

        return array_merge($normalized, ['interaction' => $interaction]);
    }

    /**
     * Suggère des revues scientifiques cibles pour soumettre un article.
     * Retourne une liste de revues avec impact factor et justification.
     *
     * @param string        $text
     * @param Project|null  $project
     * @param int           $limit
     * @return array
     */
    public function suggestJournal(string $text, ?Project $project = null, int $limit = 3): array
    {
        $cacheKey = 'journal_suggest_' . hash('sha256', $text) . '_' . $limit;

        $rawResponse = $this->cacheService->remember(
            $cacheKey,
            function () use ($text, $limit) {
                return $this->deepSeekService->call(
                    sprintf(
                        "Suggère %d revues scientifiques pour cet article avec justification. Réponse JSON.\n\nTexte :\n%s",
                        $limit,
                        mb_substr($text, 0, 6000)
                    ),
                    ['temperature' => 0.3]
                );
            },
            self::CACHE_TTL
        );

        $parsed = $this->parseJson($rawResponse, []);

        // Gérer si le JSON est directement un tableau ou enveloppé sous une clé
        $journalsData = $parsed['journals'] ?? $parsed['revues'] ?? $parsed;
        if (!is_array($journalsData)) {
            $journalsData = [];
        }

        $journals = [];
        foreach ($journalsData as $j) {
            if (!is_array($j)) continue;
            $journals[] = [
                'name'         => (string) ($j['name']         ?? $j['title'] ?? $j['nom'] ?? 'Nom de la revue inconnu'),
                'publisher'    => (string) ($j['publisher']    ?? $j['editeur'] ?? 'N/A'),
                'impact_factor'=> (string) ($j['impact_factor']?? $j['facteur_impact'] ?? 'N/A'),
                'scope'        => (string) ($j['scope']        ?? $j['domaine'] ?? 'N/A'),
                'url'          => (string) ($j['url']          ?? 'N/A'),
                'match_reason' => (string) ($j['match_reason'] ?? $j['justification'] ?? $j['raison'] ?? ''),
            ];
        }

        // Traçabilité si le projet est fourni
        $interaction = null;
        if ($project !== null) {
            $interaction = $this->persistInteraction($project, 'writing_suggest_journal', $text, $rawResponse);
        }

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
