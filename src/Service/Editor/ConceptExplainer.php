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
        return "Concept Explainer draft";
    }
}
