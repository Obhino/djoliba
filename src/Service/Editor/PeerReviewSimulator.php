<?php

namespace App\Service\Editor;

use App\Service\IA\DeepSeekService;

class PeerReviewSimulator
{
    public function __construct(private DeepSeekService $deepSeekService)
    {
    }

    private function getPrompt(string $text): string
    {
        return sprintf(
            "Simule un rapport de relecture (Peer Review) constructif et exigeant pour le paragraphe ci-dessous.\n" .
            "Fournis :\n" .
            "1. Une critique méthodologique (limites éventuelles).\n" .
            "2. Une évaluation des affirmations (sont-elles étayées par le texte ou spéculatives ?).\n" .
            "3. Une perspective sur l'intégration des réalités et données locales africaines si applicable au sujet.\n" .
            "4. Des recommandations précises de réécriture.\n\n" .
            "Texte : \"%s\"",
            $text
        );
    }

    public function simulate(string $text): string
    {
        return $this->deepSeekService->call($this->getPrompt($text));
    }

    public function streamSimulate(string $text, callable $callback): void
    {
        $this->deepSeekService->stream($this->getPrompt($text), $callback);
    }
}
