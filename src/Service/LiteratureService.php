<?php

namespace App\Service;

use App\Entity\Interaction;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\IA\DeepSeekService;

class LiteratureService
{
    public function __construct(
        private DeepSeekService $deepSeekService,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Effectue une revue de littérature scientifique sur une requête donnée.
     * Utilise le prompt défini dans PROJECT_CONTEXT.md section 6.
     *
     * @param string  $query   Le sujet ou la question de recherche.
     * @param Project $project Le projet auquel rattacher cette interaction.
     * @return array{response: string, interaction: Interaction}
     */
    public function review(string $query, Project $project): array
    {
        // Prompt défini en section 6 du PROJECT_CONTEXT.md
        $prompt = sprintf(
            "Effectue une revue de littérature sur: %s. Inclus: fondement théorique, tendances récentes, lacunes, articles incontournables.",
            $query
        );

        $startTime = microtime(true);

        $aiResponse = $this->deepSeekService->call($prompt);

        $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

        // Persistance de l'interaction pour la traçabilité et la facturation (phase 2)
        $interaction = new Interaction();
        $interaction->setProject($project);
        $interaction->setType('literature_review');
        $interaction->setUserPrompt($query);
        $interaction->setAiResponse($aiResponse);
        $interaction->setResponseTimeMs($responseTimeMs);

        $this->entityManager->persist($interaction);
        $this->entityManager->flush();

        return [
            'response'    => $aiResponse,
            'interaction' => $interaction,
        ];
    }
}
