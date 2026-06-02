<?php

namespace App\Service;

use App\Entity\Interaction;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\IA\CacheService;
use App\Service\IA\DeepSeekService;
use App\Service\Search\OpenSerpSearchService;
use App\Service\ReferenceInterceptor;

class LiteratureService
{
    private const CACHE_TTL = 3600; // 1 heure : une review ne change pas en quelques minutes

    public function __construct(
        private DeepSeekService $deepSeekService,
        private CacheService $cacheService,
        private EntityManagerInterface $entityManager,
        private OpenSerpSearchService $openSerpSearchService,
        private ReferenceInterceptor $referenceInterceptor,
    ) {
    }

    /**
     * Effectue une revue de littérature scientifique sur une requête donnée.
     * Utilise le prompt défini dans PROJECT_CONTEXT.md section 6.
     * Le résultat est mis en cache Redis pendant 1 heure pour éviter les appels API redondants.
     *
     * @param string  $query   Le sujet ou la question de recherche.
     * @param Project $project Le projet auquel rattacher cette interaction.
     * @return array{response: string, literature_review: string, web_sources: array, interaction: Interaction, from_cache: bool}
     */
    public function review(string $query, Project $project): array
    {
        // Clé de cache unique par requête (le projet n'intervient pas : même query = même réponse IA)
        $cacheKey = 'literature_review_' . $query;
        $fromCache = false;

        $aiResponse = $this->cacheService->remember(
            $cacheKey,
            function () use ($query) {
                // Prompt défini en section 6 du PROJECT_CONTEXT.md
                return $this->deepSeekService->call(
                    sprintf(
                        "Effectue une revue de littérature surcé sur: %s. Inclus: fondement théorique, tendances récentes, lacunes, articles incontournables et bibliographie.",
                        $query
                    )
                );
            },
            self::CACHE_TTL
        );

        // Récupérer les 5 premières sources scientifiques via OpenSERP
        $webSources = [];
        try {
            $webSources = $this->openSerpSearchService->search($query, null, 5, 'google', true);
        } catch (\Exception $e) {
            // Ignorer l'erreur pour ne pas faire planter la revue de littérature en cas de souci avec OpenSERP
        }

        $fromCache = false; // Simplifié ici — à affiner si besoin de métriques précises

        // Intercepter et enrichir les références bibliographiques (badges et liens réels)
        $enrichedReview = $this->referenceInterceptor->formatEnrichedResponse($aiResponse);

        $startTime = microtime(true);

        // Persistance de l'interaction (même si depuis le cache : on trace toujours l'usage)
        $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

        $interaction = new Interaction();
        $interaction->setProject($project);
        $interaction->setType('literature_review');
        $interaction->setUserPrompt($query);
        $interaction->setAiResponse($enrichedReview); // On persiste la version enrichie avec badges
        $interaction->setResponseTimeMs($responseTimeMs);

        $this->entityManager->persist($interaction);
        $this->entityManager->flush();

        return [
            'response'            => $aiResponse, // réponse originale DeepSeek (non modifiée)
            'literature_review'   => $enrichedReview, // version enrichie avec badges et liens
            'web_sources'         => $webSources,
            'interaction'         => $interaction,
            'from_cache'          => $fromCache,
        ];
    }
}
