<?php

namespace App\Service\Editor;

use App\Service\IA\DeepSeekService;

class Reformulator
{
    public function __construct(private DeepSeekService $deepSeekService)
    {
    }

    private function getPrompt(string $text): string
    {
        return sprintf(
            "Propose 3 variations de reformulation académique pour le texte suivant.\n" .
            "Assure-toi de :\n" .
            "- Conserver strictement le sens scientifique initial.\n" .
            "- Améliorer l'élégance du style, la clarté et la concision.\n" .
            "- Fournir des propositions allant de la plus formelle à la plus directe.\n\n" .
            "Texte à reformuler : \"%s\"",
            $text
        );
    }

    public function reformulate(string $text): string
    {
        return $this->deepSeekService->call($this->getPrompt($text));
    }

    public function streamReformulate(string $text, callable $callback): void
    {
        $this->deepSeekService->stream($this->getPrompt($text), $callback);
    }
}
