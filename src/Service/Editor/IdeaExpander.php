<?php

namespace App\Service\Editor;

use App\Service\IA\DeepSeekService;

class IdeaExpander
{
    public function __construct(private DeepSeekService $deepSeekService)
    {
    }

    private function getPrompt(string $text): string
    {
        return sprintf(
            "L'idée scientifique suivante est encore embryonnaire ou incomplète.\n" .
            "Propose 3 à 5 pistes concrètes pour l'approfondir. Structure ta réponse avec :\n" .
            "- Une clarification des concepts clés.\n" .
            "- Des pistes d'exploration empirique ou méthodologique.\n" .
            "- Des angles théoriques complémentaires.\n\n" .
            "Idée à développer : \"%s\"",
            $text
        );
    }

    public function expand(string $text): string
    {
        return $this->deepSeekService->call($this->getPrompt($text));
    }

    public function streamExpand(string $text, callable $callback): void
    {
        $this->deepSeekService->stream($this->getPrompt($text), $callback);
    }
}
