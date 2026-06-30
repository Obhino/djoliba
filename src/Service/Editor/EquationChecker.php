<?php

namespace App\Service\Editor;

use App\Service\IA\DeepSeekService;

class EquationChecker
{
    public function __construct(private DeepSeekService $deepSeekService)
    {
    }

    public function check(string $text): string
    {
        $prompt = sprintf(
            "Tu es un expert en notation LaTeX scientifique. Analyse l'équation LaTeX suivante :\n" .
            "\"%s\"\n\n" .
            "Détecte s'il y a des erreurs de syntaxe, des parenthèses ou accolades non fermées, ou des anomalies typographiques.\n" .
            "Retourne l'équation corrigée au format LaTeX strict ainsi qu'une explication brève de l'erreur identifiée s'il y en a une.",
            $text
        );

        return $this->deepSeekService->call($prompt);
    }
}
