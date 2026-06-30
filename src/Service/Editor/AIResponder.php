<?php

namespace App\Service\Editor;

use App\Service\IA\DeepSeekService;

class AIResponder
{
    public function __construct(private DeepSeekService $deepSeekService)
    {
    }

    private function getPrompt(string $text, string $question): string
    {
        return sprintf(
            "En contexte de rédaction de travaux de recherche, réponds à la question suivante : \"%s\".\n" .
            "Utilise le paragraphe suivant comme contexte immédiat de rédaction pour calibrer ta réponse :\n" .
            "\"%s\"",
            $question,
            $text
        );
    }

    public function ask(string $text, string $question): string
    {
        return $this->deepSeekService->call($this->getPrompt($text, $question));
    }

    public function streamAsk(string $text, string $question, callable $callback): void
    {
        $this->deepSeekService->stream($this->getPrompt($text, $question), $callback);
    }
}
