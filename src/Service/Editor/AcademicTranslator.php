<?php

namespace App\Service\Editor;

use App\Service\IA\DeepSeekService;

class AcademicTranslator
{
    public function __construct(private DeepSeekService $deepSeekService)
    {
    }

    private function getPrompt(string $text, string $targetLanguage): string
    {
        return sprintf(
            "Traduis le texte scientifique suivant en %s.\n" .
            "Consignes impératives :\n" .
            "- Conserve le ton académique formel et rigoureux.\n" .
            "- Utilise la terminologie exacte de la discipline.\n" .
            "- Préserve intacts les blocs de code et les expressions mathématiques LaTeX (ex: $...$, $$...$$).\n\n" .
            "Texte : \"%s\"",
            $targetLanguage,
            $text
        );
    }

    public function translate(string $text, string $targetLanguage): string
    {
        return $this->deepSeekService->call($this->getPrompt($text, $targetLanguage));
    }

    public function streamTranslate(string $text, string $targetLanguage, callable $callback): void
    {
        $this->deepSeekService->stream($this->getPrompt($text, $targetLanguage), $callback);
    }
}
