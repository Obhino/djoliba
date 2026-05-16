<?php

namespace App\Service;

use App\Service\IA\DeepSeekService;

class SuggestionService
{
    public function __construct(
        private DeepSeekService $deepSeekService,
    ) {
    }

    /**
     * Suggère des articles scientifiques complémentaires à une requête.
     * Utilise le prompt défini dans PROJECT_CONTEXT.md section 6.
     *
     * @param string $query La requête ou le sujet de recherche.
     * @param int    $limit Nombre d'articles à suggérer (défaut: 5).
     * @return array<int, array{title: string, authors: string, year: int, abstract: string, doi: string}>
     * @throws \RuntimeException Si l'API DeepSeek est indisponible après toutes les tentatives.
     * @throws \UnexpectedValueException Si la réponse JSON de l'IA est malformée.
     */
    public function suggest(string $query, int $limit = 5): array
    {
        // Prompt défini en section 6 du PROJECT_CONTEXT.md
        $prompt = sprintf(
            "Suggère %d articles scientifiques complémentaires à: %s. Réponse JSON: [{title, authors, year, abstract, doi}]. Réponds UNIQUEMENT avec le tableau JSON, sans texte ni balise markdown autour.",
            $limit,
            $query
        );

        $rawResponse = $this->deepSeekService->call($prompt, [
            'temperature' => 0.3, // Température basse pour une réponse JSON structurée et reproductible
        ]);

        return $this->parseArticles($rawResponse, $limit);
    }

    /**
     * Parse et valide le JSON retourné par DeepSeek.
     * Nettoie les éventuels blocs markdown (```json ... ```) que l'IA peut ajouter.
     *
     * @throws \UnexpectedValueException Si le JSON est invalide ou la structure incorrecte.
     */
    private function parseArticles(string $raw, int $expectedCount): array
    {
        // Nettoyage des balises markdown que certains modèles ajoutent malgré l'instruction
        $cleaned = trim($raw);
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
        $cleaned = preg_replace('/\s*```$/', '', $cleaned);
        $cleaned = trim($cleaned);

        $articles = json_decode($cleaned, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \UnexpectedValueException(
                sprintf('Réponse JSON invalide de DeepSeek : %s. Réponse brute : %s', json_last_error_msg(), substr($raw, 0, 200))
            );
        }

        if (!is_array($articles)) {
            throw new \UnexpectedValueException('La réponse DeepSeek n\'est pas un tableau JSON.');
        }

        // Validation et normalisation de chaque article
        $validated = [];
        foreach ($articles as $index => $article) {
            if (!is_array($article)) {
                continue;
            }

            $validated[] = [
                'title'    => (string) ($article['title']    ?? 'Titre inconnu'),
                'authors'  => (string) ($article['authors']  ?? 'Auteurs inconnus'),
                'year'     => (int)    ($article['year']      ?? 0),
                'abstract' => (string) ($article['abstract'] ?? ''),
                'doi'      => (string) ($article['doi']      ?? ''),
            ];

            // On s'arrête au nombre demandé
            if (count($validated) >= $expectedCount) {
                break;
            }
        }

        return $validated;
    }
}
