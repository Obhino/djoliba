<?php

namespace App\Service\Editor;

use App\Service\IA\DeepSeekService;

class RedundancyDetector
{
    public function __construct(private DeepSeekService $deepSeekService)
    {
    }

    public function detect(string $text): string
    {
        $prompt = sprintf(
            "Analyse le paragraphe suivant et identifie :\n" .
            "1. Les répétitions lexicales excessives.\n" .
            "2. Les redondances d'idées ou les pléonasmes académiques.\n\n" .
            "Propose une version épurée du paragraphe, plus dynamique et concise, en suggérant des synonymes appropriés au contexte scientifique.\n" .
            "Texte : \"%s\"",
            $text
        );

        return $this->deepSeekService->call($prompt);
    }
}
