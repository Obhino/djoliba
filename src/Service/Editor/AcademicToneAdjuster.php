<?php

namespace App\Service\Editor;

use App\Service\IA\DeepSeekService;

class AcademicToneAdjuster
{
    public function __construct(private DeepSeekService $deepSeekService)
    {
    }

    private function getPrompt(string $text, string $register): string
    {
        return sprintf(
            "Reformule le paragraphe ci-dessous pour adopter le registre de rédaction suivant : \"%s\".\n" .
            "Choix de registres disponibles :\n" .
            "- \"Voix Active Directe\" : Rendre le ton plus percutant, éviter la sur-utilisation du passif.\n" .
            "- \"Formel & Distancié\" : Utiliser le \"nous\" de modestie ou des structures impersonnelles adaptées aux revues majeures.\n" .
            "- \"Vulgarisation & Impact\" : Conserver la précision mais simplifier les structures de phrases pour un public non spécialisé.\n\n" .
            "Texte : \"%s\"",
            $register,
            $text
        );
    }

    public function adjust(string $text, string $register): string
    {
        return $this->deepSeekService->call($this->getPrompt($text, $register));
    }

    public function streamAdjust(string $text, string $register, callable $callback): void
    {
        $this->deepSeekService->stream($this->getPrompt($text, $register), $callback);
    }
}
