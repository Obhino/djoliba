<?php

namespace App\Service\Editor;

use App\Service\IA\DeepSeekService;
use App\Service\SuggestionService;

class ReferenceSuggester
{
    public function __construct(
        private DeepSeekService $deepSeekService,
        private SuggestionService $suggestionService
    ) {
    }

    public function suggestReferences(string $text): array
    {
        $prompt = sprintf(
            "Extraire uniquement 3 à 5 mots-clés ou termes de recherche scientifiques les plus pertinents du texte suivant (sans ponctuation, séparés uniquement par des espaces) pour effectuer une recherche bibliographique :\n" .
            "\"%s\"\n\n" .
            "Réponds UNIQUEMENT avec les mots-clés, aucun autre mot.",
            $text
        );

        try {
            $keywords = $this->deepSeekService->call($prompt, [
                'temperature' => 0.1,
                'max_tokens' => 50
            ]);
            $keywords = trim($keywords);
        } catch (\Exception $e) {
            $keywords = $text;
        }

        if (empty($keywords)) {
            $keywords = $text;
        }

        return $this->suggestionService->suggest($keywords, 5);
    }
}
