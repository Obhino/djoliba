<?php

namespace App\Service\Editor;

use App\Service\IA\DeepSeekService;

class CodeGenerator
{
    public function __construct(private DeepSeekService $deepSeekService)
    {
    }

    private function getPrompt(string $text, string $language): string
    {
        return sprintf(
            "Génère un script structuré en langage %s basé sur la description et les paramètres suivants.\n" .
            "Le script doit respecter les standards de qualité (bonnes pratiques, commentaires, gestion des erreurs).\n" .
            "Description : \"%s\"",
            $language,
            $text
        );
    }

    public function generate(string $text, string $language): string
    {
        return $this->deepSeekService->call($this->getPrompt($text, $language));
    }

    public function streamGenerate(string $text, string $language, callable $callback): void
    {
        $this->deepSeekService->stream($this->getPrompt($text, $language), $callback);
    }
}
