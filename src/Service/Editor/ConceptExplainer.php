<?php

namespace App\Service\Editor;

use App\Service\IA\DeepSeekService;

class ConceptExplainer
{
    public function __construct(private DeepSeekService $deepSeekService)
    {
    }

    public function explain(string $text): string
    {
        $prompt = sprintf(
            "Explique de manière concise et rigoureuse le concept ou terme scientifique suivant : \"%s\".\n" .
            "Fournis :\n" .
            "- Une définition consensuelle dans la discipline concernée.\n" .
            "- Son importance ou rôle dans la recherche contemporaine.\n" .
            "- Sa traduction ou équivalent dans l'autre langue (anglais/français).",
            $text
        );

        return $this->deepSeekService->call($prompt);
    }
}
